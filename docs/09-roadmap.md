# Build Order

Sequenced so each milestone is demoable and nothing is rewritten later.
Estimates assume two backend + two Flutter engineers.

## M0 — Foundations (1 week)

- Laravel 13 skeleton, Postgres 16, Redis, Horizon, Docker Compose (or Sail).
- Sanctum auth, `users` + `user_identities`, `spatie/laravel-permission`, the six
  roles (`admin`, `ops`, `content_author`, `content_reviewer`, `instructor`,
  `learner`), permission seeder.
- `audit_logs` + an `Auditable` trait.
- CI: Pest, PHPStan (`larastan`) at level 6 rising to 8, Pint, `flutter analyze`, `dart format`.
- Flutter monorepo (`melos`), `lms_core` with the dio client + auth interceptor.

**Done when:** an admin can invite a user, assign a role, and log in from the app.

## M1 — Course schemas (1 week)

- `course_schemas`, `schema_versions`, `schema_levels`, immutability trigger.
- CRUD + publish/archive endpoints.
- Studio: schema builder UI (add level, set cardinality, pick block types).

**Done when:** an admin defines "Part → Chapter → Topic", publishes v1, and
cannot subsequently edit it — only clone to v2.

## M2 — Course tree (2 weeks) — *the core*

- `courses`, `course_nodes`, structural triggers, `ltree` paths, fractional
  `sort_key`.
- `allowed-children` endpoint. Create / move / reorder / delete.
- `content_blocks` with `rich_text` only, JSON Schema validation.
- Studio: schema-driven tree editor + Portable Text editor.

**Done when:** creating a Topic under a Part is rejected by the database, and
the UI never offered the option in the first place.

Property-test the tree here: generate random create/move/delete sequences,
assert I2/I3/I7/I8 hold after each. This is the milestone where a bug costs the
most later.

## M3 — Media & block types (1.5 weeks)

- Presigned direct-to-S3 upload, `media` lifecycle, checksum dedupe.
- Mux/Cloudflare integration + webhook → `status: ready`.
- Blocks: `video`, `image`, `attachment`, `embed`, `callout`.
- Flutter block renderer registry + goldens (`07-§3`).

**Done when:** an author uploads a 500 MB video, it transcodes, and the studio
preview plays it without PHP touching a byte of it.

## M4 — Review & publishing (1.5 weeks)

- `review_requests`, anchored threaded `review_comments`.
- Course workflow state machine; separation of duties (`03-§5`).
- `CourseValidator` + `GET /validate` readiness panel.
- Publish pipeline, `course_publications`, snapshot builder, ETag caching,
  rollback.

**Done when:** an author submits, a reviewer comments on a specific block and
requests changes, the author resolves and resubmits, an admin publishes, and
`promote` rolls back to publication 1 in one request.

## M5 — Assessments (2.5 weeks)

- Question banks, questions, options; `mcq_single`, `mcq_multi`, `true_false`,
  `short_answer` first. Others after.
- Assessments, `assessment_questions`, settings.
- Attempts: start (frozen `question_order`), answer, submit, auto-grade, expire.
- `QuestionViewerResource` + the test asserting no `is_correct` leaks (I14).
- Manual grading queue.
- Flutter: player UI, timer bound to server `expires_at`, results screen.

**Done when:** a learner takes a timed test, loses connectivity at question 12,
reconnects, and submits without losing an answer.

## M6 — Products & entitlements (1 week)

Do this **before** the first client integration. The resolver is what the launch
calls; building the launch first means building it twice.

- `products`, `product_courses`, `comp_grants`.
- `EntitlementResolver` — one class, one code path, Redis-cached, bust-on-write.
- `/me/courses` catalogue driven by the resolver.
- `403 not-entitled` problem responses with the `reason` + `cta` shape (`11-§7`).

**Done when:** a content reviewer reads a published course via a comp grant, and
a user with no grant gets a 403 carrying a paywall CTA — never a 404.

## M7 — Delivery & offline (2 weeks)

- `node_progress`, `/me/*` endpoints, batch progress flush.
- Snapshot download, `media_manifest` fetch, drift persistence, checksum verify.
- Outbox + `SyncEngine` with idempotency keys.
- Update prompt on new publication; never swap mid-lesson.
- Entitlement re-check on refresh; pack deletion on `entitlement_revoked`; the
  offline grace window (`11-§5.2`).

**Done when:** the learner app is put in airplane mode after downloading a
course, completes three topics and a quiz, and everything lands correctly on
reconnect — and a revoked entitlement removes the pack on the next refresh.

