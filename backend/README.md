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
| M7 — progress tracking, offline merge rules, batch outbox flush | ✅ (server half) |
| M8 — plans, subscriptions, purchases, Razorpay webhooks | ✅ (web path) |
| M9 — clients, custom-JWT launch, JIT provisioning, client entitlements, scoping | ✅ (launch core) |
| M10 — activity events, gapless outbox, signed webhooks, pull API, xAPI | ✅ |
| M11 — partition maintenance, item analysis, scoped search, rate limits, perf guard | ✅ |

Not built: notifications (mail/FCM) and the accessibility audit, which needs the
Flutter client.

M9 builds the launch **core**: validation, replay defence, JIT provisioning,
one-time tickets, client-scoped tokens, contract entitlements and seats. The
LTI 1.3 adapter plugs into `LaunchValidator` alongside `CustomJwtValidator`.
Deep Linking, AGS grade passback and NRPS roster sync belong with M10's
reporting work and are **not** built — the schema reserves `lineitem_url` and
`client_deployments` for them.

M7's Flutter half (drift persistence, media pre-download, the client outbox)
waits on the app existing. The server half — `node_progress`, the merge rules an
offline client needs, and `POST /me/progress` — is done.

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

## Operations

**Partitions do not create themselves.** `audit_logs` and `activity_events` are
range-partitioned by month. Past the last partition, *every insert fails at once*
— audit logging and activity ingest stop dead, silently, a year after launch.
`partitions:ensure` runs daily and keeps six months of runway;
`PartitionMaintenanceTest` reproduces the failure so nobody deletes the command
thinking it does nothing. **Alert on a short runway, not on a failed insert.**

Retention lives in `config/retention.php` because it is a contract term, and it
bounds the cost of a data-subject erasure request. `activity:prune` detaches
before dropping, so a nightly prune cannot stall ingest.

**Item analysis** (`question_stats`) computes facility and the *corrected*
point-biserial discrimination — the item against the score on the **rest** of the
assessment. Correlating an item against a total that contains it correlates the
item with itself; on a short test that alone can make a worthless item look good.
Discrimination below zero means the strongest learners did worst on that item,
which almost always means the answer key is wrong, and nothing else in the system
would notice. Refreshed `CONCURRENTLY`, which is why the view carries a unique
index.

**Search is scoped by the resolver.** An endpoint returning node titles from
courses the caller cannot read is a content leak: *"Chapter 7: Kinematics of
Rigid Bodies"* is the product. The candidate set is `coursesFor()`, and the query
filters to it — never the other way round.

**Rate limits** sit on `launch` (per IP: verifying a signature is exactly the
work we don't want commanded unboundedly, and the client is unknown until it
verifies), `activity`, `search` and `checkout`. The payment and reporting webhook
endpoints are deliberately **unlimited** — a provider retrying a burst must not
be throttled into a parked stream.

**Performance is guarded by query counts, not by wall time.** A CI box's clock is
not a benchmark; an N+1 is. `LargeCoursePerformanceTest` builds a 152-node course
and asserts the snapshot builder, the validator and the content endpoint all take
a *constant* number of queries regardless of tree size, that `descendants()` is
one `ltree` query, and that reordering a sibling rewrites exactly one row.

## B2B launch

Content is single-tenant — no `client_id` on courses, nodes or blocks. A
client's **people and their activity** are tenanted, and that data is more
sensitive than the content ever was. The failure mode to fear is not "school A
edits school B's course"; it is "school A's report contains school B's students".

**The redirect carries a ticket, never a token.** URLs land in browser history,
access logs, `Referer` headers and screen recordings. The ticket is opaque,
single-use, 60 seconds, stored only as `sha256(ticket)`, and burnt under
`lockForUpdate` so a double-click cannot mint two sessions.

**The signing algorithm is pinned from our record of the key**, never read from
the token header. Otherwise the attacker picks it: `alg: none`, or `HS256` with
our own public key as the HMAC secret. Only `RS256`/`ES256` — a symmetric
algorithm would make our verification key a signing key.

