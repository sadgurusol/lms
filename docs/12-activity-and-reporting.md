# Activity Logging & Reporting Back to the SIS

## 1. Two logs, different jobs

| | `audit_logs` | `activity_events` |
|---|---|---|
| Records | Administrative acts | Learning acts |
| Actors | Admins, authors, reviewers | Learners, instructors |
| Examples | `course.published`, `role.assigned`, `client_key.rotated` | `content.viewed`, `attempt.submitted` |
| Read by | Internal compliance | Clients, learners, analytics |
| Retention | 7 years | Per contract, typically 2 |
| Volume | thousands/month | millions/month |

Do not merge them. They have different volumes, different privacy exposure, and
different consumers, and the merged table pleases neither.

## 2. Event model

Every event is attributed to exactly **one** context: a client, or nobody (B2C).
Attribution comes from the session (`cid` in the token, doc 10 §8) and the
`Grant` (doc 11 §5), never from a request parameter.

```sql
CREATE TABLE activity_events (
    id                uuid PRIMARY KEY,          -- UUIDv7, client-generated, idempotency key
    occurred_at       timestamptz NOT NULL,      -- when it happened on the device
    received_at       timestamptz NOT NULL DEFAULT now(),
    user_id           uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id         uuid REFERENCES clients(id) ON DELETE CASCADE,   -- null ⇒ B2C
    client_user_id    uuid REFERENCES client_users(id) ON DELETE CASCADE,
    client_context_id uuid REFERENCES client_contexts(id) ON DELETE SET NULL,
    launch_session_id uuid REFERENCES launch_sessions(id) ON DELETE SET NULL,
    resource_link_id  uuid REFERENCES resource_links(id) ON DELETE SET NULL,

    verb              text NOT NULL,
    course_id         uuid NOT NULL REFERENCES courses(id),
    publication_id    uuid NOT NULL REFERENCES course_publications(id),
    course_node_id    uuid,
    assessment_id     uuid,
    attempt_id        uuid,

    grant_source      text,                       -- 'client'|'subscription'|'purchase'|'grant'
    over_seat         boolean NOT NULL DEFAULT false,
    payload           jsonb NOT NULL DEFAULT '{}'::jsonb,

    device            jsonb NOT NULL DEFAULT '{}'::jsonb   -- {platform, app_version, offline}
) PARTITION BY RANGE (occurred_at);

CREATE INDEX ON activity_events (client_id, occurred_at DESC);
CREATE INDEX ON activity_events (user_id, occurred_at DESC);
CREATE INDEX ON activity_events (course_id, verb, occurred_at DESC);
```

Monthly partitions, created ahead by a scheduled command, detached and archived
to Parquet on S3 after the retention window.

### Verbs

| Verb | `payload` |
|---|---|
| `session.launched` | `{ resource_link_id, context_id }` |
| `content.viewed` | `{ node_id, seconds_visible }` |
| `content.progressed` | `{ node_id, percent, seconds_spent }` |
| `content.completed` | `{ node_id }` |
| `video.watched` | `{ media_id, from_s, to_s, watched_s }` |
| `course.completed` | `{ percent, completed_nodes, total_nodes }` |
| `attempt.started` | `{ assessment_id, attempt_id, attempt_number }` |
| `attempt.submitted` | `{ attempt_id, duration_s, auto_submitted }` |
| `attempt.graded` | `{ attempt_id, score, max_score, percent, passed }` |

`id` is generated **on the client** as a UUIDv7 and used as the idempotency key.
The offline outbox (doc 07 §5.3) replays on reconnect; `INSERT … ON CONFLICT
(id) DO NOTHING` makes replay free. `occurred_at` is the device clock, clamped
server-side to `[received_at - 30d, received_at + 5m]` — phones have wrong
clocks, and you cannot let one write an event into next year.

## 3. Ingestion

```
POST /api/v1/activity   { events: [ …up to 200… ] }
```

- Authenticated learner session. `client_id`, `client_user_id`,
  `launch_session_id`, and `grant_source` are **stamped server-side from the
  token**. A client-supplied `client_id` is ignored, not honoured.
