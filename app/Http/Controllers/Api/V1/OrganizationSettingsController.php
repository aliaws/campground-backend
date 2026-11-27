<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationSettingsRequest;
use App\Http\Resources\OrganizationSettingResource;
use App\Models\EngageOrganizationLocation;
use App\Models\OrganizationSetting;
use App\Services\LeadConnectorLocationSyncService;
use App\Support\PublicStorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The caller's OWN campground settings (Settings page). Every staff role can
 * read them (the portal shows the brand); owner/admin can change them.
 */
class OrganizationSettingsController extends Controller
{
    private const IMAGE_FOLDER = 'organization-branding';

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->settings($request));
    }

    public function update(UpdateOrganizationSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings($request);
        $settings->fill($request->validated())->save();

        return $this->respond($settings, 'Settings saved.');
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', Rule::in(array_keys(OrganizationSetting::IMAGE_FIELDS))],
            'image' => $request->input('type') === 'favicon'
                ? ['required', 'file', 'mimes:png,ico,svg,jpg,jpeg,webp', 'max:1024']
                : ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $settings = $this->settings($request);
        $field = OrganizationSetting::IMAGE_FIELDS[$request->input('type')];
        $old = $settings->{$field};

        $path = $request->file('image')->store(self::IMAGE_FOLDER, 'public');
        $settings->{$field} = PublicStorageUrl::absolute(Storage::url($path));
        $settings->save();

        $this->deleteLocalFile($old);

        return $this->respond($settings, 'Image uploaded.');
    }

    public function deleteImage(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', Rule::in(array_keys(OrganizationSetting::IMAGE_FIELDS))],
        ]);

        $settings = $this->settings($request);
        $field = OrganizationSetting::IMAGE_FIELDS[$request->input('type')];
        $old = $settings->{$field};

        if ($old !== null) {
            $settings->{$field} = null;
            $settings->save();
            $this->deleteLocalFile($old);
        }

        return $this->respond($settings, 'Image removed.');
    }

    /** Lead Connector's location values next to the current ones (nothing saved). */
    public function leadConnectorPreview(Request $request, LeadConnectorLocationSyncService $sync): JsonResponse
    {
        try {
            $fields = $sync->preview($this->organization($request));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['fields' => $fields], 'message' => null]);
    }

    /** Copies the chosen fields from Lead Connector (re-read on the server). */
    public function applyLeadConnector(Request $request, LeadConnectorLocationSyncService $sync): JsonResponse
    {
        $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(LeadConnectorLocationSyncService::FIELDS)],
        ]);

        try {
            $applied = $sync->apply($this->organization($request), $request->input('fields'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'data' => null, 'message' => $e->getMessage()], 422);
        }

        $settings = $this->settings($request);

        return response()->json([
            'success' => true,
            'data' => ['applied' => $applied, 'settings' => new OrganizationSettingResource($settings)],
            'message' => $applied === []
                ? 'Nothing was updated: Lead Connector has no value for the chosen fields.'
                : 'Updated from Lead Connector: '.implode(', ', $applied).'.',
        ]);
    }

    private function organization(Request $request): EngageOrganizationLocation
    {
        return EngageOrganizationLocation::query()->findOrFail($request->user()->resolveOrganizationLocationId());
    }

    private function settings(Request $request): OrganizationSetting
    {
        $settings = OrganizationSetting::forOrganization($request->user()->resolveOrganizationLocationId());
        $settings->load('organizationLocation');

        return $settings;
    }

    private function respond(OrganizationSetting $settings, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new OrganizationSettingResource($settings),
            'message' => $message,
        ]);
    }

    /** Only after the new value is saved; never touches an outside URL. */
    private function deleteLocalFile(?string $url): void
    {
        if (! $url || ! PublicStorageUrl::isOwnStoragePath($url)) {
            return;
        }

        try {
            Storage::disk('public')->delete(PublicStorageUrl::diskRelativePath($url));
        } catch (\Throwable $e) {
            Log::warning('Could not delete old organization branding image.', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