**A launch never links to an existing account by email.** An email in a launch
token is a claim by the client *about a third party*: enough to display, never
enough to authenticate. A compromised SIS asserting `email: victim@school.edu`
would otherwise walk into that victim's paid B2C account. Launches only ever
create or reuse a `client:{slug}` identity, and the user they provision has no
email and no password — the database enforces it.

**Replay defence is Redis *and* Postgres.** The cache is the fast path; the
unique index on `(client_id, jti)` is the truth, and it survives a cache flush.
Both, not either.

**An unrecognised role maps down to `learner`, never up.** A client that starts
asserting a role string nobody anticipated must not thereby mint administrators.

**`EnsureClientScope` reads the client from the access token**, never from a
request parameter. `ClientScopeTest` enumerates the client route group and fails
if any route lacks it — the threat is a route added six months from now by
someone who has not read the middleware's docblock.

**Seats are soft-enforced.** A student locked out mid-term because their school
under-purchased is an escalation, and the school pays anyway: allow the read,
flag the overage, invoice. Only the `assigned` model refuses, because assignment
is an explicit administrative act and the error can point at someone who can fix
it.

## Activity and reporting

**Two logs.** `audit_logs` records administrative acts; `activity_events` records
learning acts. Different volume, different consumers, different privacy exposure.
Merging them pleases neither.

**Every event is attributed to exactly one context** — a client, or nobody (B2C).
`client_id`, `client_user_id`, `launch_session_id` and `grant_source` are stamped
**server-side from the session**. A client-supplied `client_id` in the request
body is ignored, not honoured. That single fact is what makes "report ABC's
activity to ABC, and nothing else" correct by construction, and
`ActivityDeliveryTest` proves a linked account's personal study never appears in
their school's feed.

**Global idempotency lives in `activity_event_keys`.** Postgres cannot enforce a
unique constraint on a column that omits the partition key, so `activity_events`
can only be unique on `(id, occurred_at)` — and a replay whose `occurred_at` we
clamped differently would slip through. The small unpartitioned key table carries
the real uniqueness.

**The outbox sequence is gapless, not merely monotonic.** A Postgres sequence
burns a number on rollback, and a gap is indistinguishable from a lost event.
Gaplessness is the only thing that lets a SIS reconcile: they notice `sequence`
jumped and ask for what they missed.

**Head-of-line blocking is the point.** The retry backoff belongs to the *stream*,
not to each row. Filtering `next_attempt_at <= now()` per row lets a freshly
queued sequence 2 overtake a backed-off sequence 1 — the client then receives a
gap they cannot distinguish from data loss. A stream that cannot be delivered is
**parked and alerted on, never stepped over**, and resumed from the console.

Delivery is signed Stripe-style: `t=<unix>,v1=<hmac>` over `"{t}.{raw_body}"`.
The timestamp is inside the signed material, so a captured request cannot be
replayed at leisure.

Three delivery paths, because every SIS supports exactly one and never the one
you guessed: **webhook push**, **pull** (`GET /client/activity`, cursored on
`sequence`, never on a timestamp — timestamps collide and skew), and xAPI
statements for clients with an LRS. Reports carry the pseudonymous
`external_user_id` the client gave us; never a name or an email we were only
given to display. xAPI uses `actor.account`, never `mbox`.

> **Not built:** LTI AGS grade passback, NRPS roster sync, Caliper, and the
> nightly Parquet drop. AGS needs LTI's OAuth client-credentials flow, which
> waits on the LTI adapter.

## Billing

**Money is an integer count of minor units.** A float price will, eventually and
unpredictably, charge someone ₹498.99999997.

**Checkout grants nothing.** `POST /me/subscriptions` opens a subscription at the
provider in `pending`; `POST /me/purchases` opens a payment link for a
`one_time` plan. Both return a checkout URL and create no entitlement. Access
arrives with the capture webhook. A client that reached the endpoint expressed
intent to pay, not payment.

A plan's `interval` decides which endpoint serves it, and each refuses the
other's plans. A `one_time` plan opened as a subscription would sit `pending`
forever — no renewal means no activation webhook — leaving a learner who paid
and got nothing.

