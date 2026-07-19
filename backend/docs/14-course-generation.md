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
3. The job:
   - `BlueprintGenerator` builds a prompt describing the **schema hierarchy**
     (level names, nesting, occurrence limits, which levels carry content) and
     asks Claude for a JSON outline. For a PDF it sends the document natively
     (Claude reads PDFs, text and figures); for a brief it sends the text.
   - `CourseBuilder` turns that blueprint into a draft course using the same
     authoring services a human uses — `CreateCourse`, `CourseTree`,
     `BlockEditor` — mapping level names to schema levels and converting the
     content text to Portable Text.
4. On success the generation is `completed` with a link to the draft; on failure
   it's `failed` with the error. Token usage is recorded per run.

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

- **Large-textbook map-reduce**: v1 sends the PDF in one request (Claude's
  per-request page/size limits apply). Real textbooks want chapter-by-chapter
  generation — an outline pass then per-chapter content passes.
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
PDFs are stored on the default disk during the run and deleted afterwards.
`GENERATION_MAX_TOKENS` (default 16000) caps generation output — raise it for
bigger courses, but a whole textbook still wants chapter-by-chapter passes.