- Validate every `course_id` against the caller's resolved entitlement. A learner
  cannot log activity against a course they cannot read.
- `202 Accepted` with per-event `{id, status}` so the app's outbox can drain
  precisely. Partial success is normal; one bad event must not reject the batch.
- Write to Postgres, then fan out to the per-client outbox in the same
  transaction.

## 4. Delivery to the client

Ship all three. Every SIS supports exactly one of them, and never the one you
guessed.

### 4.1 Webhook push (default)

```sql
CREATE TABLE client_event_outbox (
    client_id    uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    sequence     bigint NOT NULL,             -- per-client, gapless, monotonic
    event_id     uuid NOT NULL REFERENCES activity_events(id) ON DELETE CASCADE,
    delivered_at timestamptz,
    attempts     int NOT NULL DEFAULT 0,
    last_error   text,
    next_attempt_at timestamptz,
    PRIMARY KEY (client_id, sequence)
);
CREATE INDEX ON client_event_outbox (client_id, next_attempt_at)
    WHERE delivered_at IS NULL;
```

Assign `sequence` from a per-client Postgres sequence inside the ingest
transaction. A **gapless, monotonic sequence per client** is the single feature
that makes SIS-side reconciliation possible: they can detect a missed batch by
noticing `sequence` jumped, and ask for the gap.

Delivery: batches of ≤ 100, ordered by `sequence`, at-least-once, **in order**
(one in-flight batch per client — a serialised queue, `WithoutOverlapping` keyed
on `client_id`).

```http
POST https://sis.abcschool.edu/hooks/lms
Content-Type: application/json
X-LMS-Delivery-Id: 01J9...
X-LMS-Signature: t=1752130000,v1=5f2b...
X-LMS-Sequence-Range: 41200-41299
```

`v1 = HMAC-SHA256(secret, "{t}.{raw_body}")`. The client rejects if
`|now - t| > 300s`, which is what stops a captured request being replayed at
leisure. Publish this verification snippet in the partner docs; nobody
implements it correctly from prose.

Retries: `1m, 5m, 15m, 1h, 6h, 24h`, then park the client's stream and alert.
**Do not skip a failing batch** — ordering and gaplessness are the contract. A
parked stream is resumed by the partner console.

### 4.2 Pull (for SIS behind a firewall — most of them)

```
GET /partner/v1/activity?since_sequence=41199&limit=500
→ { data: [...], meta: { last_sequence: 41699, has_more: true } }
```

Cursor on `sequence`, not on timestamp. Timestamps collide, go backwards under
clock skew, and make "did I already fetch this?" unanswerable. Keep 90 days
retrievable.

### 4.3 Bulk drop

Nightly Parquet/CSV to the client's S3 bucket or SFTP, partitioned by date.
Unglamorous, universally supported, and it is what their data team actually
wants.

## 5. Standards-shaped output

Same events, different envelopes. Implement as serialisers over
`activity_events`, selected by `clients.settings.report_formats`.

### 5.1 LTI AGS — grade passback

This is what the SIS actually cares about: the score in the gradebook.

When `attempt.graded` fires and the originating `resource_link` has a
`lineitem_url`:

1. Fetch an access token from the platform: `client_credentials` +
   `client_assertion` (private-key JWT signed with our key from
   `/.well-known/jwks.json`), scope
   `https://purl.imsglobal.org/spec/lti-ags/scope/score`.
2. `POST {lineitem_url}/scores` with
   `Content-Type: application/vnd.ims.lis.v1.score+json`:

```jsonc
{ "userId": "student-88213",
  "scoreGiven": 17, "scoreMaximum": 20,
  "activityProgress": "Completed", "gradingProgress": "FullyGraded",
  "timestamp": "2026-07-10T09:14:00.000Z" }
```

`timestamp` must be strictly increasing per `(lineitem, user)` or the platform
silently discards the score. Store the last sent timestamp; on a regrade, bump it.

For assessments in `awaiting_review` (essay questions), send
`gradingProgress: "PendingManual"` on submit and the real score after grading.
The teacher then sees "submitted, awaiting grading" rather than a zero.

