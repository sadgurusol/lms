# Entitlements, Products & Billing

## 1. One question, one answer

Both audiences reduce to a single question the API must answer on every content
request:

> **May this user, in this session context, read this course right now?**

Answering it in two places — one branch for B2B, one for B2C — guarantees they
drift, and the drift is a paid-content leak. There is exactly one resolver.

```php
final class EntitlementResolver
{
    public function grantFor(User $user, Course $course, ?Client $client): ?Grant;
    public function coursesFor(User $user, ?Client $client): Collection;  // catalogue view
}
```

`$client` comes from the session's `cid` claim (doc 10 §8), never from a request
parameter. A B2C session passes `null`.

## 2. Products sit between contracts and courses

Never entitle a client directly to a course. Entitling ABC School to 40 courses
is 40 rows, no price, and a support ticket every time you add course 41.

```
Product ──< product_courses >── Course
   ▲
   ├── ClientEntitlement   (ABC School, 500 seats, 2026-04-01 → 2027-03-31)
   └── Plan → Subscription (B2C, ₹499/mo)
```

A **Product** is the sellable unit: a single course (`ENG-G10`), a bundle
(`Grade 10 Complete`), or a catalogue (`All Competitive Exams`). Adding a course
to a bundle grants it to every client and subscriber holding that bundle —
which is what you want, and is also why bundle membership changes need an audit
trail and an announcement.

```sql
CREATE TABLE products (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    sku        citext NOT NULL UNIQUE,
    name       text NOT NULL,
    kind       text NOT NULL CHECK (kind IN ('course','bundle','catalog')),
    status     text NOT NULL DEFAULT 'draft'
               CHECK (status IN ('draft','active','retired')),
    metadata   jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE product_courses (
    product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    course_id  uuid NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
    added_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (product_id, course_id)
);
```

`ON DELETE RESTRICT` on `course_id`: you may not delete a course somebody paid
for. Archive it.

## 3. B2B: client entitlements and seats

```sql
CREATE TABLE client_entitlements (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id   uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    product_id  uuid NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    seat_model  text NOT NULL DEFAULT 'active'
                CHECK (seat_model IN ('assigned','active','unlimited')),
    seat_limit  int CHECK (seat_limit IS NULL OR seat_limit > 0),
    starts_at   timestamptz NOT NULL,
    ends_at     timestamptz,
    status      text NOT NULL DEFAULT 'active'
                CHECK (status IN ('active','suspended','expired')),
    contract_ref text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (client_id, product_id, starts_at),
    CHECK (seat_model = 'unlimited' OR seat_limit IS NOT NULL),
    CHECK (ends_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX ON client_entitlements (client_id, status);
```

Three seat models, because schools ask for different ones and you cannot talk
them out of it:

| `seat_model` | Meaning | Counted by |
|---|---|---|
| `assigned` | Named students, explicitly allocated | rows in `client_seat_assignments` |
| `active` | Any student who opened content this period | distinct `client_user_id` in `activity_events` this billing period |
| `unlimited` | Site licence | — |

```sql
CREATE TABLE client_seat_assignments (
    client_entitlement_id uuid NOT NULL REFERENCES client_entitlements(id) ON DELETE CASCADE,
    client_user_id        uuid NOT NULL REFERENCES client_users(id) ON DELETE CASCADE,
    assigned_at           timestamptz NOT NULL DEFAULT now(),
    released_at           timestamptz,
    PRIMARY KEY (client_entitlement_id, client_user_id)
);
```

**Never hard-block a learner mid-term for a seat overage.** A student locked out
of their coursework because the school under-purchased is a support escalation
and a reputational problem, and the school will pay anyway. Instead: allow the
launch, mark `activity_events.over_seat = true`, alert the account manager, and
surface the overage in the client console. Soft-enforce and invoice; hard-enforce
only after `ends_at` passes.

