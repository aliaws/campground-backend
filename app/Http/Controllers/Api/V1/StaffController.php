<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OrganizationLocationResolver;
use App\Support\SessionJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = User::query()
            ->where(function ($q) {
                foreach ([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF] as $role) {
                    $q->orWhereJsonContains('roles', $role);
                }
            })
            ->whereJsonDoesntContain('roles', User::ROLE_SUPERADMIN);

        if (! $actor->isSuperAdmin()) {
            $locationId = $actor->resolveOrganizationLocationId();
            $query->whereHas(
                'locationLinks',
                fn ($q) => $q->where('engage_organization_location_id', $locationId)
            );
        }

        $staff = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($staff),
            'message' => 'Staff retrieved.',
        ]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->canManageStaffUsers()) {
            return $this->forbidden('You are not allowed to create staff users.');
        }

        $data = $request->validated();
        $role = $data['role'];
        unset($data['role']);

        if (! $actor->canAssignRole($role)) {
            return $this->forbidden("You are not allowed to assign the '{$role}' role.");
        }

        if (! $actor->isSuperAdmin() && ! $actor->primaryLocationId()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to an organization location.',
            ], 422);
        }

        $locationId = $actor->isSuperAdmin()
            ? OrganizationLocationResolver::resolveDefaultLocationId()
            : $actor->resolveOrganizationLocationId();

        $user = User::create($data + [
            'roles' => [$role],
            'status' => User::STATUS_ACTIVE,
            'created_by' => $actor->id,
        ]);

        $user->attachLocation($locationId);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->fresh()->load('locationLinks')),
            'message' => 'Staff account created.',
        ], 201);
    }

    public function update(UpdateStaffRequest $request, User $staff): JsonResponse
    {
        $actor = $request->user();

        if (! $this->sameLocationOrSuperadmin($actor, $staff)) {
            return $this->forbidden('Staff user not found in your location.');
        }

        if (! $actor->canUpdateStaffUser($staff)) {
            return $this->forbidden('You are not allowed to update this user.');
        }

        $data = $request->validated();

        if (isset($data['role'])) {
            if (! $actor->canAssignRole($data['role'])) {
                return $this->forbidden("You are not allowed to assign the '{$data['role']}' role.");
            }
            $staff->roles = [$data['role']];
            unset($data['role']);
        }

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        $staff->fill($data);
        $staff->save();

        return response()->json([
            'success' => true,
            'data' => new UserResource($staff->fresh()->load('locationLinks')),
            'message' => 'Staff account updated.',
        ]);
    }

    public function destroy(Request $request, User $staff): JsonResponse
    {
        $actor = $request->user();

        if (! $this->sameLocationOrSuperadmin($actor, $staff)) {
            return $this->forbidden('Staff user not found in your location.');
        }

        if (! $actor->canDeleteStaffUser($staff)) {
            return $this->forbidden('You are not allowed to delete this user.');
        }

        SessionJwt::revokeAllFor($staff);
        $staff->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff account deleted.',
        ]);
    }

    private function sameLocationOrSuperadmin(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        $targetLocationId = $target->primaryLocationId();

        return is_string($targetLocationId) && $targetLocationId !== '' && $actor->belongsToLocation($targetLocationId);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
