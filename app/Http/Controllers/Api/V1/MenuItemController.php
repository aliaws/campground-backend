<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EngageMenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-global staff sidebar config. index() is readable by any
 * authenticated user (the Sidebar needs it); update() is superadmin-only
 * (route-gated by permission:menu.manage).
 */
class MenuItemController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->rows(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'string', 'exists:engage_menu_items,key'],
            'items.*.label' => ['required', 'string', 'max:60'],
            'items.*.is_visible' => ['required', 'boolean'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $locked = EngageMenuItem::where('is_locked', true)->pluck('key')->all();

        DB::transaction(function () use ($data, $locked) {
            foreach ($data['items'] as $item) {
                EngageMenuItem::where('key', $item['key'])->update([
                    'label' => trim($item['label']),
                    // Locked rows (this screen's own entry) can never be hidden.
                    'is_visible' => in_array($item['key'], $locked, true) ? true : $item['is_visible'],
                    'sort_order' => $item['sort_order'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->rows(),
            'message' => 'Menu updated.',
        ]);
    }

    private function rows(): array
    {
        return EngageMenuItem::orderBy('sort_order')->get()
            ->map(fn (EngageMenuItem $m) => [
                'key' => $m->key,
                'parent_key' => $m->parent_key,
                'label' => $m->label,
                'default_label' => $m->default_label,
                'sort_order' => $m->sort_order,
                'is_visible' => $m->is_visible,
                'is_locked' => $m->is_locked,
            ])->all();
    }
}
