<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => PermissionMatrix::roles(),
                'actions' => PermissionMatrix::matrix(),
                'self' => [
                    'roles' => $user->roleList(),
                    'allowed' => PermissionMatrix::allowedFor($user),
                ],
            ],
            'message' => 'Permission matrix retrieved.',
        ]);
    }
}