**The capture webhook trusts the plan's price, not the webhook's amount.** The
payment link's amount travelled through a client-visible URL; the `plan_id` in
its `notes` is ours.

**The webhook handler verifies, persists, acknowledges, then processes.**
Providers time out at 5–10 seconds and retry, so a slow entitlement rebuild
inline reads as a delivery failure.

- The HMAC covers `$request->getContent()` — the bytes as received. Re-encoding
  `$request->all()` reorders keys and drops whitespace, and no honest webhook
  would ever validate. `hash_equals`, not `===`.
- A missing webhook secret **throws**. An unconfigured secret must never mean
  "accept everything" — that is an unauthenticated write endpoint.
- Idempotency is `insertOrIgnore`, not a caught unique violation: a failed
  `INSERT` aborts the surrounding Postgres transaction, leaving the connection
  unusable for everything after.
- Webhook order is not guaranteed. Each event carries the provider's clock, and
  one older than the last applied event is stored, marked stale, and dropped —
  otherwise a retried `halted` overrides the `charged` that came after it.
- The provider's vocabulary is normalised at the edge. Nothing downstream learns
  what a `DID_CHANGE_RENEWAL_STATUS` is, and an unmapped event type moves a
  subscription nowhere.

**`past_due` and `canceled` still entitle, until the paid period ends.** Locking
a learner out on the first declined card — mid-term, while the provider is still
retrying — loses the customer you were trying to bill. Cancelling means "do not
renew", not "refund the month I already paid for".

**Entitlement lives on the user, not the platform.** `subscriptions.provider` is
a billing detail; nothing in the resolver knows what a platform is. A
subscription bought on the web unlocks the mobile app.

> **Open decision (docs/08 §D4):** does the Flutter app sell subscriptions
> in-app? Apple and Google take 15–30%, and the reader-app exemption holds only
> if you never link to your own purchase flow from within it. The schema already
> permits `apple` and `google` providers; only the web path is built.

## Progress and offline merge

`node_progress` is keyed by **publication**, not by course. Completing chapter 3
of publication 1 says nothing about publication 2, whose chapter 3 may be
different text entirely. `course_node_id` has **no foreign key** on purpose: the
learner is reading a frozen snapshot, and the author may since have deleted that
node from the draft tree.

The merge is a single `ON CONFLICT DO UPDATE`, never read-modify-write — two
devices flushing their outbox at the same moment would otherwise race and one
would lose its seconds. Its rules:

- `seconds_spent` settles on the **larger** total, not the newer. Clients report
  cumulative totals; summing would double-count the minutes both devices saw.
- Completion is **monotonic**. A late `in_progress` event from a device that was
  offline must not un-complete a finished lesson.
- `last_position` is a resume point, not a maximum: it comes from the newest
  client clock. Client clocks are clamped to `[now-30d, now+5m]`, which *bounds*
  a bad clock to five minutes rather than letting it win every merge forever.
- Only content-bearing nodes count toward completion. A Part that merely groups
  Chapters is not something a learner completes.

`POST /me/progress` is a batch flush and returns **202 with per-event results**.
One malformed event must not reject a batch containing an hour of a learner's
work. Only client-fixable errors are reported per-event; a `QueryException` is
ours and fails the request loudly. `publication_id` arrives in the request body,
so it is re-checked against the caller's entitlement — otherwise it is an
unauthenticated pointer into any course in the system.

## Two things that will bite you

**`sort_key` is `COLLATE "C"`.** Sort keys are base-62 fractions compared
byte-wise. Under the default locale-aware collation `'a'` sorts before `'B'` and
the whole ordering scheme collapses silently.

**Never compare sort keys with `<` / `>=` in PHP.** PHP compares two numeric
strings *numerically*: `'0002' >= '001'` is `2 >= 1` → true, while byte-wise —
and in Postgres — `'0002'` sorts first. Use `strcmp`. Head insertions generate
exactly these all-digit keys, and the tree property test found this bug at
seed 42.
