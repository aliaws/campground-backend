<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'product_type' => ['sometimes', Rule::in(['DIGITAL', 'PHYSICAL', 'SERVICE'])],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'draft', 'archived'])],
            'available_in_store' => ['nullable', 'boolean'],
            'image' => ['nullable', 'string', 'max:2048'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'is_taxes_enabled' => ['nullable', 'boolean'],
            'track_product_inventory' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:32', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('engage_products', 'sku')
                    ->where('engage_organization_location_id', $this->user()->resolveOrganizationLocationId())
                    ->ignore($this->route('product')),
            ],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'exists:engage_categories,id'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['string', 'exists:amenities,id'],
            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['string', 'exists:features,id'],
            // Manage Service's Pricing section — display/reference values on
            // the base rental row, not the live GHL booking quote. Harmless
            // to accept generically (the goods form never sends these keys),
            // same convention as amenity_ids/feature_ids above.
            'listing_price' => ['nullable', 'numeric', 'min:0'],
            'service_duration_unit' => ['nullable', Rule::in(['hour', 'day', 'week', 'month'])],
            'security_deposit_amount' => ['nullable', 'numeric', 'min:0'],
            // Manage Service's Category field — a rental's ServiceCategory
            // assignment lives on product_rentals.service_category_id as the
            // raw GHL category id (see EngageProductRental::serviceCategory()),
            // not a local ULID — so this accepts/validates that same raw id
            // directly rather than a second, translated representation.
            // Scoped to this tenant's own ServiceCategory rows only.
            'service_category_id' => [
                'nullable', 'string',
                Rule::exists('engage_product_rental_categories', 'ghl_category_id')
                    ->where('engage_organization_location_id', $this->user()->resolveOrganizationLocationId()),
            ],
            // Manage Service's Variants tab — per-variant overrides (never
            // the base/default rental, which is edited via listing_price/
            // service_duration_unit/security_deposit_amount above instead,
            // so the two sections can never fight over the same row).
            // Ownership (variant actually belongs to this product) is
            // re-verified in ProductService::update(), not trusted from
            // validation alone.
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['required_with:variants', 'string', 'exists:engage_product_rentals,id'],
            'variants.*.listing_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            // Manage Service's Booking Settings tab — bookingPeriodType gets
            // its own column (it drives which fields the form shows/hides);
            // everything else is one JSON object mirroring GHL's own raw
            // field names verbatim, see GhlServiceDetail::
            // bookingSettingsForPersistence(). Unit fields are validated
            // against each field's own specific allowed set (matching
            // BookingSettingsFields.tsx's identical per-field option lists
            // on the frontend) — this is safe to enforce strictly here
            // because this FormRequest only ever gates the staff-initiated
            // save endpoint (ProductController::update()); the GHL pull
            // ingestion path (GhlServiceSyncService::upsertRentalRow())
            // writes straight to the model via Eloquent and never goes
            // through this validation at all, so a real Lead Connector
            // value outside these lists can still be pulled/stored — this
            // only ever blocks a *manual* save from submitting one.
            'booking_period_type' => ['nullable', Rule::in(['date-time-selection', 'date-selection', 'fixed'])],
            'booking_settings' => ['nullable', 'array'],
            'booking_settings.minDuration' => ['nullable', 'numeric', 'min:0'],
            // Minimum/Maximum Duration support only "day" — the edit form's
            // own dropdown for these two is disabled and locked to it.
            'booking_settings.minDurationUnit' => ['nullable', Rule::in(['day'])],
            'booking_settings.maxDuration' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.maxDurationUnit' => ['nullable', Rule::in(['day'])],
            'booking_settings.preBuffer' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.preBufferUnit' => ['nullable', Rule::in(['min', 'hour', 'day'])],
            'booking_settings.postBuffer' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.postBufferUnit' => ['nullable', Rule::in(['min', 'hour', 'day'])],
            'booking_settings.allowBookingAfter' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.allowBookingAfterUnit' => ['nullable', Rule::in(['min', 'hour', 'day', 'week', 'month'])],
            'booking_settings.allowBookingFor' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.allowBookingForUnit' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'booking_settings.hasTimeSelection' => ['nullable', 'boolean'],
            'booking_settings.bookingStartTime' => ['nullable', 'date_format:H:i'],
            'booking_settings.bookingEndTime' => ['nullable', 'date_format:H:i'],
            'booking_settings.serviceDurations' => ['nullable', 'array'],
            // Deliberately nullable, not required — a malformed/incomplete
            // interval entry is filtered out in ProductService::update()
            // before it ever reaches the database, rather than rejecting the
            // entire save over one bad row.
            'booking_settings.serviceDurations.*.duration' => ['nullable', 'numeric', 'min:0'],
            'booking_settings.serviceDurations.*.durationUnit' => ['nullable', 'string', 'max:32'],
        ];
    }
}
