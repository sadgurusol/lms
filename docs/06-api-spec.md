# REST API

Base: `/api/v1`. JSON only. Sanctum bearer tokens.
Errors follow RFC 7807 (`application/problem+json`).

```jsonc
// 422
{ "type": "https://lms.dev/errors/validation", "title": "Validation failed",
  "status": 422, "errors": { "title": ["The title field is required."] } }

// 409 publish blocked
{ "type": "https://lms.dev/errors/publish-blocked", "title": "Course is not publishable",
  "status": 409,
  "findings": [ { "code": "E_MIN_OCCURRENCES", "severity": "error",
                  "anchor": { "type": "node", "id": "n2" },
                  "message": "Chapter 1 must contain at least 1 Topic." } ] }
```

Conventions:
- Cursor pagination: `?cursor=…&per_page=50` → `{ data, meta: { next_cursor } }`.
- Sparse fieldsets / includes: `?include=blocks,assessments`.
- Mutations require `If-Match: <etag>`; `409 Conflict` on stale.
- `Idempotency-Key` header honoured on all `POST` that create money-or-attempt-like resources.

---

## Auth

| Method | Path | Notes |
|---|---|---|
| `POST` | `/auth/login` | `{email, password}` → `{access_token, refresh_token, expires_in, user}` |
| `POST` | `/auth/refresh` | rotate |
| `POST` | `/auth/logout` | revoke current token |
| `GET` | `/auth/me` | user + roles + permissions + grants |
| `POST` | `/auth/forgot-password` · `/auth/reset-password` | |
| `POST` | `/auth/2fa/challenge` | admins |

## Users & roles — `admin`

| Method | Path |
|---|---|
| `GET` | `/users?search=&role=&status=` |
| `POST` | `/users/invite` `{email, name, role}` |
| `GET PATCH` | `/users/{user}` |
| `POST` | `/users/{user}/suspend` · `/users/{user}/reactivate` |
| `PUT` | `/users/{user}/roles` `{roles: []}` |
| `GET` | `/roles` · `/permissions` |

## Course schemas

| Method | Path | Perm |
|---|---|---|
| `GET` | `/schemas?status=published` | `schema.view` |
| `POST` | `/schemas` | `schema.create` |
| `GET` | `/schemas/{schema}` | |
| `GET` | `/schemas/{schema}/versions` | |
| `POST` | `/schemas/{schema}/versions` | clone latest into a new draft |
| `GET` | `/schema-versions/{v}` | includes `levels[]` |
| `PATCH` | `/schema-versions/{v}` | draft only |
| `POST` | `/schema-versions/{v}/levels` | `{parent_level_id, name, plural_name, min_occurrences, max_occurrences, allows_content, allowed_block_types, allows_assessment, numbering_style, label_template}` |
| `PATCH DELETE` | `/schema-levels/{level}` | draft only |
| `POST` | `/schema-versions/{v}/publish` | `schema.publish` |
| `POST` | `/schema-versions/{v}/archive` | |
| `GET` | `/schema-versions/{v}/usage` | courses bound to it — check before archiving |

## Courses

| Method | Path | Perm |
|---|---|---|
| `GET` | `/courses?state=&subject=&grade=&q=` | scoped by `visibleTo` |
| `POST` | `/courses` `{title, code, subject, grade_band, language, schema_version_id}` | `course.create` |
| `GET` | `/courses/{course}` | metadata + schema levels |
| `PATCH` | `/courses/{course}` | |
| `DELETE` | `/courses/{course}` | soft |
| `GET` | `/courses/{course}/tree?depth=2` | draft tree, authors/reviewers |
| `GET` | `/courses/{course}/allowed-children` | root-level options |
| `GET` | `/courses/{course}/validate` | `Finding[]`, live readiness panel |
| `POST` | `/courses/{course}/submit-review` `{assigned_to, note, due_at}` | |
| `POST` | `/courses/{course}/publish` `{changelog}` | `course.publish` |
| `GET` | `/courses/{course}/publications` | |
| `GET` | `/courses/{course}/publications/latest` | `ETag`, `304` aware |
| `GET` | `/courses/{course}/publications/{n}` | |
| `POST` | `/courses/{course}/publications/{n}/promote` | rollback |
| `GET POST DELETE` | `/courses/{course}/grants` | `course.grant` |

## Nodes & blocks

| Method | Path |
|---|---|
| `GET` | `/nodes/{node}?include=blocks,assessments,comments` |
| `POST` | `/courses/{course}/nodes` `{parent_id, schema_level_id, title, after_node_id}` |
| `PATCH` | `/nodes/{node}` `{title, summary}` |
| `POST` | `/nodes/{node}/move` `{parent_id, after_node_id}` |
| `DELETE` | `/nodes/{node}` |
| `GET` | `/nodes/{node}/allowed-children` |
| `POST` | `/nodes/{node}/blocks` `{type, payload, after_block_id}` |
| `PATCH DELETE` | `/blocks/{block}` |
| `POST` | `/nodes/{node}/blocks/reorder` `{block_ids: []}` |

