<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CustomScript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Custom Scripts — superadmin only. Code snippets for every organization or
 * only selected ones, added to their customer website and/or staff portal
 * (head, body start or body end). The code
 * runs in every visitor's browser, so only the superadmin can manage it.
 */
class CustomScriptController extends Controller
{
    public function index(): JsonResponse
    {
        $scripts = CustomScript::query()->with('organizations:id,name')->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $scripts->map(fn (CustomScript $s) => $this->present($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) CustomScript::query()->max('sort_order') + 1;

        $script = DB::transaction(function () use ($data) {
            $script = CustomScript::create(Arr::except($data, 'organization_ids'));
            $script->organizations()->sync($data['organization_ids'] ?? []);

            return $script;
        });
        CustomScript::forgetCache();

        return response()->json([
            'success' => true,
            'data' => $this->present($script),
            'message' => 'Script added.',
        ], 201);
    }

    public function update(Request $request, CustomScript $customScript): JsonResponse
    {
        $data = $this->validated($request, partial: true);

        DB::transaction(function () use ($customScript, $data) {
            $customScript->update(Arr::except($data, 'organization_ids'));
            if (array_key_exists('organization_ids', $data)) {
                $customScript->organizations()->sync($data['organization_ids'] ?? []);
            }
        });
        CustomScript::forgetCache();

        return response()->json([
            'success' => true,
            'data' => $this->present($customScript->fresh()),
            'message' => 'Script updated.',
        ]);
    }

    public function destroy(CustomScript $customScript): JsonResponse
    {
        $customScript->delete();
        CustomScript::forgetCache();

        return response()->json([
            'success' => true,
            'message' => 'Script deleted.',
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:20000'],
            'placement' => [$required, Rule::in(CustomScript::PLACEMENTS)],
            'target' => [$required, Rule::in(CustomScript::TARGETS)],
            // Empty or null = every organization.
            'organization_ids' => ['sometimes', 'nullable', 'array'],
            'organization_ids.*' => ['uuid', 'distinct', 'exists:engage_organization_locations,id'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function present(CustomScript $script): array
    {
        $organizations = $script->loadMissing('organizations:id,name')->organizations
            ->sortBy('name')
            ->map(fn ($org) => ['id' => $org->id, 'name' => $org->name])
            ->values();

        return [
            'id' => $script->id,
            'name' => $script->name,
            'code' => $script->code,
            'placement' => $script->placement,
            'target' => $script->target,
            'organization_ids' => $organizations->pluck('id')->all(),
            'organizations' => $organizations->all(),
            'is_active' => $script->is_active,
            'sort_order' => $script->sort_order,
            'created_at' => $script->created_at?->toIso8601String(),
            'updated_at' => $script->updated_at?->toIso8601String(),
        ];
    }
}
