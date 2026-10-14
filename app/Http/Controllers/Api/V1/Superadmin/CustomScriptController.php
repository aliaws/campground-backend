<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CustomScript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custom Scripts — superadmin only. Code snippets added to the customer
 * website and/or the staff portal (head, body start or body end). The code
 * runs in every visitor's browser, so only the superadmin can manage it.
 */
class CustomScriptController extends Controller
{
    public function index(): JsonResponse
    {
        $scripts = CustomScript::query()->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $scripts->map(fn (CustomScript $s) => $this->present($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['sort_order'] ??= (int) CustomScript::query()->max('sort_order') + 1;

        $script = CustomScript::create($data);
        CustomScript::forgetCache();

        return response()->json([
            'success' => true,
            'data' => $this->present($script),
            'message' => 'Script added.',
        ], 201);
    }

    public function update(Request $request, CustomScript $customScript): JsonResponse
    {
        $customScript->update($this->validated($request, partial: true));
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
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function present(CustomScript $script): array
    {
        return [
            'id' => $script->id,
            'name' => $script->name,
            'code' => $script->code,
            'placement' => $script->placement,
            'target' => $script->target,
            'is_active' => $script->is_active,
            'sort_order' => $script->sort_order,
            'created_at' => $script->created_at?->toIso8601String(),
            'updated_at' => $script->updated_at?->toIso8601String(),
        ];
    }
}
