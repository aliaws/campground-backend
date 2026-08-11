<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GhlSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'total_contacts_pulled' => $this->total_contacts_pulled,
            'total_categories_pulled' => $this->total_categories_pulled,
            'total_service_categories_pulled' => $this->total_service_categories_pulled,
            'total_products_pulled' => $this->total_products_pulled,
            'total_services_pulled' => $this->total_services_pulled,
            'total_rentals_pulled' => $this->total_rentals_pulled,
            'total_paid_bookings_pulled' => $this->total_paid_bookings_pulled,
            'total_paid_invoices_pulled' => $this->total_paid_invoices_pulled,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'duration_ms' => $this->duration_ms,
            'error_message' => $this->error_message,
            'phase_errors' => $this->phase_errors,
        ];
    }
}
