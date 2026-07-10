# B2B Clients & Launch

## 1. Positioning

The LMS is a **content provider's platform**, not a SaaS that schools operate.
Courses are authored once, centrally, by the provider's own staff. Two
audiences consume them:

| Audience | How they arrive | Identity | Access source |
|---|---|---|---|
| **B2B** — ABC School's students & teachers | Click a content link inside their SIS → launched into the LMS | Provisioned just-in-time from the SIS launch, scoped to the client | The client's contract entitlement |
| **B2C** — individual learners | Sign up directly in the Flutter app | Own account, own credentials | Their own subscription or purchase |

### 1.1 "Not multi-tenant" is right about content, and wrong about people

Content is genuinely single-tenant: one catalogue, one author team, no
`tenant_id` on `courses` or `course_nodes`. That is a real simplification and
it removes the entire class of "missing global scope leaks another school's
course" bug.

But the moment ABC School's students exist in your database, you have
per-client data that must not cross:

- ABC School's roster, and the mapping to their internal student IDs.
- Which of ABC's students opened what, when, and how they scored.
- ABC's contract, seat count, and entitled products.

So: **single-tenant content, multi-client identity and activity.** `client_id`
belongs on `client_users`, `launch_sessions`, `activity_events`, and
`client_entitlements` — and nowhere near the content tree. Every table that
carries it gets a scope; every partner-facing query is filtered by the
authenticated client. That is a dozen tables, not fifty, and it is the correct
boundary.

## 2. Entities

```
Client ──┬── ClientKey            (JWKS / public keys, rotatable)
         ├── ClientDeployment     (LTI issuer + deployment_id)
         ├── ClientUser           (external_user_id ⇄ LMS user)
         ├── ClientContext        (a class/section, e.g. "10-B")
         ├── ResourceLink         (the link placed in the SIS → course or node)
         ├── ClientEntitlement    (contract: which products, how many seats)
         └── LaunchSession        (one validated launch, audit + attribution)
```

A **ResourceLink** is the durable object the SIS stores. A **LaunchSession** is
one click on it.

## 3. Launch: use LTI 1.3

Your description — *"SIS shows a content link; student clicks; LMS validates the
request, allows access, records activity against user and client, sends activity
back to the SIS"* — is LTI 1.3 plus LTI Advantage, claim for claim:

| Your requirement | LTI mechanism |
|---|---|
| Teacher places a content link in the class | **Deep Linking 2.0** |
| Student clicks, lands in LMS, authenticated | **Core launch** (OIDC third-party init + signed `id_token`) |
| LMS knows which student, which class, which role | `sub`, `context`, `roles` claims |
| Activity/grades flow back to the SIS | **Assignment & Grade Services (AGS)** |
| Roster sync | **Names & Roles Provisioning (NRPS)** |

Support it. Every SIS worth integrating (PowerSchool, Canvas, Moodle, Blackboard,
Skyward, and most Indian SIS vendors via Moodle) speaks LTI 1.3, and certifying
once means you never negotiate a bespoke protocol again. Use
`packbackbooks/lti-1p3-tool` or `celtic-lti/lti` rather than implementing JWT
validation yourself.

Then offer **Custom JWT launch** (§5) for the SIS vendors who don't. In
practice you will need both, and the custom path should be a thin adapter that
produces the same internal `LaunchSession` — not a second code path all the way
down.

## 4. LTI 1.3 launch flow, end to end

```
 Student in ABC's SIS portal
        │  clicks "Grade 10 English — Chapter 3"
        ▼
 [1] SIS  ──POST /lti/login {iss, login_hint, target_link_uri, lti_message_hint}──►  LMS
        ◄──302 to SIS auth endpoint (state, nonce, client_id, redirect_uri)───────
 [2] Browser follows; SIS authenticates the session it already has
        │
        ▼
 [3] SIS  ──POST /lti/launch {state, id_token}──►  LMS backend
        │      validate: signature (JWKS), iss, aud, exp/iat, nonce, jti,
        │                deployment_id, message_type, version
        │      resolve:  ClientUser (JIT), ClientContext, ResourceLink
        │      check:    ClientEntitlement covers the target course
        │      create:   LaunchSession + one-time LaunchTicket (60 s TTL)
        ▼
 [4] LMS  ──302 https://learn.example.com/l/{ticket}──►  browser
        │        universal link / app link
        ├── app installed  ─► OS opens Flutter app with {ticket}
        └── not installed  ─► Flutter web fallback (same ticket) + install banner
        ▼
 [5] Flutter ──POST /api/v1/auth/launch/exchange {ticket}──► LMS
        ◄── {access_token, refresh_token, launch_context, deep_link}
        │   ticket burned; token carries client_id + launch_session_id
        ▼
 [6] App routes to course/node, plays content, emits activity events
        │
        ▼
 [7] LMS ──► activity outbox ──► ABC's SIS (webhook / AGS / pull)   [see doc 12]
```

