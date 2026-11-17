<?php

namespace App\Models\Concerns;

use App\Services\WebhookLogContext;

/**
 * A record created or changed while an inbound webhook is being processed is
 * linked to that webhook's log row.
 */
trait LinksToWebhookLog
{
    protected static function bootLinksToWebhookLog(): void
    {
        static::created(fn ($record) => app(WebhookLogContext::class)->link($record));
        static::updated(fn ($record) => app(WebhookLogContext::class)->link($record));
    }
}
