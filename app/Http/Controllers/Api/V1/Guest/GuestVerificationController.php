<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\ResendVerificationRequest;
use App\Http\Requests\Guest\VerifyCodeRequest;
use App\Http\Resources\UserResource;
use App\Services\GuestAccountService;
use Illuminate\Http\JsonResponse;

class GuestVerificationController extends Controller
{
    public function __construct(private GuestAccountService $guestAccounts) {}

    public function verifyCode(VerifyCodeRequest $request): JsonResponse
    {
        try {
            $user = $this->guestAccounts->verifyCode(
                $request->validated('token'),
                $request->validated('code')
            );
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
                'user' => new UserResource($user),
                'token' => $request->validated('token'),
            ],
            'message' => 'Email verified. Please create a password.',
        ]);
    }

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        try {
            $this->guestAccounts->resendVerification($request->validated('email'));
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
            'message' => 'If an unverified account exists for that email, a new verification message has been sent.',
        ]);
    }
}
