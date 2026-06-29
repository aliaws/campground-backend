<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\GHL\GhlWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private GhlWebhookHandler $handler,
    ) {}

    public function ghl(Request $request): JsonResponse
    {
        $result = $this->handler->handle($request);

        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
