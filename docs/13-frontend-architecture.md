# Frontend Architecture

Four surfaces, four audiences, four auth stories. They are separate on purpose.

| Surface | Audience | Stack | Auth |
|---|---|---|---|
| **Learner app** | Students, instructors (B2C + launched B2B) | Flutter (mobile) + Flutter Web fallback | Bearer token; launched sessions carry `cid` |
| **Studio** | Content authors, reviewers, admins | Inertia + React + TypeScript, inside the Laravel app | Session cookie, staff only |
| **Ops back-office** | Admins, ops | Filament at `/ops` | Session cookie + mandatory 2FA |
| **Client console** | A client's own `client_admin` | React (same repo, separate entrypoint + hostname) | Launch-only, client-scoped token |

---

## 1. Learner app — Flutter

Unchanged from `07-flutter-app.md`. Renders the published snapshot; never the
draft tree.

The **Flutter Web build** exists for one reason: a launched student whose school
gave them a Chromebook cannot install an APK. `/l/{ticket}` must resolve as a
real web page. It is a read-only, short-session surface, so a canvas renderer is
an acceptable trade there.

---

## 2. Studio — Inertia + React + TypeScript

### Why not Flutter Web

The studio shares almost nothing with the learner app. The learner app
*renders* content blocks; the studio *edits* them. That is a different program,
and its three hardest widgets are the three Flutter Web is worst at.

**The rich text editor.** `rich_text.payload` is Portable Text — ProseMirror-shaped
JSON (`01-domain-model.md §1`). The editor that produces that document model is
TipTap/ProseMirror. There is no credible equivalent in Flutter. Building a
structured-document editor with marks, tables, math and selection semantics is a
multi-year project that people found companies around. On its own this is close
to decisive.

**The tree editor.** Drag-and-drop across a 1,400-node tree, with validity shown
*during* the drag (a Topic may not drop onto a Part — `04-authoring-and-review.md §1`).
`dnd-kit` does this, including keyboard reordering. In Flutter you would rebuild
it, accessibility included.

**Everything the browser gives you.** Flutter Web renders to canvas. Text
selection, find-in-page, Grammarly, password managers, copy-paste fidelity,
right-click, deep-linkable state and screen-reader support all degrade — Flutter
exposes a semantics overlay, not a DOM. We committed to WCAG 2.2 AA in
`09-roadmap.md §M11`. Authors live in this tool for hours; reviewers need to
select and quote text. It is the wrong trade.

### Why Inertia rather than a standalone SPA

`/api/v1` is a **contract surface**. Flutter consumes it; B2B partners consume
`/partner` and `/client`. It should stay small, stable and versioned.

Studio screens are chatty and page-shaped. Giving them Inertia endpoints keeps
them out of an API we have promised to schools. We also get, for free:

- Session-cookie auth on the same origin. No token juggling, no CORS, CSRF handled.
- **One authorization source.** `CoursePolicy` and the Gate stay authoritative;
  the client never re-implements "may this user publish?" It renders what the
  server says it may render.
- Server-driven routing and redirects, which is what a form-heavy tool wants.

### Stack

| Concern | Choice |
|---|---|
| Transport | Inertia 2 |
| View | React 19 + TypeScript |
| Build | Vite |
| Styling | Tailwind |
| Rich text | TipTap (ProseMirror) → Portable Text serialiser |
| Tree drag/drop | `dnd-kit` |
| Tables | TanStack Table |
| Charts | Recharts |
| Uploads | Presigned direct-to-S3; `tus` for large video |
| Types | `spatie/laravel-typescript-transformer` — block payload shapes stay in sync with the JSON Schemas |

The item-analysis screen is a scatter: facility on one axis, discrimination on
the other. Miskeyed questions land in the bottom-left quadrant and become
visually obvious (`05-assessments.md §6`).

### Auth

Session cookie, and **staff only**. A client-provisioned user has
`users.email IS NULL` and no password — enforced by
`users_provisioned_has_no_password`. They cannot log in here, cannot be phished
into it, and `EnsureStaff` refuses them a second time at the middleware.

---

## 3. Ops back-office — Filament

