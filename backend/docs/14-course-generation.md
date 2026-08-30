# AI course generation

Draft a course with AI — from a PDF textbook or a topic brief — structured to a
chosen schema. It lands as a **draft** for an author to review and publish;
nothing is auto-published.

## Flow

1. **Studio → Generate** (`GenerationController`, gated on `course.create`).
   The author gives a name, picks a **published** schema, and either uploads a
   **PDF** or writes a **brief** (topic/syllabus). No PDF is needed — the brief
   mode generates from the model's own knowledge (e.g. a NEET syllabus).
2. A `CourseGeneration` row is created (`pending`) and `GenerateCourseJob` is
   queued. The studio page polls until it finishes.
3. Generation runs in **two phases, split across many short queue jobs** so a big
   course is never one long, timeout-prone job:
   - **Outline** (`GenerateCourseJob` → `BlueprintGenerator::outline`) — one small
     request for the course *structure* (levels + titles only, no teaching text),
     described against the **schema hierarchy** (level names, nesting, occurrence
     limits). For a PDF it sends the document natively (Claude reads PDFs, text
     and figures); for a brief it sends the text. `CourseBuilder` builds the draft
     course tree from it (via `CreateCourse`, `CourseTree`) — nodes, no content yet.
   - **Content** (`GenerateContentJob`, chained) — one short job per content-bearing
     node calls `BlueprintGenerator::contentFor` for just that topic's teaching
     text (plain text, not JSON) and writes it with `ContentWriter`, then dispatches
     itself for the next node. "Next node" is *the next content-bearing node with no
     rich-text block yet*, so the chain is idempotent and resumable. A PDF source is
     sent **once per call and prompt-cached**, so the many content calls reuse it
     cheaply. A content call that fails writes a short placeholder and the chain
     continues, rather than sinking the whole course.
4. When no unfilled node remains, the last content job marks the generation
   `completed` (and deletes the PDF). On failure it's `failed` with the error and
   can be **retried** from the studio — a retry **resumes**: it reuses the built
   course and fills only the topics still missing content (a failed PDF run keeps
   its upload). Token usage across outline + all content calls is summed per run.

## Prompt settings (Studio → Generate → Settings)

The base prompts are fixed (they carry the machine contract — schema, JSON shape,
plain-text rules). Admins add **house-style guidance** (`GenerationSettings`,
stored in `app_settings`) that is appended to the outline and content prompts —
depth, tone, and especially how to handle figures. Gated on `course.create`.

## Visuals — images and diagrams

Generated lessons carry visuals from three sources:

- **Platform images.** The AI-platform animated pipeline emits `image` blocks.
  `StepMapper` turns each into an image spec and `LessonExpander` ingests it via
  `ImageIngestor` (hosted URL or inline data: URI → a ready `Media` record →
  `image` block; identical images are de-duplicated by checksum, non-raster or
  oversized sources are dropped). Previously these were discarded.
- **Generated SVG diagrams.** The content prompt invites the model to include a
  self-contained inline SVG in a fenced ```svg block when a figure genuinely
  helps (geometry, graphs, flowcharts). `ContentWriter` pulls each one out of the
  prose into a **`diagram`** block (a new block type: `{format:"svg", svg, alt,
  caption?}`), when the level's `allowed_block_types` includes `diagram`. Scripts
  / foreignObject / on* handlers are stripped on write; rendering is script-inert
  anyway (studio `<img>` data-URI, Flutter `SvgPicture.string`).
- **Simulations / animations.** The platform's interactive `simulation` and
  `animation` blocks flow through `StepMapper` unchanged and render in both
  surfaces.

For any of these to attach, the target content level's `allowed_block_types`
must list the block type (`image`, `diagram`, `simulation`, `animation`). Set it
in the schema builder **before publishing** — published levels are immutable.
Whatever isn't drawn, the model still describes in words.

## Design notes

- **`CourseBuilder` is deterministic and AI-free** — it takes a parsed blueprint
  and builds the course, so it's fully unit-tested without the model. It's
  **resilient per node**: a spec naming an unknown level or exceeding a level's
  occurrence limit is skipped (each node is its own committed write) rather than
  failing the whole course. Zero nodes built = a surfaced failure.
- **Human-in-the-loop**: output is always a draft. The author fixes anything in
  the studio and publishes.
- **Cost**: generation calls a paid model; a whole textbook is a lot of tokens.
  Usage is recorded; add a per-run/token cap before opening this widely.

## Not yet built

- **Interactive study plans**: NEET-style schedules (full-year / 1–2 month) are
  generated as *content* today (a "Study Plan" section — ask for it in the
  brief). A structured plan entity (dated tasks linked to topics/quizzes,
  progress against the plan, reminders) is a separate, larger feature.
- **Model fine-tuning is not used** and generally isn't needed: tailored content
  comes from a strong base model + a good brief/style prompt + author curation.

## Robustness

The model is asked for JSON only, but real replies vary — the parser tolerates a
stray sentence or code fence (it takes the text from the first `{` to the last
`}`) and escapes raw newlines/tabs that land inside `content` strings (invalid
JSON that models emit constantly). If the reply is cut off at the output token
ceiling (`stop_reason: max_tokens`), the run fails with a clear "outline was too
long" message instead of a generic parse error. An unparseable reply is logged
(`Log::warning`, head/tail) so it can be diagnosed.

Transient API failures — a connection blip, a rate limit (429), or an overloaded
/ 5xx response — are retried with growing backoff inside the client, so one bad
moment during a long run doesn't cost a topic. Only if a topic still fails after
retries does it get a placeholder block ("add it manually") and the chain moves
on. The studio's "done/total topics" counter includes placeholders, so a
completed run may still have a few sections to fill by hand.

## Requires

`ANTHROPIC_API_KEY` (chat/generation). A queue worker for `GenerateCourseJob`.
PDFs are stored on the default disk during the run and deleted afterwards (a
failed run keeps its PDF so it can be retried).

`GENERATION_MAX_TOKENS` (default 16000) caps the *outline* pass. Because content
is generated one topic per job, no single job is long: `GENERATION_TIMEOUT`
(default 1800s) covers the orchestrator/outline, and `GENERATION_CONTENT_TIMEOUT`
(default 180s) covers each per-topic content job. Both must stay **below** the
queue connection's `retry_after` (default 2100s, see config/queue.php) and the
worker's `--timeout`, or the queue re-dispatches a job while it is still running.
On DigitalOcean set `ANTHROPIC_FORCE_IPV4=true` (the default) to avoid IPv6
connect timeouts. The content chain is sequential; a very large course simply
takes many short jobs (and the studio shows it working until the last completes).
