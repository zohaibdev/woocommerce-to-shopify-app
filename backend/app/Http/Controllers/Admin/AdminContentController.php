<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DashboardContent;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContentController extends Controller
{
    private const TYPES = [
        'tutorial_video', 'useful_link', 'documentation_link',
        'support_link', 'email_info', 'announcement', 'other_resource',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = DashboardContent::query()->ordered();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json([
            'data' => $query->get(),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['position'] = $data['position'] ?? (DashboardContent::max('position') + 1);

        return response()->json(DashboardContent::create($data), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $content = DashboardContent::findOrFail($id);
        $content->update($this->validated($request, false));

        return response()->json($content->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        DashboardContent::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Persist a new ordering: {"order": [id, id, ...]}. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($data['order'] as $position => $id) {
            DashboardContent::where('id', $id)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    // --- Plans (read + manage) ------------------------------------------------

    public function plans(): JsonResponse
    {
        return response()->json(
            Plan::withCount(['subscriptions as active_subscriptions' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('price')->get()
        );
    }

    private function validated(Request $request, bool $requireAll = true): array
    {
        $rule = $requireAll ? 'required' : 'sometimes';

        return $request->validate([
            'type' => [$rule, 'in:' . implode(',', self::TYPES)],
            'title' => [$rule, 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string'],
            'url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }
}
