# LMS Backend

Laravel 13 · PHP 8.3+ · PostgreSQL 16 · Redis

Design docs live in [`../docs`](../docs). Read `00-overview.md` first; the
course-schema system in `01-domain-model.md` is the part worth understanding
before touching anything.

## Quickstart

```bash
docker compose up -d              # from the repo root: Postgres :55432, Redis :63790
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Local seed users (password `password`): `admin@`, `ops@`, `author@`,
`reviewer@` `example.com`.

## Tests

Tests run against **real Postgres**, not SQLite. The system's invariants are
`ltree` paths, `jsonb` constraints and row triggers, and SQLite can express none
of them — a green SQLite suite would mean nothing.

```bash
docker exec lms_postgres createdb -U lms lms_test   # once
./vendor/bin/pest
./vendor/bin/pest --filter=CourseTree
```

The tree property test replays a random sequence of create/move/reorder/delete
against the invariants. Seed it to reproduce a failure:

```bash
TREE_SEED=42 ./vendor/bin/pest --filter="holds every structural invariant"
```

## Gate

```bash
./vendor/bin/pint          # format
./vendor/bin/phpstan analyse
./vendor/bin/pest
```

## What is built

| Milestone | Status |
|---|---|
| M0 — identity, `user_identities`, six roles, 48 permissions, partitioned audit log | ✅ |
| M1 — course schemas, versions, levels; published versions immutable | ✅ |
| M2 — courses, node tree, content blocks; structural triggers; fractional ordering | ✅ |
| M3 — media lifecycle, presigned uploads, transcode webhook, six block types with JSON Schema payloads | ✅ |
| M4 — course grants, review workflow, publish validator, immutable snapshots, rollback | ✅ |
| M5 — question banks, nine question types, attempts, auto- and manual grading | ✅ |
| M6 — products, comp grants, `EntitlementResolver`, `/me/courses` | ✅ |
| M7 — delivery & offline | — |

Two tests drive whole milestones end to end:

- `AuthoringLifecycleTest` (M3+M4) — upload a video, build a tree, get blocked on
  a transcoding asset, submit, review, request changes, resolve, approve,
  publish, publish again, roll back.
- `TimedTestLifecycleTest` (M5) — a learner takes a timed test, loses
  connectivity at question 12, reconnects, flushes the offline queue twice, and
  submits without losing an answer.

## Where the rules live

Structural invariants are **database triggers**, not FormRequests. A
`FormRequest` is bypassed by every seeder, queue job, artisan command and 2 a.m.
tinker session; a tree that corrupts silently is unrecoverable six months later.

| Invariant | Enforced by |
|---|---|
| I1 a course cannot be rebound to another schema version once it has nodes | `courses_pin_schema_version` |
| I2/I3/I4 a node's level must be a declared child of its parent's level, in the course's own schema version | `course_nodes_enforce_structure` |
| I5 a block's type must be permitted by its node's level | `content_blocks_enforce_level` |
| I7 sibling sort keys are unique | partial unique indexes |
| I8 `path` and `depth` are derived, never supplied | `course_nodes_enforce_structure` |
| I6 an assessment may only attach to a level that permits one | `assessments_enforce_level` |
| I9 a published schema version is immutable | `forbid_published_schema_level_mutation` + `..._version_mutation` |
| I10 a publication is immutable | `forbid_publication_mutation` |
| I13 an answer references a question in the attempt's own assessment | `attempt_answers_enforce_assessment` |

I12 (cardinality: "a Part must contain ≥ 1 Chapter") is deliberately **not** a
trigger. Enforcing it on every save makes it impossible to create an empty Part
and then fill it. It is checked by `CourseValidator` at the publish gate, which
also backs the editor's live readiness panel — one implementation, two callers.

Block **payload** shape is a JSON Schema, checked in `ContentBlock::saving`.
That is application-level and a raw query-builder insert goes around it, which is
exactly why the rule the system cannot afford to lose — *which block types a
level permits* — is a trigger instead. `CourseValidator` catches the rest before
a learner ever sees it.

Two authorization rules worth knowing:

- **A user may not review a course they author.** `CoursePolicy::review()`, and
  `Gate::before` explicitly does *not* let admins bypass it — otherwise an admin
  could approve their own work and the control is theatre.
- **`CourseGrant::revoke()` exists** because `CourseGrant::where(...)->delete()`
  fires no model events, so the grant cache never busts and a revoked author
  keeps editing for the full TTL.

Grading has two rules that look like bugs and are not:

- **A wrong short answer is not auto-graded wrong.** `ShortAnswerGrader` returns
  `needsHuman()` on a non-match, and the attempt goes to `awaiting_review`. A
  near-miss spelling is a teacher's call, and a machine that scores it zero at
  2 a.m. gets overruled at 9 a.m. anyway. Only a *blank* answer is auto-wrong.
- **`is_correct === null` means "ask a human", never "incorrect".** Everything
  downstream — the attempt state machine, `GradeAnswer`, `finalise()` — keys off
  that distinction.

`QuestionViewerResource` is deliberately not a Laravel `JsonResource`: those
serialise the model by default, so a new column leaks unless someone remembers
to exclude it. It builds the payload from nothing. `AnswerKeyLeakTest` asserts
no `is_correct`, `grading`, `explanation`, `feedback` or `match_key` key appears
at *any* depth of a learner-facing payload.

## Entitlements

**One question, one answer:** *may this user, in this session context, read this
course right now?* `EntitlementResolver` is the only place it is answered.
Answering it in two branches — one for B2B, one for B2C — guarantees they drift,
and the drift is a paid-content leak. There is no Eloquent scope shortcut for
"just the list endpoint"; `/me/courses` calls the resolver too.

**Sources are ordered, and the order is load-bearing.** Client entitlements are
checked before subscriptions, so a student launched from ABC School reads under
ABC's contract even if they also hold a personal subscription. Attribution
follows the session context, not the cheapest grant. Get it backwards and ABC's
activity report silently omits that student. Subscriptions (M8) and client
contracts (M9) slot into the ordered list in `AppServiceProvider`; nothing else
changes.

**Never 404 a course the caller isn't entitled to.** It makes "does this exist?"
indistinguishable from "may I read it?" and support cannot triage it. `403` with
a `reason` and a `cta`: a B2C learner gets a paywall, a B2B learner gets "ask
your school". A paywall for content the school was supposed to buy is a bad day
for everyone.

The entitlement cache has two invalidation scopes. Per-user (a comp grant, a
subscription webhook) forgets one key. **Global** (a course joins a bundle,
granting it to every holder at once) bumps a version counter in the cache key —
finding the affected users would be unbounded. A grant's expiry rides along in
the cached value and is re-checked on read, so a grant that lapses inside the
five-minute TTL lapses on time.

## Two things that will bite you

**`sort_key` is `COLLATE "C"`.** Sort keys are base-62 fractions compared
byte-wise. Under the default locale-aware collation `'a'` sorts before `'B'` and
the whole ordering scheme collapses silently.

**Never compare sort keys with `<` / `>=` in PHP.** PHP compares two numeric
strings *numerically*: `'0002' >= '001'` is `2 >= 1` → true, while byte-wise —
and in Postgres — `'0002'` sorts first. Use `strcmp`. Head insertions generate
exactly these all-digit keys, and the tree property test found this bug at
seed 42.
