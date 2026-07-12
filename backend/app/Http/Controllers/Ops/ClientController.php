<?php

namespace App\Http\Controllers\Ops;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientKey;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B2B client administration (ops). A client is an integrating organisation —
 * ABC School — whose users reach published content through a launch.
 *
 * `webhook_secret` is never returned to the browser: it is a shared secret used
 * to sign activity reports back to the client, and a secret that round-trips
 * through a page is a secret you have to rotate.
 */
class ClientController extends Controller
{
    private const STATUSES = ['pending', 'active', 'suspended', 'terminated'];

    private const INTEGRATIONS = ['none', 'lti_1_3', 'custom_jwt'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $clients = Client::query()
            ->withCount(['users', 'entitlements'])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'status' => $c->status,
                'integration' => $c->integration,
                'user_count' => $c->users_count,
                'entitlement_count' => $c->entitlements_count,
            ]);

        return Inertia::render('clients/Index', [
            'clients' => $clients,
            'options' => ['statuses' => self::STATUSES, 'integrations' => self::INTEGRATIONS],
            'can' => ['create' => Gate::allows('create', Client::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $data = $request->validate($this->rules());

        $client = Client::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?: $this->uniqueSlug($data['name']),
            'status' => $data['status'],
            'integration' => $data['integration'],
            'contact_email' => $data['contact_email'] ?? null,
        ]);

        return redirect()
            ->route('ops.clients.show', $client)
            ->with('success', "Created client “{$client->name}”.");
    }

    public function show(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);

        $client->loadCount(['users', 'entitlements']);

        return Inertia::render('clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'status' => $client->status,
                'integration' => $client->integration,
                'contact_email' => $client->contact_email,
                'user_count' => $client->users_count,
                'entitlement_count' => $client->entitlements_count,
                // The URL is not secret; the secret itself is never serialized.
                'report_webhook_url' => $client->report_webhook_url,
                'has_webhook_secret' => $client->webhook_secret !== null,
                'ai_tutor_enabled' => $client->aiTutorEnabled(),
            ],
            'keys' => $client->keys()->orderByDesc('created_at')->get()
                ->map(fn (ClientKey $k) => [
                    'id' => $k->id,
                    'kid' => $k->kid,
                    'algorithm' => $k->algorithm,
                    'status' => $k->status,
                    'expires_at' => $k->expires_at?->toIso8601String(),
                ]),
            'entitlements' => $client->entitlements()->with('product:id,name')->orderByDesc('starts_at')->get()
                ->map(fn (ClientEntitlement $e) => [
                    'id' => $e->id,
                    'product_id' => $e->product_id,
                    'product' => $e->product->name,
                    'seat_model' => $e->seat_model,
                    'seat_limit' => $e->seat_limit,
                    'status' => $e->status,
                    'starts_at' => $e->starts_at->toDateString(),
                    'ends_at' => $e->ends_at?->toDateString(),
                    'contract_ref' => $e->contract_ref,
                ]),
            // For the entitlement form: products to grant and the seat vocab.
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku]),
            'options' => [
                'statuses' => self::STATUSES,
                'integrations' => self::INTEGRATIONS,
                'seat_models' => [ClientEntitlement::ASSIGNED, ClientEntitlement::ACTIVE_SEATS, ClientEntitlement::UNLIMITED],
                'entitlement_statuses' => ['active', 'suspended', 'expired'],
            ],
            'can' => [
                'manage' => Gate::allows('manage', $client),
                'manage_entitlements' => $request->user()->can(Permissions::ENTITLEMENT_MANAGE),
                'manage_keys' => $request->user()->can(Permissions::CLIENT_KEY_ROTATE),
            ],
        ]);
    }

    /** Turn the AI tutor on or off for this client's learners. */
    public function updateAiTutor(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('manage', $client);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $client->setAiTutorEnabled($data['enabled']);

        return back()->with('success', $data['enabled'] ? 'AI tutor enabled.' : 'AI tutor disabled.');
    }

    /** Set (or clear) the client's activity-report webhook URL. */
    public function updateWebhook(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('manage', $client);

        $data = $request->validate([
            'report_webhook_url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
        ]);

        $client->update(['report_webhook_url' => $data['report_webhook_url'] ?? null]);

        return back()->with('success', 'Webhook URL saved.');
    }

    /**
     * Mint a fresh webhook signing secret and hand it back exactly once.
     *
     * The client stores it and uses it to verify our HMAC signatures. We keep it
     * to sign with, but never return it again — a secret that can be re-read is a
     * secret you have to assume is compromised.
     */
    public function rotateSecret(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::CLIENT_KEY_ROTATE), 403);

        $secret = 'whsec_'.Str::random(48);
        $client->update(['webhook_secret' => $secret]);

        // Flashed once for the confirmation screen; never stored in a prop again.
        return back()->with('success', 'Webhook secret rotated.')->with('webhook_secret', $secret);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('manage', $client);

        $data = $request->validate($this->rules($client));

        $client->update([
            'name' => $data['name'],
            'slug' => $data['slug'] ?: $client->slug,
            'status' => $data['status'],
            'integration' => $data['integration'],
            'contact_email' => $data['contact_email'] ?? null,
        ]);

        return back()->with('success', 'Client saved.');
    }

    /** @return array<string, mixed> */
    private function rules(?Client $client = null): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('clients', 'slug')->ignore($client?->id),
            ],
            'status' => ['required', Rule::in(self::STATUSES)],
            'integration' => ['required', Rule::in(self::INTEGRATIONS)],
            'contact_email' => ['nullable', 'email', 'max:200'],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = $base;
        $n = 2;

        while (Client::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
