<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\JwtGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StaffAccountService;
use App\Support\SessionJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private StaffAccountService $staffAccounts) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'roles' => [$request->input('role', User::ROLE_CUSTOMER)],
            'status' => User::STATUS_ACTIVE,
        ]);

        $token = $this->issueTokenFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($this->asSelf($request, $user)),
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

        // Org-blocking is per-organization, not per-user: a user linked to
        // multiple orgs where only some are blocked still logs in fine (and
        // simply won't be able to select a blocked one — see
        // selectOrganization()/issueTokenFor() below). Only reject here
        // when the user has links at all and every single one is blocked.
        // Super-admin is exempt (org-less by design, see User::primaryLocationId()).
        if (! $user->isSuperAdmin() && $user->locationLinks()->exists() && ! $user->hasAnyActiveOrganization()) {
            return response()->json([
                'success' => false,
                'message' => "Your organization's access has been suspended. Please contact support.",
            ], 403);
        }

        if ($user->hasRole(User::ROLE_CUSTOMER) && ! $user->hasAnyRole(...User::STAFF_ROLES) && $user->status !== User::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Please finish verifying your account before signing in.',
            ], 403);
        }

        $token = $this->issueTokenFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($this->asSelf($request, $user)),
                'token' => $token,
            ],
            'message' => 'Login successful.',
        ]);
    }

    /**
     * login()/register() are unauthenticated routes — $request->user() is
     * still null at the point UserResource is built, even though we've
     * just identified exactly who $user is. UserResource's `permissions`
     * field is gated on `$request->user()?->id === $this->id` (deliberately
     * self-lookup-only, so serializing some *other* user — e.g. a staff
     * list — never leaks their allowed actions) — that check silently came
     * back false on every login/register response, so the frontend's
     * AuthContext seeded allowedActions as [] until the next /auth/me call,
     * and the Sidebar/AppLayout rendered as if the user had zero
     * permissions until a manual page reload fixed it.
     *
     * The fix has to reach two different Request objects, confirmed via
     * live debugging (not guessed): the $request injected into this
     * controller method (a LoginRequest/RegisterRequest FormRequest
     * instance) is NOT the same object JsonResource ends up resolving —
     * UserResource::toArray() is invoked lazily during response
     * json_encode() via JsonResource::jsonSerialize() -> resolve(), which
     * falls back to Container::getInstance()->make('request') when no
     * request is explicitly passed through — a *different*, separately
     * bound Request instance in this app's request lifecycle. Setting the
     * resolver only on the controller's own $request left the
     * container-bound one (the one actually used at serialization time)
     * untouched, so the permissions field kept coming back missing even
     * after the "fix." Stamping both closes the gap without touching
     * UserResource's actual security semantics at all.
     */
    private function asSelf(Request $request, User $user): User
    {
        $resolver = fn () => $user;
        $request->setUserResolver($resolver);
        app('request')->setUserResolver($resolver);

        return $user;
    }

    /**
     * A user linked to exactly one organization gets scoped to it
     * immediately at login — no extra step for the common case. A user
     * linked to more than one (or none yet) gets an unscoped token;
     * resolveOrganizationLocationId() falls back to its pre-existing
     * "first linked, else default" behavior for any request made before
     * they explicitly pick one via POST /auth/select-organization, so
     * nothing breaks for a multi-org user who never bothers to pick.
     *
     * Also stamps the choice onto $user itself (not just the token) so the
     * UserResource built from this same request/object correctly reports
     * organization_selected — JwtGuard only ever does that for a *later*
     * request that comes back in with the token, not for the object this
     * very login/register call already has in hand.
     */
    private function issueTokenFor(User $user): string
    {
        $ids = collect($user->activeLocationIds());
        $locationId = $ids->count() === 1 ? $ids->first() : null;

        $user->setActiveLocationId($locationId);

        return SessionJwt::issue($user, $locationId);
    }

    /**
     * Lets a multi-organization user (or one who wants to switch) choose
     * which organization's data the rest of their session should be scoped
     * to. Re-issues a fresh token carrying that choice as the JWT's `loc`
     * claim; every subsequent request's resolveOrganizationLocationId()
     * then resolves to it directly. Revokes the old token so a stale
     * unscoped/differently-scoped one can't keep being used afterward.
     */
    public function selectOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'engage_organization_location_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $locationId = $request->input('engage_organization_location_id');

        if (! $user->belongsToLocation($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to that organization.',
            ], 403);
        }

        if (! $user->isSuperAdmin() && ! in_array($locationId, $user->activeLocationIds(), true)) {
            return response()->json([
                'success' => false,
                'message' => "That organization's access has been suspended.",
            ], 403);
        }

        /** @var JwtGuard $guard */
        $guard = Auth::guard('api');
        $jti = $guard->currentJti();
        $exp = $guard->currentTokenExp();
        if ($jti && $exp) {
            SessionJwt::revoke($jti, $exp);
        }

        $token = SessionJwt::issue($user, $locationId);
        $user->setActiveLocationId($locationId);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Organization selected.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var JwtGuard $guard */
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

    /**
     * Staff forgot-password (owner/admin/staff/superadmin) — deliberately
     * always returns the same success message regardless of whether the
     * email matched a real staff account, so this endpoint can't be used
     * to enumerate staff emails.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->staffAccounts->forgotPassword($request->validated('email'));

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'If a staff account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->staffAccounts->resetPassword(
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
            $token = $this->staffAccounts->changePassword(
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

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'max:2048'],
        ]);

        $user = $this->staffAccounts->updateAvatar($request->user(), $request->file('avatar'));

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'message' => 'Profile picture updated.',
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $this->staffAccounts->deleteAvatar($request->user());

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'message' => 'Profile picture removed.',
        ]);
    }
}