### 5.2 xAPI (Tin Can)

For clients with an LRS. Map verb → IRI:

```jsonc
{ "actor":  { "account": { "homePage": "https://abcschool.edu",
                           "name": "student-88213" } },
  "verb":   { "id": "http://adlnet.gov/expapi/verbs/completed",
              "display": { "en-US": "completed" } },
  "object": { "id": "https://lms.example.com/courses/eng-g10/nodes/n3",
              "definition": { "type": "http://adlnet.gov/expapi/activities/module",
                              "name": { "en": "Simple Past" } } },
  "result": { "completion": true, "duration": "PT6M52S" },
  "context": { "registration": "<launch_session_id>",
               "contextActivities": { "parent": [{ "id": ".../courses/eng-g10" }] } },
  "timestamp": "2026-07-10T09:14:00Z", "id": "<event_id>" }
```

`actor.account` — pseudonymous, keyed to the client's own ID. Never `mbox`. See §6.

### 5.3 Caliper 1.2

Same shape, IMS envelope. Add it only when a client demands it; the mapping is
mechanical once §2 exists.

## 6. Privacy

This is student data, frequently minors' data. The design rule:

> **The client sends us the minimum PII to identify their own user, and we send
> back only what happened inside their own context.**

- `external_user_id` is a pseudonymous, stable, opaque string. Ask clients for
  the internal SIS key, never an email or a government ID. `clients.settings.pii_level`
  defaults to `pseudonymous`; `named` is opt-in and contractual.
- Reports contain `external_user_id` — the client re-identifies from their own
  system. We never echo back a name we were never supposed to store.
- **Never report a user's B2C activity to a client.** The partition is
  `activity_events.client_id`, and every partner query filters on it. Test that
  a linked account (doc 10 §7) whose personal subscription activity exists in the
  same `user_id` does not leak into ABC School's feed. This is the single test
  most worth writing in the whole reporting subsystem.
- India's **DPDP Act 2023**: for users under 18 the client is responsible for
  verifiable parental consent — put it in the DPA, record the client's assertion
  per `client_user`, and prohibit behavioural advertising outright (you have
  none; keep it that way). You are a **Data Processor** for B2B activity and a
  **Data Fiduciary** for B2C. Those are different obligations on the same table;
  `client_id IS NULL` is the discriminator.
- Data subject requests: `DELETE /partner/v1/users/{external_user_id}` must erase
  or irreversibly pseudonymise that `client_user`'s events. Partitioned tables
  make this an `UPDATE … SET user_id = <tombstone>` over recent partitions plus
  a rewrite of archived Parquet — budget for it, and cap retention so the
  rewrite is bounded.
- Contract termination: export everything, then purge on a schedule stated in
  the contract. `clients.status = 'terminated'` stops ingest and delivery
  immediately.

## 7. Operational surface

- **Delivery health per client**: lag (`max(sequence) - max(delivered sequence)`),
  failure rate, last success. Alert on lag > 10k or age > 1 h.
- **Launch failure log** (doc 10 §12), joined to delivery health, is the entire
  B2B support console.
- Replay: `POST /admin/clients/{c}/outbox/replay?from_sequence=41200` — after the
  client fixes their endpoint, they need the gap. At-least-once means their
  handler is idempotent on `event_id`; say so loudly in the partner docs.
- Load: a 500-student school generates roughly 200k events/month. Ten schools is
  nothing. A thousand is 200M rows/month and you want the partitions, the Parquet
  archive, and a `daily_activity_rollup` materialised view feeding the dashboards
  rather than the raw table.

```sql
CREATE MATERIALIZED VIEW daily_activity_rollup AS
SELECT date_trunc('day', occurred_at) AS day,
       client_id, course_id, verb,
       count(*)                         AS events,
       count(DISTINCT user_id)          AS users,
       sum((payload->>'seconds_spent')::int) FILTER (WHERE verb = 'content.progressed') AS seconds
  FROM activity_events
 GROUP BY 1, 2, 3, 4;
```