At `/ops`, behind mandatory 2FA for `admin` and `ops` (`03-rbac.md §7`).

Users, roles, products, plans, clients, key rotation, entitlements, comp grants,
the audit-log viewer, outbox delivery health and replay.

This is CRUD and operational tooling, and Filament does that in a week. **Do not
build the schema builder or the tree editor here.** Filament will fight you the
whole way. The split is the point: Filament for tables and forms, React for the
two surfaces that are genuinely bespoke.

### The trade

Two frontend stacks and two build pipelines. Accepted, because they serve
different users on different lifecycles — the studio is a product, the ops panel
is a tool. If you would rather have one, drop Filament and build the CRUD in
Inertia too: roughly three weeks slower, simpler repo, defensible. What is not
defensible is putting a ProseMirror-class editor in Flutter Web to save a
codebase that was never really shared.

---

## 4. Client console — separate app, separate origin

ABC School's IT coordinator. A **customer**, not staff.

Four screens, and they exist to remove the B2B support load (`11-§8`):

- **Seats** — used vs purchased, and the overage. Seats are soft-enforced; a
  student is never locked out mid-term. This is where the school sees the
  overage before an account manager calls them.
- **Roster** — who is active, who has never launched, deactivate a leaver.
- **Integration health** — last launch, launch failures *with the exact rejected
  claim*, webhook delivery lag, parked streams, replay. "The launch doesn't work"
  and "we're missing events" are the entire B2B support queue.
- **Activity export** — CSV for their data team.

### Why a separate app

Sharing a session between the internal ops panel and an external customer is
precisely how a scoping bug becomes a cross-tenant read. `EnsureClientScope` and
its route-coverage test hold today *because* the client group is separate and
enumerable. Fold it into the studio and a staff session can reach customer
routes, and the test stops meaning anything.

**Same React repo** — shared component library, shared generated types — built as
a **separate Vite entrypoint on a separate hostname**:
`console.example.com` vs `studio.example.com`. One repo, two apps, no shared
session.

### It is not the partner API

The partner API is server-to-server: their SIS pulling activity or pushing a
roster. The console is a human looking at a screen.

---

## 5. Two open items

### 5.1 Client-admin authentication is launch-only

Today a `client_admin` reaches the console exactly one way: an admin-role launch
from their SIS. The console needs a token carrying `client_id`, and only
`ExchangeTicket` mints one.

This is not an accident. Client-provisioned users hold no password precisely so
a compromised SIS cannot be used to take over an account (`10-§7`). There is no
second credential to phish.

The cost: the coordinator cannot check delivery health at 11pm without going
through Moodle. If that turns out to matter, the fix is an **invited local
account** explicitly linked to their `ClientUser`, with 2FA — and never by
matching an email address.

### 5.2 Pull reporting has no machine credential — blocking

`GET /api/v1/client/activity` sits behind `assertClientAdmin`, which needs a
human token. A SIS *server* has no launch session and cannot reach it.

`12-§4.2` says most SIS sit behind a firewall and cannot receive webhooks. So
without client-credentials auth (private-key JWT, `10-§11`) a school's data team
cannot fetch their own data at all.

**Close this before the first SIS integration goes live.** It is a few days of
server-side work and depends on none of the UI above.

---

## 6. Build order

1. **Studio.** Nothing else has content to show.
   1. Scaffold + staff auth + shell.
   2. Schema builder — the thing every course depends on.
   3. Tree editor — schema-driven, `dnd-kit`, the core of the product.
   4. Block editor — TipTap → Portable Text, media upload.
   5. Review + publish: anchored comments, readiness panel, publish, rollback.
   6. Question bank and assessments.
2. **Ops panel** (Filament), alongside. One to two weeks.
3. **Partner OAuth** (server-side) before the first SIS goes live.
4. **Client console.** Wait for the second B2B client; until then the support
   load is you reading the launch-failure log yourself.

## 7. One thing to decide now

**The studio and the learner app must not share a design system.** The studio is
a dense professional tool; the learner app is a reading experience. Sharing
tokens between them produces a studio that feels like a phone app and a reader
that feels like a spreadsheet. Two token sets, one shared brand palette.
