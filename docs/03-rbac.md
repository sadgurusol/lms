# Roles, Permissions & Authorization

## 1. Three axes

Authorization answers three different questions, and one flat role list answers
none of them:

| Axis | Question | Mechanism |
|---|---|---|
| **Global role** | What kind of thing may you do at all? | `spatie/laravel-permission` |
| **Course grant** | On which courses may you *author or review*? | `course_grants` |
| **Entitlement** | Which courses may you *read*, under whose contract? | `EntitlementResolver` (doc 11) |

```
mayAuthor(user, action, course) =
      user.hasRole('admin')
   OR (user.hasPermissionTo(action) AND user.hasGrantOn(course, requiredGrant(action)))

mayRead(user, course, clientCtx) =
      resolver.grantFor(user, course, clientCtx) !== null
```

Authoring authority is **staff-side**: an Admin needs no grants; a Content
Author with zero grants can create courses (becoming `owner` of what they
create) but cannot touch anyone else's; a Reviewer sees only courses assigned
to them.

Reading authority is **commercial**: it comes from a contract or a
subscription, never from a role. A `learner` role permits nothing on its own —
`attempt.take` on a course you aren't entitled to is still a 403. Keeping these
separate is what lets the same `Learner` role serve a B2B student launched from
ABC School and a B2C subscriber.

## 2. Global roles

### Staff roles (the content provider's own people)

| Role | Intent |
|---|---|
| `admin` | Full control: users, roles, schemas, all courses, publishing, clients, products, config. |
| `content_author` | Create and edit courses they own or are granted on. Submit for review. Cannot approve or publish. |
| `content_reviewer` | Read granted courses in full, comment, approve / request changes. Cannot edit content. |
| `ops` | Manage clients, keys, entitlements, delivery health. No content authority. |

### Consumer roles

| Role | Intent |
|---|---|
| `learner` | Read **published** content they are entitled to. Take assessments. Track progress. |
| `instructor` | Everything a learner can, plus: see their context's learners' progress, grade essay answers, create deep links. |

> **`viewer` is renamed `learner`.** "Viewer" implies read-only browsing. The
> actual entity has entitlements, attempts, progress, a guardian, and a right of
> erasure. Naming it correctly on day one saves a rename across 40 files later.

Roles are additive — a user may be both `content_author` and
`content_reviewer` — but see §5 on separation of duties.

### Client membership roles

Orthogonal to the above, and scoped to one client (doc 10 §10):

`client_users.role ∈ { learner, instructor, client_admin }`

`client_admin` is not an LMS admin. It grants access to the client console
(seats, roster, delivery health, activity export) for **their own client only**
— never to content authoring, and never to another client's data.

## 3. Permission catalogue

Seed these into `spatie/laravel-permission`. Naming: `subject.verb`.

```
user.view          user.invite        user.update       user.suspend
role.assign

schema.view        schema.create      schema.update     schema.publish
schema.archive

course.view.any    course.view.granted course.create    course.update
course.delete      course.archive
course.submit_review
course.review                     # comment + approve/request changes
course.publish
course.grant                      # manage course_grants

node.create        node.update       node.delete       node.move
block.create       block.update      block.delete

assessment.manage  question.manage
attempt.take       attempt.grade     attempt.view.any

media.upload       media.delete

progress.view.own  progress.view.context   progress.view.any
audit.view

client.view        client.manage     client.key.rotate
product.view       product.manage
entitlement.view   entitlement.manage
delivery.view      delivery.replay
deeplink.create
```

### Role → permission mapping

