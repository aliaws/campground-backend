<?php

namespace App\Integrations\GHL;

use App\Services\GhlService;
use Illuminate\Http\Request;

class GhlWebhookHandler
{
    public function __construct(
        private GhlService $ghlService,
    ) {}

    /**
     * GhlService::handleInboundWebhook() writes the webhook_logs row (and its
     * processed / ignored / failed status). This used to log a second row of
     * its own, so every webhook was stored twice.
     */
    public function handle(Request $request): array
    {
        $eventType = $request->header('X-Event-Type', $request->input('type', 'unknown'));

        try {
            $this->ghlService->handleInboundWebhook($request->all(), $eventType);

            return ['success' => true, 'message' => 'Webhook processed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
