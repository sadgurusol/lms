# LMS — Functional & Implementation Overview

## 1. What this system is

A **content provider's** authoring and delivery platform for structured courses.
A course ("Grade 10 English", "Grade 12 Economics", "G&SR") is a tree of content
whose *shape* is dictated by a reusable **Course Schema** chosen at
course-creation time. Nodes of the tree hold descriptive content, video, and
assessments.

It is **not** a SaaS that institutions operate. One catalogue, one author team.
Content is single-tenant. Two audiences consume it:

- **B2B** — a client (ABC School) integrates their SIS. Their students and
  teachers click a content link inside the SIS, are launched into the LMS,
  validated, and served content under the client's contract. Activity is logged
  against both user *and* client, and reported back to the SIS. See `10`, `12`.
- **B2C** — an individual signs up in the Flutter app and subscribes. See `11`.

Both reduce to one question — *may this user, in this session context, read this
course right now?* — answered by one **EntitlementResolver** (`11-§1`).

Internal roles: **Admin**, **Content Author**, **Content Reviewer**.
Consumer roles: **Learner**, **Instructor**, **Client Admin** (`03`).

## 2. Stack

| Layer | Choice | Notes |
|---|---|---|
| Backend | Laravel 13 (PHP 8.3+) | API-only, no Blade beyond ops pages |
| DB | PostgreSQL 16 | `ltree`, `jsonb`, `tsvector`, `pgcrypto` |
| Auth | Laravel Sanctum (token guard) | Bearer tokens for Flutter |
| Authorization | `spatie/laravel-permission` + Policies | see `03-rbac.md` |
| Queue / cache | Redis + Horizon | transcoding, publish jobs, search indexing |
| Object storage | S3-compatible | direct-to-bucket presigned uploads |
| Video | Mux or Cloudflare Stream | do **not** roll your own transcode |
| Frontend | Flutter (mobile + web) | Riverpod, `drift` for offline |
| SIS integration | **LTI 1.3 + Advantage** (`packbackbooks/lti-1p3-tool`) | custom-JWT launch as fallback |
| Payments | Razorpay (web) + StoreKit / Play Billing (in-app) | server-side receipt verification |

## 3. The core concepts

```
                          AUTHORING (single-tenant)
CourseSchema  ──(versioned)──►  SchemaVersion  ──► SchemaLevel (tree of level defs)
                                      │
                                      │ bound at course creation
                                      ▼
                                   Course  ──► CourseNode (tree of actual content)
                                      │              ├──► ContentBlock (text/video/file)
                                      │              └──► Assessment (quiz/test)
                                      │
                                      └──► CoursePublication (immutable snapshot)
                                                    ▲
─────────────────────────────────────────────────── │ ──────────────────────────
                          DISTRIBUTION (multi-client) │
                                                      │
   Product ──< product_courses >── Course ────────────┘
      ▲
      ├── ClientEntitlement ── Client ──┬── ClientUser ── LaunchSession
      │                                 ├── ResourceLink (the link inside the SIS)
      │                                 └── event outbox ──► SIS reporting
      │
      └── Plan ──► Subscription ── User            (B2C)

                 EntitlementResolver answers: user × course × client → Grant?
```

The line across the middle is the important one. Above it, nothing knows a
client exists. Below it, nothing edits content.

### 3.1 CourseSchema
A named blueprint: `"Unit → Lesson"`, `"Part → Chapter → Topic"`,
`"Module → Section → Subsection → Topic"`. It defines, per level: the display
name, cardinality bounds, numbering style, whether that level may carry content,
and which block types are permitted there.

Schemas are **versioned**. A course binds to a `schema_version_id`, never to a
bare schema. Editing a published schema mints a new version; existing courses
keep rendering against the version they were built on. Without this, a schema
tweak silently invalidates every course already authored against it.

### 3.2 Course
An instance of a schema version, plus metadata (subject, grade, language,
cover art, owners). Holds exactly one **draft tree** of `CourseNode`s.

### 3.3 CourseNode
One node in the course's content tree. Every node points at the `SchemaLevel`
it fulfils; the API refuses to create a node whose parent's level is not the
declared parent of that level. This is where the schema is *enforced* rather
than merely suggested.

### 3.4 CoursePublication
Publishing validates the draft tree against the schema, then freezes an
immutable snapshot. **Learners only ever read publications.** Authors keep
editing the draft with zero risk of a half-finished chapter appearing in the
app. See `04-authoring-and-review.md`.

### 3.5 Product & Entitlement
Contracts and subscriptions point at **Products** (a course, a bundle, a
catalogue), never at courses directly. A `ClientEntitlement` grants ABC School a
product for a contract period and a seat count; a `Subscription` grants a B2C
user the same product. `EntitlementResolver` is the single place either is
checked. See `11-entitlements-and-billing.md`.

### 3.6 Client & LaunchSession
A **Client** is a B2B integrator. A **ResourceLink** is the durable object their
SIS stores ("Grade 10 English → Chapter 3"); a **LaunchSession** is one student's
click on it, validated against the client's signing key. Every activity event
carries the launch session's `client_id`, which is what makes reporting back to
that client — and only that client — correct by construction. See
`10-clients-and-launch.md`.

## 4. Lifecycle in two paragraphs

**Authoring.** An Admin defines a schema and publishes v1. An Author creates a
course against schema v1, builds out the node tree, attaches rich text / video /
quizzes, and submits for review. A Reviewer walks the tree, leaves anchored
comments, and either approves or requests changes. On approval an Admin
publishes: the system validates against the schema, snapshots the tree, and
increments the publication number.

**Delivery.** Ops adds the course to a Product; a Client contract entitles ABC
School to it. A teacher deep-links "Chapter 3" into their class. A student clicks
it; the SIS signs a launch; the LMS validates the signature, resolves or
just-in-time provisions the `ClientUser`, checks the entitlement, and mints a
one-time ticket. The Flutter app exchanges the ticket for a client-scoped token,
downloads the publication snapshot, and renders it — online or offline. Progress
and attempts flow back as `activity_events` stamped with `client_id`, which the
per-client outbox delivers to ABC's SIS as webhooks, an LTI AGS grade, or a
nightly file. A B2C learner walks the same delivery path with `client_id = null`
and a subscription instead of a contract.

## 5. Document map

| Doc | Contents |
|---|---|
| `01-domain-model.md` | Entities, invariants, state machines |
| `02-database-schema.md` | Postgres DDL for authoring & delivery |
| `03-rbac.md` | Three authorization axes, policies, partner API auth |
| `04-authoring-and-review.md` | Draft → review → publish, validation rules |
| `05-assessments.md` | Question bank, quizzes, tests, attempts, grading |
| `06-api-spec.md` | REST surface |
| `07-flutter-app.md` | App architecture, offline sync, renderer |
| `08-improvements.md` | Gaps in the original brief + what I'd add |
| `09-roadmap.md` | Suggested build order |
| `10-clients-and-launch.md` | B2B clients, LTI 1.3, launch security, identity |
| `11-entitlements-and-billing.md` | Products, contracts, seats, subscriptions |
| `12-activity-and-reporting.md` | Activity events, outbox, SIS reporting, privacy |
