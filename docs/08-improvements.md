# Improvements on the Original Brief

Three groups: changes I already folded into the design and why; gaps you will
hit within a quarter; and things to deliberately not build.

---

## Part A — Changes made to the brief

### A1. Content is permitted per-level, not only at the leaf

**Brief:** *"bottom level of the course content could be descriptive content, video."*

**Change:** `schema_levels.allows_content` + `allowed_block_types`.

Every real textbook has a chapter introduction above its topics. Restricting
content to leaves forces authors to invent a fake "Introduction" topic, which
then pollutes progress tracking and the table of contents. The per-level flag is
the same amount of code and strictly more general. `01-§4` shows a Chapter that
carries an intro paragraph *and* has Topic children.

### A2. Schemas are versioned; courses bind to a version

**Change:** `course_schemas → schema_versions → schema_levels`; `courses.schema_version_id`.

Without this, renaming "Unit" to "Module" or adding a level rewrites the meaning
of every course already authored against that schema. With 40 courses live, an
Admin editing a schema becomes an unauditable data migration. Published versions
are immutable (trigger-enforced, I9); "edit" clones a new draft version.

Cost: one extra table. Benefit: schema evolution stops being terrifying.

### A3. Draft/published separation via immutable snapshots

**Change:** `course_publications` with a frozen `snapshot jsonb`.

The brief implies Viewers read the course. If they read the *live* tree, then
the moment an author drags a chapter, a student mid-lesson sees the floor move.
And a half-written topic is visible the instant it is saved.

Snapshots give you, essentially for free:
- Authors edit continuously with zero viewer impact.
- **Rollback is one `UPDATE`** (`04-§6`).
- Offline packs have a stable identity and an ETag (`07-§5`).
- Attempts and progress attribute to the exact content version the learner saw —
  which is what you need when a student disputes a grade.
- Publication-to-publication diffs give you a real changelog.

This is the single highest-leverage change in this document.

### A4. Questions live in a bank, not inside an assessment

**Change:** `question_banks / questions` + `assessment_questions` pivot.

Questions get reused across a topic quiz, a chapter test, and a revision test.
Embedded questions mean fixing a typo three times, and item statistics that
fragment across duplicates so you can never tell which questions are broken.
See `05-§2` and the item-analysis view in `05-§6`.

### A5. A reviewer may not review their own course

**Change:** `CoursePolicy::review()` denies when the user holds an `author` or
`owner` grant on that course, regardless of global role.

Roles as listed are global — they cannot answer "author of *which* course?". The
two-axis model (global role = capability, `course_grants` = scope) fixes that
and makes separation of duties expressible. `03-§1`, `03-§5`.

### A6. Structural rules enforced in the database, not just the app

**Change:** triggers for I3 (level nesting), I5 (block type per level), I8
(path/depth), I9/I10 (immutability).

Laravel `FormRequest` validation is bypassed by every artisan command, every
seeder, every future queue job, and every `tinker` session at 2 a.m. Tree
invariants are exactly the class of thing that corrupts silently and is
unrecoverable six months later. Put them where they cannot be bypassed. See
`01-§2` for what is enforced where — and note I12, cardinality, which is
deliberately *not* a trigger.

### A7. Ordering by fractional index, not integer `position`

**Change:** `sort_key text` (LexoRank).

Reordering with integer positions rewrites N sibling rows per drag. Two authors
dragging concurrently produce a scrambled order. Fractional indices make a
reorder a single-row update with no cross-row contention. `02-§10`.

### A8. Rich text as structured JSON, not HTML

**Change:** `payload.body` is Portable Text / ProseMirror JSON.

HTML forces `flutter_html` on the client (slow, lossy, unmaintained) and an XSS
sanitisation problem on every write path. Structured JSON renders as native
Flutter widgets, diffs cleanly, and is machine-readable for search and screen
readers. `07-§4`.

### A9. "Test" and "Quiz" are one entity with different defaults

**Change:** `assessments.kind ∈ {quiz, test}` over a shared settings blob.

They differ in timing, attempts, and answer visibility — not in structure.
Two tables would duplicate attempts, grading, and analytics. `05-§1`.

---

## Part B — Gaps you will hit within a quarter

Ordered by how much pain deferring them causes.

### B1. ~~Multi-tenancy~~ — **resolved**: single-tenant content, multi-client identity

*(This section originally argued for a `tenant_id` decision. The brief has since
clarified: one content provider, one catalogue, B2B clients consuming it via SIS
integration, plus direct B2C subscribers. That resolves it — but not entirely in
the direction "no multi-tenancy needed".)*

Content is genuinely single-tenant. No `tenant_id` on `courses`, `course_nodes`,
`content_blocks`, `assessments`, `questions`. This removes an entire class of
"missing global scope leaked another school's course" bug, and it is a real
simplification. Good call.

But ABC School's **people and their activity** are absolutely tenanted, and that
data is more sensitive than the content ever was:

