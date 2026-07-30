<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\SessionJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'roles' => [$request->input('role', User::ROLE_CUSTOMER)],
            'status' => User::STATUS_ACTIVE,
        ]);

        $token = SessionJwt::issue($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Registration successful.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! $user->password || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->isLoginBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account is not allowed to sign in. Please contact support.',
            ], 403);
        }

        if ($user->hasRole(User::ROLE_CUSTOMER) && ! $user->hasAnyRole(...User::STAFF_ROLES) && $user->status !== User::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Please finish verifying your account before signing in.',
            ], 403);
        }

        $token = SessionJwt::issue($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Login successful.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Auth\JwtGuard $guard */
        $guard = Auth::guard('api');
        $jti = $guard->currentJti();
        $exp = $guard->currentTokenExp();

        if ($jti && $exp) {
            SessionJwt::revoke($jti, $exp);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('customer');

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'message' => 'Authenticated user.',
        ]);
    }
}
