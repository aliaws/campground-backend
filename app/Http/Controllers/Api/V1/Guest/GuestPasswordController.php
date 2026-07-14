<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\ChangePasswordRequest;
use App\Http\Requests\Guest\CreatePasswordRequest;
use App\Http\Requests\Guest\ForgotPasswordRequest;
use App\Http\Requests\Guest\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\GuestAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class GuestPasswordController extends Controller
{
    public function __construct(private GuestAccountService $guestAccounts) {}

    public function createPassword(CreatePasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->guestAccounts->createPassword(
                $request->validated('token'),
                $request->validated('password')
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ],
            'message' => 'Password created. You are now signed in.',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->guestAccounts->forgotPassword($request->validated('email'));

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->guestAccounts->resetPassword(
                $request->validated('token'),
                $request->validated('password')
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Password reset successfully. Please log in.',
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->guestAccounts->changePassword(
                $user,
                $request->validated('current_password'),
                $request->validated('password')
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Password updated.',
        ]);
    }
}
