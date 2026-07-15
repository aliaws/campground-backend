<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = User::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('role', ['admin', 'staff', 'cashier'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($staff),
            'message' => 'Staff retrieved.',
        ]);
    }

    /** Admin-only (see routes/api.php): creates a staff account within the admin's own tenant — never a new one. */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $user = User::create($request->validated() + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'message' => 'Staff account created.',
        ], 201);
    }
}