| Permission | admin | ops | author | reviewer | instructor | learner |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| `user.*`, `role.assign` | ✅ | — | — | — | — | — |
| `schema.view` | ✅ | — | ✅ | ✅ | — | — |
| `schema.create/update/publish/archive` | ✅ | — | — | — | — | — |
| `course.view.any` | ✅ | — | — | — | — | — |
| `course.view.granted` | ✅ | — | ✅ | ✅ | — | — |
| `course.create` | ✅ | — | ✅ | — | — | — |
| `course.update` / `node.*` / `block.*` | ✅ | — | ✅ | — | — | — |
| `course.submit_review` | ✅ | — | ✅ | — | — | — |
| `course.review` | ✅ | — | — | ✅ | — | — |
| `course.publish` | ✅ | — | — | — | — | — |
| `course.grant` | ✅ | — | owner only | — | — | — |
| `assessment.manage` / `question.manage` | ✅ | — | ✅ | — | — | — |
| `attempt.take` | — | — | — | — | — | ✅ |
| `attempt.grade` | ✅ | — | ✅ | ✅ | ✅¹ | — |
| `attempt.view.any` | ✅ | — | ✅ | — | — | — |
| `progress.view.own` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `progress.view.context` | ✅ | — | — | — | ✅¹ | — |
| `progress.view.any` | ✅ | — | — | — | — | — |
| `deeplink.create` | ✅ | — | — | — | ✅¹ | — |
| `media.upload` | ✅ | — | ✅ | — | — | — |
| `client.*`, `entitlement.*`, `delivery.*` | ✅ | ✅ | — | — | — | — |
| `product.view` | ✅ | ✅ | ✅ | — | — | — |
| `product.manage` | ✅ | ✅ | — | — | — | — |
| `audit.view` | ✅ | — | — | — | — | — |

¹ **Scoped to the instructor's own `client_context`s.** An instructor at ABC
School grades ABC students in the classes they teach — not every ABC student,
and certainly not XYZ School's. Enforced by `ClientContextScope`, §6.6.

Notable: **`course.publish` is admin-only by default.** Give it to owners via a
`can_self_publish` flag on the course if a customer demands it, but ship with
the safe default — publishing is the only action a learner can observe.

`ops` exists so the person rotating ABC School's signing key at 11 p.m. does not
also hold the ability to edit Grade 10 English.

## 4. Course grants

```
role ∈ { owner, author, reviewer }
```

- `owner` — implicitly has `author`. Granted automatically to `created_by`. May manage grants on that course.
- `author` — edit the draft tree.
- `reviewer` — read + review. **No edit**, regardless of global role.

`requiredGrant(action)`:

| Action group | Required grant |
|---|---|
| `course.view` | any grant, or enrollment (viewers), or `course.view.any` |
| `node.*`, `block.*`, `course.update`, `assessment.manage` | `author` or `owner` |
| `course.submit_review` | `author` or `owner` |
| `course.review` | `reviewer` |
| `course.grant` | `owner` |
| `course.publish` | none (admin permission is sufficient) |

## 5. Separation of duties

> **A user may not review a course they have an `author` or `owner` grant on**,
> even if they hold both global roles.

Enforce in `CoursePolicy::review()`:

```php
public function review(User $user, Course $course): Response
{
    if ($user->hasGrantOn($course, ['author', 'owner'])) {
        return Response::deny('You cannot review a course you author.');
    }
    return $user->can('course.review') && $user->hasGrantOn($course, 'reviewer')
        ? Response::allow()
        : Response::deny();
}
```

This is the single most valuable line of authorization code in the system, and
it is the one people forget.

## 6. Implementation

### 6.1 Grant lookup, cached

```php
// app/Models/Concerns/HasCourseGrants.php
public function hasGrantOn(Course|string $course, string|array $roles): bool
{
    $id = $course instanceof Course ? $course->id : $course;
    $granted = Cache::remember(
        "grants:{$this->id}",
        now()->addMinutes(10),
        fn () => $this->courseGrants()
                      ->get()
                      ->groupBy('course_id')
                      ->map->pluck('role')
                      ->toArray(),
    );
    return (bool) array_intersect((array) $roles, $granted[$id] ?? []);
}
```
Bust `grants:{userId}` on any `course_grants` write. Bust `perms:{userId}` on
role change (spatie does this for you if `PERMISSION_CACHE` is on).

### 6.2 Policies

One policy per aggregate root. `CoursePolicy` is the important one; node/block
policies delegate to it:

```php
public function update(User $user, CourseNode $node): bool
{
    return $this->coursePolicy->update($user, $node->course);
}
```

Never authorize a `CourseNode` on its own — authority always flows from the
course.

