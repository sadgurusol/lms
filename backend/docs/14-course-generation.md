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
3. The job runs `BlueprintGenerator` in **two phases** so it scales to whole
   textbooks instead of truncating in one giant reply:
   - **Outline** — one small request for the course *structure* (levels + titles
     only, no teaching text), described against the **schema hierarchy** (level
     names, nesting, occurrence limits). For a PDF it sends the document natively
     (Claude reads PDFs, text and figures); for a brief it sends the text.
   - **Content** — a separate request per content-bearing node writes just that
     topic's teaching text (plain text, not JSON). Each stays well under the
     token ceiling. A PDF source is sent **once and prompt-cached**, so the many
     content calls reuse it cheaply. A content call that fails leaves that node
     without content rather than sinking the whole course.
   - `CourseBuilder` then turns the filled blueprint into a draft course using the
     same authoring services a human uses — `CreateCourse`, `CourseTree`,
     `BlockEditor` — mapping level names to schema levels and converting the
     content text to Portable Text.
4. On success the generation is `completed` with a link to the draft; on failure
   it's `failed` with the error, and can be **retried** from the studio (a failed
   PDF run keeps its upload so no re-upload is needed). Token usage across all
   phases is summed and recorded per run.

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

## Requires

`ANTHROPIC_API_KEY` (chat/generation). A queue worker for `GenerateCourseJob`.
PDFs are stored on the default disk during the run and deleted afterwards (a
failed run keeps its PDF so it can be retried).

`GENERATION_MAX_TOKENS` (default 16000) caps the *outline* pass. `GENERATION_TIMEOUT`
(default 1800s) is the wall-clock budget for a whole run — one API call per topic
adds up, so big courses need it. **Three timers must stay ordered**: the queue
connection's `retry_after` (default 2100s) **must exceed** `GENERATION_TIMEOUT`,
which **must not exceed** the worker's `--timeout`. If `retry_after` is smaller,
the queue re-dispatches the job while it is still running. On DigitalOcean set
`ANTHROPIC_FORCE_IPV4=true` (the default) to avoid IPv6 connect timeouts.

Very large textbooks still want **chapter-by-chapter** runs — even two-phase, a
single job that makes hundreds of calls will bump the timeout. A batched design
(one short job per topic) is the next step if that ceiling is reached.
