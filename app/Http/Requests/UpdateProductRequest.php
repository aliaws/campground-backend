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
            // Inventory & Pricing tab's per-variant Stock field — same
            // local-only, base-row-excluded convention as listing_price/
            // is_active above (see ProductService::update()'s doc comment).
            // Null means "unlimited" (matches GhlServiceDetail::quantity()),
            // so it's nullable rather than required. The base rental's own
            // entry is synthesized by the frontend and also flows through
            // this same array (see ProductService::update()'s handling) —
            // carrying only `id`/`quantity`/`pricing_rules`, never
            // `listing_price`/`is_active`.
            'variants.*.quantity' => ['nullable', 'integer', 'min:0'],
            // Real Lead Connector `hasQuantityEnabled` flag, per-row (unlike
            // is_variants_enabled below, this one is genuinely meaningful
            // per variant) — the frontend's one shared "Inventory" toggle
            // sends the same value for every row in this array, base
            // included, so every row stays consistent with what's shown.
            'variants.*.has_quantity_enabled' => ['nullable', 'boolean'],
            // Advanced Pricing discount rules — replaces the row's entire
            // pricing_rules array on save (same "whole object" convention as
            // booking_settings), field names confirmed against a real
            // captured Lead Connector response, not guessed. `match`'s
            // meaningful sub-keys vary by `type` (date_range: from/to,
            // day_of_week: dayOfWeek, duration_discount: duration/
            // durationUnit, quantity_discount: min) — all left individually
            // nullable/optional here (the frontend editor only ever submits
            // the sub-keys relevant to each rule's own type) rather than
            // conditionally required per type, matching this codebase's
            // existing lenient-nested-array precedent (see
            // booking_settings.serviceDurations.* above).
            'variants.*.pricing_rules' => ['nullable', 'array'],
            'variants.*.pricing_rules.*.type' => ['required_with:variants.*.pricing_rules', Rule::in(['date_range', 'day_of_week', 'duration_discount', 'quantity_discount'])],
            'variants.*.pricing_rules.*.value' => ['required_with:variants.*.pricing_rules', 'numeric'],
            'variants.*.pricing_rules.*.valueType' => ['nullable', Rule::in(['percentage', 'flat'])],
            'variants.*.pricing_rules.*.sequence' => ['nullable', 'integer'],
            'variants.*.pricing_rules.*.match' => ['nullable', 'array'],
            'variants.*.pricing_rules.*.match.from' => ['nullable', 'string'],
            'variants.*.pricing_rules.*.match.to' => ['nullable', 'string'],
            'variants.*.pricing_rules.*.match.dayOfWeek' => ['nullable', 'integer', 'min:0', 'max:6'],
            'variants.*.pricing_rules.*.match.duration' => ['nullable', 'integer', 'min:0'],
            'variants.*.pricing_rules.*.match.durationUnit' => ['nullable', 'string', 'max:32'],
            'variants.*.pricing_rules.*.match.min' => ['nullable', 'integer', 'min:0'],
            // Inventory & Pricing tab's real, staff-editable Variants
            // switch — persisted onto the base rental (same base-only
            // convention as booking_period_type below) and pushed to Lead
            // Connector as an explicit isVariantsEnabled value instead of
            // the previous rentals-count-derived guess (see
            // GhlServiceSyncService::buildServiceUpdatePayload()).
            'is_variants_enabled' => ['nullable', 'boolean'],
            // Base rental's own has_quantity_enabled — see the
            // variants.*.has_quantity_enabled comment above; this top-level
            // key is what actually reaches the base row (via
            // ProductService::update()'s base-rental-only $rentalData
            // intersect), same convention as booking_period_type below.
            'has_quantity_enabled' => ['nullable', 'boolean'],
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
