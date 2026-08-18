<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\EngageSetting;
use Illuminate\Http\JsonResponse;

/**
 * Read-only, unauthenticated. Exposes only the {location_id}-templated
 * app-install URL from the global EngageSetting singleton — never
 * client_id/client_secret/tokens, which stay behind
 * engage.identifiers.view (see SettingsController::globalEngageSetting()'s
 * own doc comment). Backs the public "Register for Application" page
 * (app/(customer)/register-application in the frontend), reachable
 * without a staff login so a new partner can self-install the app to
 * their own GHL location.
 */
class PublicEngageController extends Controller
{
    public function installationUrlTemplate(): JsonResponse
    {
        $setting = EngageSetting::query()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'location_installation_url_template' => $setting?->location_installation_url_template,
            ],
        ]);
    }
}