## M8 — B2C subscriptions (1.5 weeks)

- `plans`, `subscriptions`, `purchases`; Razorpay checkout + webhooks.
- Signature verification on the **raw** body, `provider_event_id` idempotency,
  async handling, stale-transition guard (`11-§4.2`).
- Paywall screens. **Resolve the in-app-purchase question first** (`08-§D4`) —
  it determines whether this milestone also contains StoreKit and Play Billing,
  which roughly doubles it.

**Done when:** a subscription bought on the web unlocks the mobile app, a
cancellation ends access at period end, and a replayed webhook is a no-op.

## M9 — B2B clients & launch (2.5 weeks)

- `clients`, `client_keys`, `client_deployments`, `client_users`,
  `client_contexts`, `resource_links`, `launch_sessions`, `launch_tickets`.
- LTI 1.3 via `packbackbooks/lti-1p3-tool`: OIDC init, `id_token` validation
  against the full checklist (`10-§4`), Deep Linking picker.
- Custom-JWT launch adapter producing the same `LaunchSession`.
- One-time ticket exchange; universal/app links with a Flutter-web fallback.
- JIT provisioning, explicit role mapping, `client:{slug}` identities with
  `users.email = NULL`. **No email auto-linking** (`10-§7`).
- `client_entitlements`, seat models, soft overage.
- Partner API: client-credentials OAuth, `EnsureClientScope`, roster upsert.
- Launch failure log surfacing the exact rejected claim.

**Done when:** a student clicks a link in a Moodle sandbox, lands on Chapter 3
in the Flutter app, and a replayed `id_token` is rejected on the `jti`.

Security review gate. This milestone is the one that gets you breached.

## M10 — Activity & reporting (1.5 weeks)

- `activity_events` (partitioned), client-stamped ingest, UUIDv7 idempotency.
- Per-client gapless outbox; ordered webhook delivery with HMAC signatures and
  the retry ladder; parked-stream alerting and replay.
- Pull API cursored on `sequence`. Nightly Parquet drop.
- LTI AGS score passback with monotonic timestamps; `PendingManual` for essays.
- xAPI serialiser.
- The test that proves a linked account's B2C activity never appears in a
  client's feed (`12-§6`).
- Client console: seats, roster, delivery health, launch failures, CSV export.

**Done when:** a graded test appears in the SIS gradebook within a minute, ABC's
feed contains zero XYZ rows, and killing the client's endpoint for an hour then
restoring it delivers every missed event in order.

## M11 — Hardening (ongoing)

- Item analysis materialised view (`05-§6`).
- Notifications (mail + FCM), digest preferences.
- Search (`tsvector` + `pg_trgm`).
- Rate limits, 2FA for admins, audit viewer.
- Load test: 5k concurrent learners fetching a snapshot, 500 concurrent attempt
  submissions. The snapshot is served from Redis; the submissions are the real
  test.
- Accessibility audit against WCAG 2.2 AA.

---

## Backend packages

```
laravel/framework ^13    spatie/laravel-permission
laravel/sanctum          spatie/laravel-model-states
laravel/horizon          spatie/laravel-query-builder
laravel/scout (later)    league/flysystem-aws-s3-v3
opis/json-schema         staudenmeir/laravel-adjacency-list  # if you skip ltree
pestphp/pest             larastan/larastan

# B2B / commerce
packbackbooks/lti-1p3-tool     # LTI 1.3 + Advantage. Do not hand-roll.
web-token/jwt-framework        # custom-JWT launch + private-key JWT client assertions
razorpay/razorpay              # or stripe/stripe-php
readdle/app-store-server-api   # if you ship IAP
```

`opis/json-schema` validates `content_blocks.payload` and `questions.grading`
against the per-type schemas. Wire it into a `ValidatesJsonPayload` FormRequest
trait so the rule lives in one place.

## Flutter packages

```
flutter_riverpod  go_router     dio          freezed
drift             sqlite3_flutter_libs        flutter_secure_storage
better_player     cached_network_image        flutter_math_fork
flutter_downloader              connectivity_plus
melos             golden_toolkit              patrol
```

## Environments

| Env | Notes |
|---|---|
| local | Sail; MinIO for S3; Mux test env or a stubbed transcoder |
| staging | Full stack, seeded with a realistic 3-level course + 200 questions |
| prod | Managed Postgres (PITR on), Redis, S3, Mux; app servers behind a CDN |

Seed staging with a course large enough to hurt — 8 parts × 12 chapters × 15
topics ≈ 1,440 nodes. Every performance problem in this design shows up there
and nowhere smaller.