### 6.3 Admin bypass

```php
// AuthServiceProvider::boot()
Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);
```
Return `null`, not `false` — returning `false` would short-circuit every other
gate for non-admins.

### 6.4 Query scoping

Authorization at the controller is not enough; list endpoints must filter.

```php
// app/Models/Scopes/VisibleToScope.php  — the *authoring* catalogue
public function scopeAuthorableBy(Builder $q, User $user): Builder
{
    if ($user->can('course.view.any')) return $q;

    return $q->whereHas('grants', fn ($g) => $g->where('user_id', $user->id));
}
```

The *learner* catalogue is not a query scope at all — it is
`EntitlementResolver::coursesFor($user, $clientCtx)`, which returns the courses
reachable through an active client entitlement, subscription, purchase, or comp
grant, intersected with `latest_publication_id IS NOT NULL`. A learner sees
nothing until the course has been published at least once.

Resist the temptation to reimplement that as an Eloquent scope for "just the
list endpoint". Two implementations of an access rule is one implementation and
one paid-content leak.

### 6.6 Client-context scoping

Every partner-facing and instructor-facing query filters on the authenticated
`client_id` — taken from the session token's `cid` claim (doc 10 §8), **never**
from a route parameter or request body.

```php
// app/Http/Middleware/EnsureClientScope.php
$clientId = $request->user()->currentClientId();   // from token, not input
abort_if($clientId === null, 403);
app()->instance(ClientContext::class, new ClientContext($clientId));
```

Apply it to the whole `partner` route group and to the client console. Then write
the test that enumerates `Route::getRoutes()` for that group and **fails if any
route lacks the middleware**. A route added six months from now by someone who
hasn't read this document is exactly how one school reads another school's data.

For instructors, add the context layer:

```php
public function viewProgress(User $user, User $learner): bool
{
    if ($user->can('progress.view.any')) return true;
    if ($user->id === $learner->id) return true;

    return $user->can('progress.view.context')
        && ClientContextMember::sharedContexts($user, $learner)
                              ->where('role', 'instructor')   // …as the instructor
                              ->exists();
}
```

### 6.5 Response shaping

The same `Course` model serialises differently per audience. Use distinct API
resources rather than conditional fields scattered through one:

- `CourseAdminResource` — everything, including `workflow_state`, grants, audit summary.
- `CourseAuthorResource` — draft tree, review comments, no attempt data.
- `CourseViewerResource` — published snapshot only.
- `QuestionViewerResource` — **strips `is_correct`, `grading`, `explanation`.**

Invariant I14 lives or dies here. Add a test that asserts
`QuestionViewerResource` output has no key named `is_correct` at any depth —
that test will catch the regression someone introduces in month nine.

## 7. Authentication

Four distinct credential paths. Do not let them share code beyond token issuance.

| Path | Who | Mechanism |
|---|---|---|
| Password / SSO | Staff, B2C learners | Sanctum tokens; `laravel/socialite` for Google/Azure |
| **Launch** | B2B learners & instructors | LTI 1.3 `id_token` or custom JWT → one-time ticket → token (doc 10 §4) |
| **Partner API** | The SIS, server-to-server | OAuth 2.0 client-credentials with private-key JWT (doc 10 §11) |
| Client console | `client_admin` | Password + 2FA, scoped by `EnsureClientScope` |

- Access token 1 h; refresh token 30 days (B2B) / 90 days (B2C), stored in the OS
  keystore via `flutter_secure_storage`.
- Token abilities mirror permissions for defence in depth, and carry `cid` +
  `ls` (launch session) for B2B sessions.
- **Entitlement is re-resolved on every refresh**, not only at launch. A student
  removed from ABC's roster on Monday loses access on Monday.
- Client-provisioned users have `users.email = NULL` and no `password` identity.
  They cannot log in directly, and therefore cannot be phished into it (doc 10 §7).
- Rate-limit `POST /auth/login` to 5/min/IP and 10/hour/email; launches to
  60/min per client; partner API to 600/min per client.
- Enforce 2FA for `admin`, `ops`, and `client_admin` (`laravel/fortify` TOTP).