### Why a ticket, and not a token, in the redirect URL

Step 4 hands the browser a URL. URLs land in browser history, server access
logs, `Referer` headers, screen recordings, and the clipboard. An access token
there is a compromised access token.

The **LaunchTicket** is opaque, single-use, and expires in 60 seconds. Exchanging
it happens over an authenticated `POST` from the app. Burn it inside a
transaction with `SELECT … FOR UPDATE`, or a double-click on the link mints two
sessions.

```php
DB::transaction(function () use ($hash) {
    $ticket = LaunchTicket::where('token_hash', $hash)
        ->whereNull('used_at')->where('expires_at', '>', now())
        ->lockForUpdate()->firstOr(fn () => throw new InvalidLaunchTicket());
    $ticket->update(['used_at' => now()]);
    return $this->issueTokens($ticket->launchSession);
});
```

Store `token_hash = hash('sha256', $ticket)`, never the ticket itself.

### `id_token` validation checklist

Reject unless **all** hold. Each of these is a real, exploited LTI vulnerability.

- [ ] Signature verifies against a key from the deployment's JWKS, matched on `kid`.
- [ ] `iss` matches a registered `client_deployments.issuer`.
- [ ] `aud` contains our `client_id` at that platform. If `aud` is an array, `azp` must also match.
- [ ] `exp` in the future, `iat` in the past, clock skew ≤ 60 s.
- [ ] `nonce` unseen — `SETNX nonce:{iss}:{nonce}` in Redis, TTL 10 min. **Single use.**
- [ ] `jti` unseen, same treatment.
- [ ] `state` matches the value we set in step 1 (cookie or Redis, bound to the login request).
- [ ] `https://purl.imsglobal.org/spec/lti/claim/deployment_id` is registered for this issuer.
- [ ] `message_type` ∈ `{LtiResourceLinkRequest, LtiDeepLinkingRequest}` and `version = 1.3.0`.

Cache JWKS for 10 minutes; on unknown `kid`, refetch once, then fail. Never
disable signature verification "temporarily for the pilot".

## 5. Custom JWT launch (for SIS that don't speak LTI)

Same guarantees, smaller surface. The client signs a short-lived `RS256` JWT
with a key we hold the public half of.

```jsonc
// POST https://api.example.com/api/v1/launch  (form field: launch_token)
{
  "iss": "abc-school",                       // client slug, registered
  "aud": "https://api.example.com/api/v1/launch",
  "sub": "student-88213",                    // stable external user id — NOT an email
  "jti": "01J9…",                            // single-use
  "iat": 1752130000,
  "exp": 1752130120,                         // ≤ 120 s
  "nonce": "…",
  "name": "R. Sharma",                       // optional
  "email": "r.sharma@abcschool.edu",         // optional, informational only — see §7
  "role": "learner",                         // learner | instructor | client_admin
  "context": { "id": "10-B", "title": "Grade 10 Section B", "type": "class" },
  "resource": { "course_code": "ENG-G10", "node_id": "n2" },   // or "resource_link_id"
  "return_url": "https://sis.abcschool.edu/lms/return"
}
```

Rules, non-negotiable:

- **`RS256`/`ES256` only.** Reject `alg: none` and reject `HS256` outright — a
  symmetric algorithm here means our verification key is also a signing key, and
  a leaked client secret forges any student.
- Pin the algorithm from `client_keys.algorithm`; never read `alg` from the
  token header to choose the verifier.