`after_node_id` / `after_block_id` express intent; the server computes the
`sort_key` between that sibling and the next (`02-§10`). The client never sends
a sort key.

## Media

| Method | Path | Notes |
|---|---|---|
| `POST` | `/media/upload-url` `{filename, mime, size, kind}` | → `{media_id, upload_url, fields}` presigned direct-to-S3 |
| `POST` | `/media/{media}/complete` | server verifies size + checksum, kicks transcode |
| `GET` | `/media/{media}` | `status`, `playback_id`, signed `url` |
| `DELETE` | `/media/{media}` | refuses if referenced by a published snapshot |
| `POST` | `/webhooks/mux` | `status: processing → ready`, sets `duration_s`, `playback_id` |

Never proxy uploads through PHP. A 2 GB lecture video through `php-fpm` will
ruin your afternoon.

## Review

| Method | Path |
|---|---|
| `GET` | `/review-requests?assigned_to=me&state=open` |
| `GET` | `/review-requests/{r}` |
| `POST` | `/review-requests/{r}/approve` `{note}` |
| `POST` | `/review-requests/{r}/request-changes` `{note}` |
| `POST` | `/review-requests/{r}/withdraw` |
| `GET POST` | `/review-requests/{r}/comments` `{body, anchor_type, anchor_id, parent_comment_id}` |
| `PATCH DELETE` | `/comments/{comment}` — `{resolved: true}` |

## Assessments — authoring

| Method | Path |
|---|---|
| `GET POST` | `/question-banks` |
| `GET POST` | `/question-banks/{bank}/questions` |
| `GET PATCH DELETE` | `/questions/{question}` |
| `PUT` | `/questions/{question}/options` full replace |
| `POST` | `/questions/import` CSV / QTI 2.1 |
| `GET POST` | `/courses/{course}/assessments` |
| `GET PATCH DELETE` | `/assessments/{a}` |
| `PUT` | `/assessments/{a}/questions` `{items: [{question_id, points}]}` — ordered |
| `GET` | `/questions/{question}/stats` facility, discrimination |

## Assessments — taking

| Method | Path | Notes |
|---|---|---|
| `POST` | `/assessments/{a}/attempts` | `Idempotency-Key` required |
| `GET` | `/attempts/{id}` | questions in frozen order, key stripped |
| `PUT` | `/attempts/{id}/answers/{aq}` | `{response, client_answered_at}` |
| `POST` | `/attempts/{id}/submit` | |
| `GET` | `/attempts/{id}/result` | score, per-question feedback iff `show_answers` allows |
| `GET` | `/attempts?state=awaiting_review` | graders |
| `PATCH` | `/attempts/{id}/answers/{aq}` | `{points_awarded, grader_note}` |

## Learner delivery

| Method | Path |
|---|---|
| `GET` | `/me/courses` entitled courses + latest publication summary |
| `GET` | `/me/courses/{course}/content` → the snapshot (`04-§5.1`) |
| `GET` | `/me/courses/{course}/progress` |
| `POST` | `/me/progress` `{publication_id, node_id, state, seconds_spent, last_position}` — batch array accepted for offline flush |
| `POST` | `/activity` batch of ≤200 activity events (`12-§3`) |
| `GET` | `/me/attempts` |
| `GET` | `/me/subscriptions` · `POST /me/subscriptions` |

`/me/courses` is `EntitlementResolver::coursesFor($user, $clientCtx)` — the
client context comes from the token's `cid` claim, never from a query parameter.
A course the caller isn't entitled to returns `403 not-entitled` with a `reason`
and a `cta`, never `404` (`11-§7`).

## B2B launch, partner API, and clients

Specified in `10-§12`. Summary: `/lti/login`, `/lti/launch`, `/lti/deep-link`,
`/.well-known/jwks.json`, `/launch` (custom JWT), `/l/{ticket}`,
`/api/v1/auth/launch/exchange`, and the `/partner/v1/*` group behind
client-credentials OAuth and `EnsureClientScope`.

## Search

| Method | Path |
|---|---|
| `GET` | `/search?q=&scope=courses\|nodes\|questions` |

Postgres FTS over `course_nodes.search_vector` plus `pg_trgm` on titles.
Reach for Meilisearch only when FTS visibly fails you — not before.

## Cross-cutting

- `GET /audit-logs?subject_type=&subject_id=` — `audit.view`.
- Rate limits: `120/min` authenticated, `20/min` unauthenticated, `5/min` on login.
- `X-Request-Id` echoed on every response; logged. Correlate Flutter crash
  reports with Laravel logs through it.
- All list endpoints accept `?updated_since=<iso8601>` for delta sync.
