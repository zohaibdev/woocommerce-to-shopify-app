<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::withCount([
            'subscriptions as active_subscriptions' => fn ($q) => $q->where('status', 'active'),
        ])->orderBy('price')->get();

        return response()->json([
            'data' => $plans->map(fn (Plan $p) => $this->present($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($this->present(Plan::create($data)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update($this->validated($request, $plan->id));

        return response()->json($this->present($plan->fresh()));
    }

    /**
     * Delete a plan. Refused while stores are subscribed to it — deleting would
     * cascade their subscriptions away and silently drop them to Starter.
     */
    public function destroy(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        if ($plan->code === 'starter') {
            return response()->json([
                'error' => 'The Starter plan is the free fallback and cannot be deleted. Disable it instead.',
            ], 422);
        }

        $active = Subscription::where('plan_id', $plan->id)->where('status', 'active')->count();
        if ($active > 0) {
            return response()->json([
                'error' => "{$active} store(s) are on this plan. Move them first, or just disable the plan.",
            ], 422);
        }

        $plan->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('plans', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'interval' => ['sometimes', 'in:monthly,yearly'],
            'active' => ['sometimes', 'boolean'],
            // null = unlimited, 0 = not included
            'limits.products' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limits.customers' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limits.orders' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limits.metafields' => ['sometimes', 'boolean'],
            // One shared record budget across products+customers+orders instead
            // of a cap on each. See App\Support\PlanPool.
            'limits.total' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Negotiated off-platform; not buyable through Shopify billing.
            'limits.contact_only' => ['sometimes', 'boolean'],
            // Content features, each sellable on its own. null = unlimited,
            // 0 = not in this plan (the wizard then disables and explains it).
            // `blogs` covers blogs AND their posts: they are one feature to a
            // merchant, and a plan that sold blogs without posts would deliver
            // empty containers.
            'limits.coupons' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limits.blogs' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limits.reviews' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // The defaults below are what an UNSPECIFIED key means, so every key the
        // app understands has to appear here. `total` and `contact_only` were
        // missing, and because this is an array_merge against the request, a plan
        // that HAD them lost them the moment anyone opened it in the admin panel
        // and pressed save — silently turning a 20,000-record pooled plan into an
        // unlimited one, and making Enterprise buyable through Shopify checkout.
        $data['limits'] = array_merge(
            [
                'products' => null,
                'customers' => null,
                'orders' => null,
                'metafields' => true,
                'total' => null,
                'contact_only' => false,
                'coupons' => null,
                'blogs' => null,
                'reviews' => null,
            ],
            $request->input('limits', []),
        );

        return $data;
    }

    private function present(Plan $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'price' => (float) $p->price,
            'interval' => $p->interval,
            'limits' => $p->limits,
            'active' => (bool) $p->active,
            'active_subscriptions' => $p->active_subscriptions ?? Subscription::where('plan_id', $p->id)
                ->where('status', 'active')->count(),
        ];
    }
}
