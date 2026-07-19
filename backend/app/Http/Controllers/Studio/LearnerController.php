<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Authorization\Roles;
use App\Entitlements\EntitlementResolver;
use App\Http\Controllers\Controller;
use App\Models\CompGrant;
use App\Models\Course;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B2C learner administration: the people who signed up directly (not launched by
 * a client). Admins can search them, suspend/reactivate, and comp them access to
 * a product — the manual grant that stands in for a checkout while, or instead
 * of, a paid purchase.
 *
 * B2B (client-provisioned) learners are managed through their client, not here.
 */
class LearnerController extends Controller
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::LEARNER_MANAGE), 403);

        $search = trim((string) $request->query('search', ''));

        $learners = $this->baseQuery()
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"),
            ))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'joined' => $u->created_at?->toDateString(),
            ]);

        return Inertia::render('learners/Index', [
            'learners' => $learners,
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $learner): Response
    {
        abort_unless($request->user()->can(Permissions::LEARNER_MANAGE), 403);
        $this->assertB2cLearner($learner);

        $courses = $this->resolver->coursesFor($learner);

        return Inertia::render('learners/Show', [
            'learner' => [
                'id' => $learner->id,
                'name' => $learner->name,
                'email' => $learner->email,
                'status' => $learner->status,
                'joined' => $learner->created_at?->toDateString(),
            ],
            // What they can currently open.
            'courses' => $courses->map(fn (Course $c) => ['id' => $c->id, 'title' => $c->title])->values(),
            'comps' => CompGrant::where('user_id', $learner->id)->with('product:id,name')->latest('starts_at')->get()
                ->map(fn (CompGrant $g) => [
                    'id' => $g->id,
                    'product' => $g->product->name,
                    'reason' => $g->reason,
                    'starts_at' => $g->starts_at->toDateString(),
                    'ends_at' => $g->ends_at?->toDateString(),
                ]),
            'subscriptions' => Subscription::where('user_id', $learner->id)->with('plan:id,name')->latest()->get()
                ->map(fn (Subscription $s) => [
                    'plan' => $s->plan->name,
                    'status' => $s->status,
                    'entitles' => $s->isEntitling(),
                ]),
            'purchases' => Purchase::where('user_id', $learner->id)->with('product:id,name')->latest()->get()
                ->map(fn (Purchase $p) => [
                    'product' => $p->product->name,
                    'status' => $p->refunded_at === null ? 'owned' : 'refunded',
                ]),
            // Products an admin can comp them into.
            'products' => Product::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name]),
        ]);
    }

    /** Suspend or reactivate a learner. */
    public function updateStatus(Request $request, User $learner): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::LEARNER_MANAGE), 403);
        $this->assertB2cLearner($learner);

        $data = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
        ]);

        $learner->update(['status' => $data['status']]);

        return back()->with('success', $data['status'] === User::STATUS_SUSPENDED ? 'Learner suspended.' : 'Learner reactivated.');
    }

    /** Comp a learner into a product (free access). */
    public function grant(Request $request, User $learner): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::LEARNER_MANAGE), 403);
        $this->assertB2cLearner($learner);

        $data = $request->validate([
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')],
            'ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        CompGrant::create([
            'user_id' => $learner->id,
            'product_id' => $data['product_id'],
            'reason' => CompGrant::REASON_SUPPORT,
            'granted_by' => $request->user()->id,
            'starts_at' => now(),
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        return back()->with('success', 'Access granted.');
    }

    /** Revoke a comp grant. */
    public function revokeGrant(Request $request, CompGrant $comp): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::LEARNER_MANAGE), 403);

        $comp->delete();  // fires the entitlement-cache bust

        return back()->with('success', 'Access revoked.');
    }

    /** @return Builder<User> */
    private function baseQuery()
    {
        return User::query()
            ->role(Roles::LEARNER)
            ->where('kind', User::KIND_LOCAL);
    }

    /** A client-provisioned learner is B2B and out of scope here. */
    private function assertB2cLearner(User $learner): void
    {
        abort_unless(
            $learner->kind === User::KIND_LOCAL && $learner->hasRole(Roles::LEARNER),
            404,
        );
    }
}
