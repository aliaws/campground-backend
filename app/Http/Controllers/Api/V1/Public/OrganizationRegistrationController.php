<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompleteRegistrationRequest;
use App\Http\Requests\Organization\CreateOwnerPasswordRequest;
use App\Http\Requests\Organization\RegisterLocationRequest;
use App\Http\Requests\Organization\ResendOrganizationVerificationRequest;
use App\Http\Requests\Organization\VerifyOrganizationCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\EngageOrganizationLocation;
use App\Services\OrganizationRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Public (unauthenticated), self-service organization onboarding — see
 * OrganizationRegistrationService's doc comment for the full flow.
 */
class OrganizationRegistrationController extends Controller
{
    public function __construct(private OrganizationRegistrationService $organizationRegistrations) {}

    public function register(RegisterLocationRequest $request): JsonResponse
    {
        try {
            $organization = $this->organizationRegistrations->registerLocation($request->validated('location_id'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['organization_id' => $organization->id],
            'message' => 'Location registered.',
        ], 201);
    }

    public function complete(EngageOrganizationLocation $organization, CompleteRegistrationRequest $request): JsonResponse
    {
        if (! $organization->isUninstalled()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This organization has already completed registration.',
            ], 422);
        }

        try {
            $this->organizationRegistrations->completeRegistration($organization, $request->validated());
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
            'message' => 'Check your business email for a verification code to continue.',
        ]);
    }

    public function resend(ResendOrganizationVerificationRequest $request): JsonResponse
    {
        $organization = EngageOrganizationLocation::query()
            ->where('engage_location_id', $request->validated('location_id'))
            ->first();

        if ($organization) {
            try {
                $this->organizationRegistrations->resendVerification($organization);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'If a pending registration exists for this location, a new verification code has been sent.',
        ]);
    }

    public function verifyCode(VerifyOrganizationCodeRequest $request): JsonResponse
    {
        try {
            $result = $this->organizationRegistrations->verifyCode(
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
                'organization_name' => $result['organization']->name,
                'needs_password' => $result['needs_password'],
                'token' => $request->validated('token'),
            ],
            'message' => $result['needs_password']
                ? 'Email verified. Please create a password.'
                : 'Email verified. Your organization is now active.',
        ]);
    }

    public function createPassword(CreateOwnerPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->organizationRegistrations->createPassword(
                $request->validated('token'),
                $request->validated('password'),
                $request->validated('name')
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

        // Same UserResource.permissions self-lookup gotcha as
        // CustomerPasswordController::createPassword() — the resource's
        // allowed_actions field only resolves against whichever user the
        // request(s) can be resolved to, and this route has no session.
        $resolver = fn () => $result['user'];
        $request->setUserResolver($resolver);
        app('request')->setUserResolver($resolver);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ],
            'message' => 'Password created. You are now signed in.',
        ]);
    }
}
