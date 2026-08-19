<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

/**
 * Read-only, unauthenticated. Backs the public "Register for Application ->
 * Complete Registration" page's Business Country picker — countries aren't
 * sensitive data, and that page has no staff auth token to call the
 * existing GET /settings/countries with.
 */
class PublicCountryController extends Controller
{
    public function index(): JsonResponse
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => CountryResource::collection($countries),
        ]);
    }
}
