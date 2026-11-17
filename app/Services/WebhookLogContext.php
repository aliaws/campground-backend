<?php

namespace App\Services;

use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The inbound webhook log currently being processed. While one is active,
 * records created or updated by the handler link themselves to it (see
 * App\Models\Concerns\LinksToWebhookLog).
 */
class WebhookLogContext
{
    private ?WebhookLog $log = null;

    public function start(WebhookLog $log): void
    {
        $this->log = $log;
    }

    public function stop(): void
    {
        $this->log = null;
    }

    public function current(): ?WebhookLog
    {
        return $this->log;
    }

    public function link(Model $record): void
    {
        $this->log?->linkTo($record);
    }
}