| Data | Tenanted? | Carries `client_id`? |
|---|---|---|
| Courses, nodes, blocks, questions | No — shared catalogue | No |
| Publications, media | No | No |
| `client_users`, `client_contexts`, `resource_links` | **Yes** | Yes |
| `launch_sessions`, `activity_events` | **Yes** | Yes |
| `client_entitlements`, seat assignments | **Yes** | Yes |
| `users`, `subscriptions`, `node_progress` | Per-user, not per-client | No |

So: **`client_id` on roughly a dozen tables, all below the authoring line, plus
one `EnsureClientScope` middleware and one route-coverage test** (doc 03 §6.6).
That is a tenth of the cost of full multi-tenancy and it lands in the right
place. Docs `10`, `11`, `12` specify it.

The failure mode to actually fear is no longer "school A edits school B's
course". It is "school A's activity report contains school B's students", or
"a linked B2C account's private study activity is reported to the school". Both
are prevented by attributing every event to exactly one session context and
never deriving `client_id` from request input.

### B2. The role list is missing the people who run classes — **partly resolved**

`Admin / Content Author / Content Reviewer / Viewer` covers *making* content. It
does not cover *delivering* it. Doc `03` now adds:

- **`instructor`** — grades essay answers, sees their own class's progress,
  creates deep links. Scoped to their `client_context`s, never client-wide.
- **`learner`** — renamed from `viewer`. A viewer browses; a learner has
  entitlements, attempts, progress, a guardian, and a right of erasure.
- **`client_admin`** — the school's own person, scoped to their client console
  only. Not an LMS admin.
- **`ops`** — your staff who manage clients, keys, and entitlements without
  holding content authority.

Still open: **cohorts on the B2C side.** For B2B, `client_contexts` *is* the
class, provisioned from the SIS launch — you get it for free and should not
build a parallel `cohorts` table. For B2C you have no classes, only individuals.
If you later sell to coaching centres who want a teacher dashboard without an
SIS, that is when you model a first-class cohort. Not before.

Also note: **`enrollments` (doc 02 §8) is now redundant** and should be dropped.
Access is `EntitlementResolver`, not an enrollment row. Keep `node_progress`.

### B3. Sequencing and gating

"Complete Topic 1.1 before 1.2 unlocks", "score ≥ 60% on the Chapter 1 test to
open Chapter 2". This is the most-requested LMS feature that never appears in
the initial brief.

It fits cleanly as a `node_prerequisites` table + a `gating` object on
`schema_levels` (so the *schema* declares whether its topics are sequential).
Design the space for it now; build it when asked.

### B4. Localisation of content, not just UI

"Grade 10 English", "Grade 12 Economics", "G&SR" reads like an Indian
curriculum. Expect Telugu/Hindi/regional-language editions of the same course.

Two options:
- **Course-per-language**, linked by a `course_translation_group_id`. Simple,
  duplicated structure, diverging trees. Fine if translations are independent
  editions.
- **Translatable fields** (`node_translations`, `block_translations` keyed by
  `locale`). Structure stays shared, translations track the source.

Pick the first unless the structures must stay in lockstep. Retrofitting either
is painful; at minimum, put `language` on `courses` (done) and never assume one
locale per deployment.

### B5. Data protection for minors

If learners are schoolchildren in India, the **DPDP Act 2023** requires verifiable
parental consent for users under 18 and prohibits behavioural tracking and
targeted advertising directed at children. Practically:

- A `guardian_consents` table, consent captured before activation.
- Date of birth on `users`, and an age gate.
- Data export and erasure endpoints (`GET/DELETE /me/data`) that actually work —
  including attempts, progress, and audit rows.
- Retention policy per table, enforced by a scheduled job, not a wiki page.

Also relevant if you ever sell outside India: COPPA (US, under 13), FERPA
(US schools), GDPR Art. 8. Build the consent and erasure machinery once.

### B6. Standards interoperability — **LTI 1.3 is now core, not optional**

Your launch requirement *is* LTI 1.3 + Advantage, claim for claim (doc 10 §3).
Build it as the primary integration, with a custom-JWT adapter for the SIS
vendors who don't speak it. Certifying once means you never negotiate a bespoke
protocol with the next school.

Ranked by when you'll need them:

| Standard | For | When |
|---|---|---|
| **LTI 1.3 Core + Deep Linking** | The launch itself | First B2B client |
| **LTI AGS** | Scores into their gradebook | First B2B client with assessments |
| **LTI NRPS** | Roster sync without a launch | Second client |
| **xAPI** | Clients with an LRS | On request |
| **QTI 2.1** import | Question banks from publishers | On request |
| **Caliper 1.2** | Same events, IMS envelope | On demand only |
| **SCORM 1.2/2004** | Legacy packages — as an opaque block type, never unpacked into your tree | Avoid if you can |
| Plain **CSV** | Everything, always | Week two |

Do not build a bespoke "activity JSON" and call it done. The client's data team
will ask which standard it is, and "ours" is the wrong answer to give a school
board.

### B7. Media cost and delivery