- `exp - iat ≤ 120 s`. `jti` single-use in Redis for `exp + skew`.
- The token goes in a **POST body**, not a query string.
- Never accept a launch that names a `user_id` in our system. Only
  `(client_id, sub)`.

Key rotation: `client_keys` holds many rows per client with
`status ∈ {active, rotating, revoked}` and `kid`. Verify against any non-revoked
key. Clients with a `jwks_url` get their keys pulled and cached; the rest upload
a PEM through the partner console.

## 6. Just-in-time provisioning

First launch of `(client_id, sub)`:

```php
$clientUser = ClientUser::firstOrCreate(
    ['client_id' => $client->id, 'external_user_id' => $claims->sub],
    [
        'user_id'        => null,      // filled below
        'role'           => $this->mapRole($claims->roles),
        'external_name'  => $claims->name,
        'external_email' => $claims->email,   // stored, not trusted
        'status'         => 'active',
    ],
);

$clientUser->user_id ??= User::create([
    'name'   => $claims->name ?? "Learner {$claims->sub}",
    'email'  => null,                  // ← no email. See §7.
    'status' => 'active',
    'kind'   => 'client_provisioned',
])->id;
```

Role mapping is explicit and per-client, never a pass-through:

```php
// config/lti.php
'role_map' => [
    'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'    => 'learner',
    'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor' => 'instructor',
    'http://purl.imsglobal.org/vocab/lis/v2/institution/person#Administrator' => 'client_admin',
    // anything unmapped → 'learner'
],
```

An unmapped role becomes `learner`. Never the other direction: a client that
starts asserting a role string you didn't anticipate must not thereby mint
administrators.

## 7. Identity linking: the account-takeover trap

**Never auto-link a B2B launch to an existing B2C account by matching email.**

Consider: `r.sharma@abcschool.edu` has a personal B2C subscription. ABC School
is a legitimate client, but their SIS is compromised — or simply careless, or a
rogue teacher edits a student record. The SIS signs a launch with
`sub: "attacker-1", email: "r.sharma@abcschool.edu"`. If you link by email, the
attacker is now inside Sharma's paid account, with their progress, their scores,
their payment history.

Email in a launch token is a **claim by the client about a third party**, not a
verified identity. It has exactly the trust level of the client, which is to say:
enough to display, never enough to authenticate.

The design:

```sql
-- one human, many ways to sign in
user_identities (
    id, user_id,
    provider     text,   -- 'password' | 'google' | 'client:{slug}'
    provider_uid text,   -- email | google sub | external_user_id
    verified_at  timestamptz,
    UNIQUE (provider, provider_uid)
)
```

- A launch creates or reuses a `client:{slug}` identity. It never touches a
  `password` identity.
- Client-provisioned users have `users.email = NULL`. They cannot log in
  directly, cannot reset a password, and cannot be phished into one.
- If a learner *wants* their B2C progress inside the school context, they
  explicitly link: in-app, "Link my personal account", authenticate with their
  B2C password (and 2FA if set), then confirm. The link is user-initiated,
  authenticated on both sides, revocable, and audited.
- Unlinking on request; deleting the client identity leaves the B2C account intact.

The same rule protects the other direction: a B2C signup with
`r.sharma@abcschool.edu` must not inherit ABC School's entitlements.

## 8. Sessions and tokens

The exchanged access token is **client-scoped**:

```jsonc
{ "sub": "<lms_user_id>", "cid": "<client_id>", "ls": "<launch_session_id>",
  "abilities": ["attempt.take", "progress.view.own"], "exp": … }
```

- `cid` is the attribution key for every activity event the session produces (doc 12).
- Entitlement is re-resolved on token refresh, not just at launch. A student
  removed from the roster on Monday loses access Monday, not in 30 days.
- Refresh token: 30 days for B2B (school devices, offline packs), 90 for B2C.
- A B2C user has no `cid`. Their events are attributed to no client.
- **A user with both a B2C session and an ABC session has two tokens and two
  contexts.** Events attribute to whichever session produced them. This is what
  makes reporting to ABC defensible: you are only ever sending them activity
  that happened inside their context.

Revocation: `POST /partner/v1/users/{external_user_id}/revoke` kills every live
session for that `client_user`, and roster-sync deactivation does the same.

