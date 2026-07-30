<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangePasswordRequest;
use App\Http\Requests\Customer\CreatePasswordRequest;
use App\Http\Requests\Customer\ForgotPasswordRequest;
use App\Http\Requests\Customer\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CustomerPasswordController extends Controller
{
    public function __construct(private CustomerAccountService $customerAccounts) {}

    public function createPassword(CreatePasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->customerAccounts->createPassword(
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
        $this->customerAccounts->forgotPassword($request->validated('email'));

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->customerAccounts->resetPassword(
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
            $token = $this->customerAccounts->changePassword(
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
            'data' => ['token' => $token],
            'message' => 'Password updated.',
        ]);
    }
}