The `assigned` model *does* hard-block on assignment — but assignment is an
explicit administrative act, so the error message can be honest ("Ask your
school to assign you a seat") and points at someone who can fix it.

## 4. B2C: plans and subscriptions

```sql
CREATE TABLE plans (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code          citext NOT NULL UNIQUE,
    name          text NOT NULL,
    product_id    uuid NOT NULL REFERENCES products(id),
    price_minor   int NOT NULL,               -- paise / cents. Integer. Always.
    currency      char(3) NOT NULL,
    interval      text NOT NULL CHECK (interval IN ('month','year','one_time')),
    trial_days    int NOT NULL DEFAULT 0,
    provider_ref  text,                       -- Razorpay plan id
    status        text NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active','retired'))
);

CREATE TABLE subscriptions (
    id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id              uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plan_id              uuid NOT NULL REFERENCES plans(id),
    provider             text NOT NULL,       -- 'razorpay' | 'stripe' | 'apple' | 'google'
    provider_sub_id      text,
    status               text NOT NULL
                         CHECK (status IN ('trialing','active','past_due','canceled','expired')),
    current_period_start timestamptz,
    current_period_end   timestamptz,
    cancel_at            timestamptz,
    canceled_at          timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (provider, provider_sub_id)
);
CREATE INDEX ON subscriptions (user_id, status);

CREATE TABLE purchases (                       -- one-time buys
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id  uuid NOT NULL REFERENCES products(id),
    amount_minor int NOT NULL,
    currency    char(3) NOT NULL,
    provider    text NOT NULL,
    provider_ref text,
    refunded_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);
```

Money is `int` minor units. A `float` price will, eventually and unpredictably,
charge someone ₹498.99999997.

### 4.1 The store-billing tax

If the Flutter app sells subscriptions **inside** the iOS/Android app, Apple and
Google require their IAP and take 15–30%. There is no negotiating this for
digital content consumed in-app.

Consequences you must design for now, not later:

- `subscriptions.provider` includes `apple` and `google`, and their receipt
  verification is server-side (App Store Server API / Google Play Developer API)
  with **server notifications** as the source of truth. Never trust a receipt the
  client hands you.
- Renewal, refund, upgrade, grace-period, and billing-retry state all arrive as
  webhooks from three different providers with three different vocabularies.
  Normalise into `subscriptions.status` at the edge; the resolver must never
  learn what a `DID_CHANGE_RENEWAL_STATUS` is.
- A subscription bought on the web (Razorpay) must unlock the mobile app, and
  vice versa. Entitlement lives on the `user`, not on the platform.
- Reader-app rules let you *not* offer IAP if you never link to your own purchase
  flow from inside the app — this is the route most content businesses take.
  Decide before you build the paywall screen.

### 4.2 Webhook handling

Idempotent, replayable, verified:

- Verify the provider signature. Razorpay: `X-Razorpay-Signature` HMAC over the
  raw body — capture the **raw** body before Laravel's JSON middleware touches it.
- Store `provider_event_id` with a unique index; a duplicate delivery is a no-op.
- Process asynchronously: persist the event, `202`, queue the handler. Providers
  time out at 5–10 s and retry, and a slow entitlement rebuild will be
  interpreted as failure.
- Order is not guaranteed. Compare the event's timestamp against
  `subscriptions.updated_at` and drop stale transitions rather than letting a
  late `payment.failed` override a fresh `payment.captured`.

## 5. The resolver

```php
public function grantFor(User $user, Course $course, ?Client $client): ?Grant
{
    foreach ($this->productsCovering($course) as $productId) {

        if ($client !== null) {
            $ce = $this->activeClientEntitlement($client, $productId);
            if ($ce && $this->isActiveMember($user, $client)
                    && $this->seatAllows($ce, $user)) {
                return new Grant(source: 'client', clientId: $client->id,
                                 entitlementId: $ce->id, expiresAt: $ce->ends_at);
            }
        }

        if ($sub = $this->activeSubscription($user, $productId)) {
            return new Grant(source: 'subscription', subscriptionId: $sub->id,
                             expiresAt: $sub->current_period_end);
        }

        if ($p = $this->purchase($user, $productId)) {
            return new Grant(source: 'purchase', purchaseId: $p->id, expiresAt: null);
        }

        if ($g = $this->compGrant($user, $productId)) {           // staff, trials, reviewers
            return new Grant(source: 'grant', expiresAt: $g->ends_at);
        }
    }

    return null;   // → 403 with a payment/contact-your-school CTA, never 404
}
```

Ordering matters: **client entitlement is checked first**. A student launching
from ABC School reads the course under ABC's contract, and the resulting
activity is reported to ABC — even if that student also happens to hold a
personal subscription. Attribution follows the session context, not the cheapest
grant. Get this backwards and your reporting silently omits students.

`Grant` is threaded into the request and stamped onto every activity event
(`source`, `client_id`, `entitlement_id`). Reporting and revenue attribution then
fall out of the event stream for free.

### 5.1 Caching and invalidation

Entitlements change rarely and are read on every request.

```
key:  ent:{user_id}:{client_id|'b2c'}
ttl:  5 minutes
value: { course_ids: [...], grants: {...}, computed_at }
```

Bust on: subscription webhook, `client_entitlements` write, `client_users.status`
change, seat assignment, product/bundle membership change, comp grant change.
A 5-minute TTL bounds the damage from a missed bust; do not raise it to an hour
because a dashboard looked slow.

**Re-resolve on token refresh** (doc 10 §8), not only at launch. Otherwise a
student removed from the roster keeps a valid token for its full lifetime.

### 5.2 Content already downloaded

Offline packs (doc 07 §5) are the sharp edge. A learner downloads a course, the
subscription lapses, and the content is on their device.

- Access tokens expire; refresh fails; the app **must** treat "refresh returned
  403 entitlement_revoked" as a signal to delete the pack, not merely to hide it.
- Do not pretend this is DRM. A determined user roots the device and keeps the
  MP4. Cost the leakage in, and use short-lived signed playback URLs so the
  *streaming* path is not an open CDN.
- Give an offline grace window (e.g. 14 days without a successful refresh) so a
  student on a school bus is not locked out. Make the window a per-product
  setting; competitive-exam content will want it shorter.

## 6. Comp grants

```sql
CREATE TABLE comp_grants (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    reason     text NOT NULL,          -- 'staff' | 'reviewer' | 'trial' | 'support'
    granted_by uuid REFERENCES users(id),
    starts_at  timestamptz NOT NULL DEFAULT now(),
    ends_at    timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
```

Your own content reviewers need to read published courses without a
subscription. Support needs to reproduce a learner's bug. Give them a comp grant
with a reason and an expiry, audited — rather than the alternative that always
emerges otherwise, which is a hidden `if ($user->email endsWith '@example.com')`
in the resolver.

## 7. What a 403 says

Never `404` a course the user isn't entitled to — it makes "does this exist?"
indistinguishable from "may I read it?", and support cannot triage it.

```jsonc
{ "type": "https://lms.dev/errors/not-entitled", "status": 403,
  "title": "You don't have access to this course",
  "reason": "no_client_entitlement",         // | subscription_expired | seat_not_assigned
                                             // | client_contract_expired | client_suspended
  "cta": { "kind": "contact_client", "client_name": "ABC School" } }
```

The `cta` is what the Flutter app renders. A B2C learner gets a paywall. A B2B
learner gets "Ask your school" — showing them a paywall for content their school
was supposed to buy is a bad day for everyone.

## 8. Client console

Ship a minimal web console for `client_admin` users, or you become their
reporting department:

- Seats: used vs purchased, trend, overage warning.
- Roster: who's active, who's never launched.
- Entitlements: products, dates, contract reference.
- Integration health: last launch, launch failures with the rejected claim
  (doc 10 §12), webhook delivery status (doc 12).
- Activity export: CSV, on demand.

This is three screens and it removes most of the B2B support load.
