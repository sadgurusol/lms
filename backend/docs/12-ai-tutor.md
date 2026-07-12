# AI Tutor

A learner can chat with an AI tutor about the subject content of a course they
are entitled to. The tutor is **grounded** in the course's published snapshot —
it teaches from the material, cites where its answers come from, and is Socratic
by design (it guides rather than hands over answers). It never has access to
assessment questions or answer keys.

## Principles

- **Grounded, not open-ended.** Answers are built from the course's own content.
  Retrieval keeps the model on-material and lets it cite the exact node.
- **Entitlement is the boundary.** Every tutor call runs through
  `EntitlementResolver`, exactly like course content and assessments. A learner
  can only tutor on a course they can already read, and usage attributes to the
  session context (B2B client vs B2C) the same way activity does.
- **Never an answer key.** The grounding corpus is *content blocks only*. It
  never contains assessment questions or their keys, so the tutor cannot leak
  them — reinforced by a system prompt that refuses to do a learner's graded
  work for them.
- **Reuse, don't rebuild.** No Python service, no separate vector database. Chat
  and embeddings are hosted HTTP APIs called from Laravel; vectors live in
  Postgres via `pgvector`. One language, one datastore, one auth story.
- **Private by default.** Learner transcripts are the learner's. They are not
  exposed to the studio/content-provider, mirroring the privacy line already
  drawn for cohort insights.

## Architecture

```
Flutter chat  ──HTTPS(bearer)──>  Laravel Tutor API
                                    │
                                    ├─ EntitlementResolver (gate + attribution)
                                    ├─ CourseContext / Retrieval (grounding)
                                    │      └─ pgvector similarity search (phase 2)
                                    ├─ AnthropicClient ──> Anthropic Messages API (Claude)
                                    └─ tutor_conversations / tutor_messages (Postgres)

Publish ──> embed content nodes ──> content_embeddings (pgvector)   (phase 2)
```

- **Chat model:** Claude Sonnet (quality/latency/cost balance), with Opus as an
  escalation lever. Called via Laravel's `Http` client so it fakes cleanly in
  tests.
