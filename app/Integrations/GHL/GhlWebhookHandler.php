<?php

namespace App\Integrations\GHL;

use App\Models\WebhookLog;
use App\Services\GhlService;
use Illuminate\Http\Request;

class GhlWebhookHandler
{
    public function __construct(
        private GhlService $ghlService,
    ) {}

    public function handle(Request $request): array
    {
        $eventType = $request->header('X-Event-Type', $request->input('type', 'unknown'));
        $payload = $request->all();

        $log = WebhookLog::create([
            'source' => 'ghl',
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'received',
        ]);

        try {
            $this->ghlService->handleInboundWebhook($payload, $eventType);
            $log->update(['status' => 'processed']);

            return ['success' => true, 'message' => 'Webhook processed'];
        } catch (\Exception $e) {
            $log->update(['status' => 'failed']);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