Video is the dominant line item and the dominant support burden.

- Do not run `ffmpeg` on your app servers. Use Mux or Cloudflare Stream.
- Signed, short-lived playback URLs; otherwise your paid content is a public CDN.
- Per-course storage and egress metering from day one — you cannot price what you
  do not measure.
- ABR ladder capped at 720p for a school audience on 3G. 1080p on a phone is
  bandwidth you pay for and nobody sees.
- Dedupe by `checksum_sha256` (already in `media`). Authors re-upload the same
  diagram constantly.

### B8. Notifications

`review assigned`, `changes requested`, `course published`, `attempt awaiting
grading`, `new publication available`. Laravel notifications over mail + FCM.
Add a per-user digest preference before you add the fifth notification type, not
after.

### B9. Content reuse across courses

"G&SR" content overlaps with a dozen exam courses. Today the design duplicates
it. A `shared_node_library` with linked (not copied) nodes is a genuinely hard
feature — versioning, permissions, and publish semantics all get harder — so
**do not build it speculatively**. But know that "copy this chapter into another
course" (a deep clone) satisfies 80% of the demand for 5% of the cost. Build the
clone.

### B10. Observability

- Structured logs with `X-Request-Id` correlating Flutter → Laravel.
- Sentry both sides, sourcemaps/symbols uploaded in CI.
- Business metrics, not just RED: publications per week, validation failures by
  code, attempts started vs completed, offline pack completion rate.
- Query the `E_*` finding codes over time — a spike in `E_EMPTY_LEAF` means your
  editor UX is confusing, not that authors are lazy.

---

## Part C — Deliberately not building

| Not building | Why |
|---|---|
| Webcam proctoring, keystroke biometrics, focus-loss detection | False positives, legal exposure, trivially defeated, and hostile to the learner. Integrate a vendor if a customer insists. `05-§5`. |
| A table per content block type | Seven joins to render a page, a migration per new block type. `jsonb` + JSON Schema validation. |
| Live collaborative editing (CRDT/OT) | Enormous. Optimistic locking + `409` is sufficient for 1–3 authors per course. |
| Self-hosted video transcoding | You will lose a month to `ffmpeg` flags and another to a queue that falls over on a 4 GB upload. |
| A general workflow engine | Five states and four transitions. A `spatie/laravel-model-states` enum beats a rules engine nobody can debug. |
| Recomputing "Chapter 3" on the client | Bake numbering into the snapshot. Two clients must never disagree on a chapter number. `04-§5.1`. |
| GraphQL | The access patterns are a tree fetch and a form post. REST + `?include=` is enough, and Flutter codegen for REST is simpler. |
| Your own JWT/JWKS launch validation | `packbackbooks/lti-1p3-tool` has been through the exploits you haven't thought of. `10-§4`. |
| A bespoke activity JSON format | Emit LTI AGS + xAPI. Schools' data teams ask which standard it is. `12-§5`. |
| DRM on offline packs | A rooted device keeps the MP4. Short-lived signed streaming URLs, an offline grace window, and priced-in leakage. `11-§5.2`. |
| Auto-linking a launch to a B2C account by email | It is an account-takeover primitive handed to every client you onboard. `10-§7`. |
| Full multi-tenancy | Content is shared. Tenant only the identity/activity/entitlement tables. `B1`. |

---

## Part D — Decisions

### Resolved by the clarified brief

1. ~~Multi-tenant or not~~ → single-tenant content, `client_id` on the dozen
   identity/activity/entitlement tables. (B1)
2. ~~Who are the users~~ → staff (`admin`/`ops`/`author`/`reviewer`) +
   consumers (`learner`/`instructor`) + client membership (`client_admin`). (B2)

### Still open, and still expensive to defer

3. **Is offline a requirement?** (`07-§5`) — if yes, the snapshot design (A3) is
   non-negotiable, the media strategy (B7) is load-bearing, and you owe learners
   an entitlement grace window and a revocation path (`11-§5.2`). If no, you can
   defer all three and ship the B2B pilot a month sooner. *School bandwidth in
   the target market suggests yes.*

4. **Does the Flutter app sell subscriptions in-app?** (`11-§4.1`) — Apple and
   Google take 15–30% of digital content sold inside the app, and the reader-app
   exemption only holds if you never link to your own purchase flow from within
   it. This determines your paywall screen, your `subscriptions.provider`
   handling, and your unit economics. Decide before you build the paywall, not
   after review rejects it.

5. **Do B2B and B2C identities ever merge?** (`10-§7`) — the safe default is
   *never automatically*, with an explicit user-initiated link. Confirm this is
   acceptable to the business, because "my school account doesn't show my
   personal progress" will be a support ticket, and the alternative is an
   account-takeover vector. Take the support ticket.

6. **Seat model per client** (`11-§3`) — `assigned`, `active`, or `unlimited`.
   You will end up supporting all three; decide which is the default in the
   contract template so sales stops promising the other two.

Everything else here can be added incrementally without a rewrite. These cannot.