- **Embeddings (phase 2):** Voyage AI (Anthropic's recommendation) or an
  equivalent hosted embeddings API — another HTTP call, stored as `vector(N)`.
- **Vector store:** `pgvector` extension on the existing PostgreSQL 16, indexed
  with HNSW. No separate service.

### Why no Python microservice

Everything the tutor needs is a hosted API: chat (Anthropic), embeddings
(Voyage), search (pgvector = SQL). A Python tier would add a second language, a
second deployment, and an internal auth boundary for no benefit while models are
hosted. It would only earn its place if we ran embedding models locally.

## Grounding

Content is already a tree of nodes with Portable Text blocks, frozen in an
immutable publication snapshot — an ideal corpus.

**Phase 1 — direct context injection (this milestone).** A conversation is
scoped to one course. Each message may carry the `node_id` the learner is
currently reading. The system prompt is built from:
- the course outline (every node's label — cheap and gives the model structure),
- the full flattened text of the focused node and its parent chapter.

This is bounded and immediately useful for "explain this", "give me another
example", "what should I read next". No embeddings required.

**Phase 2 — retrieval over the whole course.** At publish time, each
content-bearing node is flattened and embedded, stored in `content_embeddings`
keyed by `publication_id + course_node_id` (so embeddings are immutable-aligned
and simply rebuilt on republish). At question time we embed the learner's
message, cosine-search within that publication, and inject the top-k node chunks
with citations. This scales past what fits in a single prompt and answers
questions that span the course.

## Data model

- `tutor_conversations` — `id`, `user_id`, `course_id`, `publication_id`,
  `client_id` (nullable, for attribution), `title`, timestamps.
- `tutor_messages` — `id`, `conversation_id`, `role` (`user` | `assistant`),
  `content`, `citations` (jsonb: node ids/labels), `input_tokens`,
  `output_tokens`, `created_at`.
- `content_embeddings` (phase 2) — `id`, `publication_id`, `course_node_id`,
  `chunk_index`, `text`, `embedding vector(N)`, with an HNSW index.

## API

All under `auth:sanctum`, throttled (`throttle:tutor`), entitlement-gated:

- `POST /api/v1/me/courses/{course}/tutor/conversations` — start a conversation.
- `GET  /api/v1/me/tutor/conversations/{conversation}` — history.
- `POST /api/v1/me/tutor/conversations/{conversation}/messages` — ask; returns
  the assistant reply (with citations). Non-streaming in v1; SSE streaming in a
  later phase.
- `GET  /api/v1/me/courses/{course}/tutor/conversations` — the learner's
  conversations on a course.

## Guardrails & cost

- Entitlement re-checked on every call; attribution follows session context.
- Corpus excludes all assessment content — the tutor cannot surface answer keys.
- System prompt: Socratic, on-material, refuses graded-work-by-proxy.
- `throttle:tutor` per user, plus a per-message token ceiling; conversation
  history is trimmed to a budget before each call.
- Optional per-product toggle so a B2B client can disable the tutor (future).

## Phases

1. **Done:** data model, non-streaming chat grounded by direct context
   injection, entitlement gate, Socratic prompt, answer-key exclusion, tests.
2. **Done:** Flutter chat screen wired to the course.
3. **Done — with one change:** embeddings + retrieval with citations. We store
   embeddings in a plain jsonb column and cosine-rank in the app rather than
   using `pgvector`: retrieval is always scoped to one course's nodes (small N),
   so an index buys nothing, and this stays portable to any Postgres. The schema
   can migrate to a `vector` column later with no change to the retrieval logic.
   - `App\Ai\EmbeddingsClient` — Voyage AI wrapper (`services.voyage.*`).
   - `App\Tutor\ContentEmbedder` — flattens content nodes and embeds them at
     publish (best-effort, guarded by config) and via `php artisan tutor:embed`.
   - `App\Tutor\Retrieval` — embeds the question, cosine-ranks the publication's
     node embeddings, returns the top-k with citations. Degrades to nothing when
     embeddings are absent, so the tutor still works on outline + focus.
   - `content_embeddings` table, keyed by publication (immutable-aligned).
4. **Done — SSE streaming.** `POST …/tutor/conversations/{id}/stream` returns
   `text/event-stream`: `data:` frames carry `{delta}` tokens, a final
   `event: done` frame carries the persisted message id and citations, and
   `event: error` reports a mid-stream failure. `AnthropicClient::stream()`
   consumes Anthropic's own SSE (`stream: true`) and forwards text deltas;
   `TutorChat::streamReply()` shares grounding/persistence with the non-streaming
   path (kept as a fallback). The Flutter tutor screen fills the reply bubble in
   live.
5. **Done — cost controls and the B2B toggle.**
   - A B2B client can turn the tutor off for its learners: `clients.settings.
     ai_tutor_enabled` (default on), toggled from the ops client page. The tutor
     endpoints refuse (403) when the session's client context has it off; B2C is
     always on.
   - A per-learner monthly token budget (`config/tutor.php`,
     `TUTOR_MONTHLY_TOKEN_BUDGET`, null = unlimited) is enforced before each
     turn (429 when spent) via `App\Tutor\TutorBudget`, which sums the tokens
     already recorded on messages. `GET /me/tutor/usage` returns
     `{enabled, used, budget, remaining}`.

When scale ever demands it, swap the jsonb embedding column for a `pgvector`
column + HNSW index — `Retrieval` is the only thing that changes.

## Configuration

- `ANTHROPIC_API_KEY` (+ optional `ANTHROPIC_MODEL`) — required for chat.
- `VOYAGE_API_KEY` (+ optional `VOYAGE_MODEL`) — enables retrieval. Without it,
  publishing skips embedding and the tutor grounds on outline + focus only.
- Backfill existing courses: `php artisan tutor:embed` (all) or `tutor:embed {course}`.
