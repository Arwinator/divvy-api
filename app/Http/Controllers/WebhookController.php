<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class WebhookController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle PayMongo webhook callbacks
     *
     * POST /api/webhooks/paymongo
     */
    public function handlePayMongo(Request $request)
    {
        try {
            // Get raw payload and signature
            $payload = $request->getContent();
            $signature = $request->header('PayMongo-Signature');

            // Log webhook received
            Log::channel('payment')->info('PayMongo webhook received', [
                'has_signature' => !empty($signature),
                'payload_size' => strlen($payload),
            ]);

            // Verify webhook signature
            if (!$signature) {
                Log::channel('payment')->warning('Webhook rejected: missing signature');
                return response()->json([
                    'message' => 'Missing signature',
                    'error_code' => 'MISSING_SIGNATURE',
                ], 400);
            }

            if (!$this->paymentService->verifyWebhookSignature($payload, $signature)) {
                Log::channel('payment')->warning('Webhook rejected: invalid signature');
                return response()->json([
                    'message' => 'Invalid signature',
                    'error_code' => 'INVALID_SIGNATURE',
                ], 401);
            }

            // Parse and handle webhook
            $webhookData = json_decode($payload, true);
            
            if (!$webhookData) {
                Log::channel('payment')->error('Webhook rejected: invalid JSON');
                return response()->json([
                    'message' => 'Invalid JSON payload',
                    'error_code' => 'INVALID_PAYLOAD',
                ], 400);
            }

            // Process webhook event
            $this->paymentService->handleWebhook($webhookData);

            return response()->json([
                'message' => 'Webhook processed successfully',
            ], 200);
        } catch (Exception $e) {
            Log::channel('payment')->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
