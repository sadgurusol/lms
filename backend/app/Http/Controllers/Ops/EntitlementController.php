<?php

namespace App\Http\Controllers\Ops;

use App\Authorization\Permissions;
use App\Entitlements\EntitlementCache;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A client's entitlements: which products they hold, on what seat terms, for
 * what window. Creating or changing one changes what that client's users may
 * reach, so every write busts the entitlement cache.
 */
class EntitlementController extends Controller
{
    private const SEAT_MODELS = [
        ClientEntitlement::ASSIGNED,
        ClientEntitlement::ACTIVE_SEATS,
        ClientEntitlement::UNLIMITED,
    ];

    private const STATUSES = ['active', 'suspended', 'expired'];

    public function __construct(private readonly EntitlementCache $cache) {}

    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::ENTITLEMENT_MANAGE), 403);

        $data = $this->validated($request);

        // The (client, product, starts_at) unique index catches a genuine
        // duplicate; naming the field keeps the error on the form.
        $exists = $client->entitlements()
            ->where('product_id', $data['product_id'])
            ->where('starts_at', $data['starts_at'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'product_id' => 'This client already holds that product from that start date.',
            ]);
        }

        DB::transaction(fn () => $client->entitlements()->create($data));
        $this->cache->forgetEveryone();

        return back()->with('success', 'Entitlement granted.');
    }

    public function update(Request $request, ClientEntitlement $entitlement): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::ENTITLEMENT_MANAGE), 403);

        // The product is not editable: change the product and it is a different
        // grant. Add a new entitlement and expire this one instead.
        $data = $this->validated($request, $entitlement);
        unset($data['product_id']);

        DB::transaction(fn () => $entitlement->update($data));
        $this->cache->forgetEveryone();

        return back()->with('success', 'Entitlement updated.');
    }

    public function destroy(Request $request, ClientEntitlement $entitlement): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::ENTITLEMENT_MANAGE), 403);

        DB::transaction(fn () => $entitlement->delete());
        $this->cache->forgetEveryone();

        return back()->with('success', 'Entitlement removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ClientEntitlement $entitlement = null): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')],
            'seat_model' => ['required', Rule::in(self::SEAT_MODELS)],
            // Required and positive unless the model is unlimited; the DB CHECK
            // enforces the same, this just returns a clean message first.
            'seat_limit' => ['nullable', 'integer', 'min:1', 'required_unless:seat_model,unlimited'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'contract_ref' => ['nullable', 'string', 'max:120'],
        ]);

        // Unlimited holds no seat number, whatever the form sent.
        if ($data['seat_model'] === ClientEntitlement::UNLIMITED) {
            $data['seat_limit'] = null;
        }

        return $data;
    }
}