## 9. Deep links and the mobile handoff

`ResourceLink` maps the SIS-side link to LMS content:

```sql
resource_links (
    id, client_id, client_context_id,
    external_resource_link_id text,        -- LTI resource_link.id
    course_id uuid NOT NULL,
    course_node_id uuid,                   -- null ⇒ course root
    lineitem_url text,                     -- AGS grade passback target
    created_at,
    UNIQUE (client_id, external_resource_link_id)
)
```

Deep Linking flow (teacher, once, at setup): SIS sends an
`LtiDeepLinkingRequest` → LMS renders a content picker (Flutter web, in the
SIS's iframe) → teacher picks "Chapter 3" → LMS returns a signed
`LtiDeepLinkingResponse` containing the `resource_link` → SIS stores it. Every
later student click is a plain `LtiResourceLinkRequest`.

### The app-installed problem

Universal Links (iOS) / App Links (Android) on `https://learn.example.com/l/*`.

- **Installed** → OS opens the app; the app exchanges the ticket.
- **Not installed** → the URL resolves as a real web page. Serve the Flutter web
  build, which exchanges the same ticket and renders the content. Show an
  install banner; do not gate the content behind installing an app. A student on
  a school Chromebook cannot install your APK.
- **Deferred deep linking** (install, then land on the right chapter) needs the
  ticket to survive the store round-trip — but the ticket expires in 60 s. Don't
  try. After install, the app opens cold; the student clicks the SIS link again.
  This is fine, and it is what every LTI tool does.

### The iframe problem

Most SIS launch tools in an iframe. Flutter web in a cross-origin iframe means
your session cookie needs `SameSite=None; Secure`, and Safari's ITP may drop it
anyway. Two mitigations, ship both:

1. Token in memory, obtained from the ticket exchange — no cookie needed for the
   API. This is why the ticket exchange returns a bearer token rather than
   setting a session.
2. If you must set a cookie, do the Storage Access API dance, and fall back to a
   "Open in new tab" interstitial.

## 10. Schema

```sql
CREATE TABLE clients (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name           text NOT NULL,
    slug           citext NOT NULL UNIQUE,
    status         text NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','active','suspended','terminated')),
    integration    text NOT NULL DEFAULT 'none'
                   CHECK (integration IN ('none','lti_1_3','custom_jwt')),
    contact_email  citext,
    settings       jsonb NOT NULL DEFAULT '{}'::jsonb,
    -- settings: { report_webhook_url, report_formats[], allowed_redirect_hosts[],
    --             pii_level: 'pseudonymous'|'named', timezone }
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE client_keys (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id   uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    kid         text NOT NULL,
    algorithm   text NOT NULL CHECK (algorithm IN ('RS256','ES256')),
    public_key  text,                    -- PEM; null when jwks_url is used
    jwks_url    text,
    status      text NOT NULL DEFAULT 'active'
                CHECK (status IN ('active','rotating','revoked')),
    not_before  timestamptz,
    expires_at  timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (client_id, kid),
    CHECK (public_key IS NOT NULL OR jwks_url IS NOT NULL)
);

CREATE TABLE client_deployments (            -- LTI only
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id           uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    issuer              text NOT NULL,
    deployment_id       text NOT NULL,
    platform_client_id  text NOT NULL,       -- our client_id AT the platform
    auth_login_url      text NOT NULL,
    auth_token_url      text NOT NULL,
    jwks_url            text NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (issuer, deployment_id, platform_client_id)
);

CREATE TABLE client_users (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id        uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    external_user_id text NOT NULL,
    user_id          uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    role             text NOT NULL DEFAULT 'learner'
                     CHECK (role IN ('learner','instructor','client_admin')),
    external_name    text,
    external_email   citext,               -- informational only (§7)
    status           text NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active','deactivated')),
    first_seen_at    timestamptz NOT NULL DEFAULT now(),
    last_seen_at     timestamptz,
    UNIQUE (client_id, external_user_id)
);
CREATE INDEX ON client_users (user_id);
CREATE INDEX ON client_users (client_id, status);

CREATE TABLE client_contexts (               -- a class / section
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id           uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    external_context_id text NOT NULL,
    title               text,
    type                text,                -- 'class' | 'course_section' | 'group'
    UNIQUE (client_id, external_context_id)
);

CREATE TABLE client_context_members (
    client_context_id uuid NOT NULL REFERENCES client_contexts(id) ON DELETE CASCADE,
    client_user_id    uuid NOT NULL REFERENCES client_users(id) ON DELETE CASCADE,
    role              text NOT NULL,
    PRIMARY KEY (client_context_id, client_user_id)
);

CREATE TABLE resource_links (
    id                       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id                uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    client_context_id        uuid REFERENCES client_contexts(id) ON DELETE SET NULL,
    external_resource_link_id text NOT NULL,
    course_id                uuid NOT NULL REFERENCES courses(id),
    course_node_id           uuid REFERENCES course_nodes(id) ON DELETE SET NULL,
    lineitem_url             text,
    created_at               timestamptz NOT NULL DEFAULT now(),
    UNIQUE (client_id, external_resource_link_id)
);

CREATE TABLE launch_sessions (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id         uuid NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    client_user_id    uuid NOT NULL REFERENCES client_users(id) ON DELETE CASCADE,
    resource_link_id  uuid REFERENCES resource_links(id) ON DELETE SET NULL,
    client_context_id uuid REFERENCES client_contexts(id) ON DELETE SET NULL,
    message_type      text NOT NULL,
    jti               text NOT NULL,
    nonce             text NOT NULL,
    ip                inet,
    user_agent        text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    exchanged_at      timestamptz,
    expires_at        timestamptz NOT NULL,
    UNIQUE (client_id, jti)                  -- replay defence, durable
);
CREATE INDEX ON launch_sessions (client_user_id, created_at DESC);

CREATE TABLE launch_tickets (
    token_hash        text PRIMARY KEY,      -- sha256(ticket)
    launch_session_id uuid NOT NULL REFERENCES launch_sessions(id) ON DELETE CASCADE,
    expires_at        timestamptz NOT NULL,
    used_at           timestamptz
);
```

`launch_sessions.jti` uniqueness gives durable replay protection even if Redis
is flushed. Keep both — Redis for speed, Postgres for truth. Prune rows older
than 90 days.

## 11. Partner API authentication

The SIS also talks to us server-to-server (roster sync, pull reporting,
entitlement checks). That is **not** the same credential as the launch key.

- OAuth 2.0 client-credentials: `POST /partner/v1/oauth/token` with a
  `client_assertion` JWT (private-key JWT, `RS256`) — no shared secrets.
- Scopes: `roster:read`, `roster:write`, `activity:read`, `entitlement:read`.
- Access tokens live 1 hour. Rate limit 600/min per client.
- Every partner endpoint filters by the authenticated `client_id`. Write one
  `EnsureClientScope` middleware, apply it to the whole `partner` route group,
  and add a test that fails if any route in that group lacks it.

## 12. Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET POST` | `/lti/login` | OIDC third-party init |
| `POST` | `/lti/launch` | `id_token` validation → ticket |
| `POST` | `/lti/deep-link` | Deep Linking response |
| `GET` | `/.well-known/jwks.json` | our public keys (AGS / DL signing) |
| `POST` | `/launch` | custom-JWT launch → ticket |
| `GET` | `/l/{ticket}` | universal link target (app or web) |
| `POST` | `/api/v1/auth/launch/exchange` | ticket → tokens |
| `POST` | `/partner/v1/oauth/token` | client credentials |
| `GET PUT` | `/partner/v1/users` | roster upsert / list |
| `POST` | `/partner/v1/users/{external_user_id}/revoke` | kill sessions |
| `GET` | `/partner/v1/entitlements` | what this client's contract covers |
| `GET` | `/partner/v1/activity` | pull reporting (doc 12) |
| `GET` | `/partner/v1/catalog` | courses available to link |

Admin-side (internal): `/api/v1/clients` CRUD, key upload/rotation, entitlement
management, delivery health, launch failure log. Build the launch failure log
early — "the launch doesn't work" is 80% of B2B support, and the answer is
almost always a clock skew, a stale `kid`, or an unregistered `deployment_id`.
Show the client's admin the exact rejected claim.
