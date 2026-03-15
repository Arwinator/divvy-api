<?php

namespace App\Services;

use App\Models\Share;
use App\Models\Transaction;
use App\Jobs\SendPushNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymentService
{
    private string $secretKey;
    private string $webhookSecret;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.paymongo.secret_key');
        $this->webhookSecret = config('services.paymongo.webhook_secret');
    }

    /**
     * Create a PayMongo payment intent for a share payment
     *
     * @param Share $share
     * @param string $paymentMethod ('gcash' or 'paymaya')
     * @return array Payment intent data with checkout URL
     * @throws Exception
     */
    public function createPaymentIntent(Share $share, string $paymentMethod): array
    {
        try {
            // Convert amount to cents (PayMongo requires amount in cents)
            $amountInCents = (int) ($share->amount * 100);

            // Prepare payment intent data
            $data = [
                'data' => [
                    'attributes' => [
                        'amount' => $amountInCents,
                        'payment_method_allowed' => [$paymentMethod],
                        'payment_method_options' => [
                            $paymentMethod => [
                                'redirect' => [
                                    'success' => config('app.url') . '/payment/success',
                                    'failed' => config('app.url') . '/payment/failed',
                                ]
                            ]
                        ],
                        'currency' => 'PHP',
                        'description' => "Payment for {$share->bill->title}",
                        'statement_descriptor' => 'Divvy Bill Payment',
                        'metadata' => [
                            'share_id' => $share->id,
                            'user_id' => $share->user_id,
                            'bill_id' => $share->bill_id,
                        ]
                    ]
                ]
            ];

            // Log payment intent creation attempt
            Log::channel('payment')->info('Creating PayMongo payment intent', [
                'share_id' => $share->id,
                'user_id' => $share->user_id,
                'amount' => $share->amount,
                'payment_method' => $paymentMethod,
            ]);

            // Make API request to PayMongo
            $response = Http::withBasicAuth($this->secretKey, '')
                ->post("{$this->baseUrl}/payment_intents", $data);

            if (!$response->successful()) {
                Log::channel('payment')->error('PayMongo payment intent creation failed', [
                    'share_id' => $share->id,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new Exception('Failed to create payment intent: ' . $response->body());
            }

            $responseData = $response->json();
            $paymentIntent = $responseData['data'];

            // Log successful creation
            Log::channel('payment')->info('PayMongo payment intent created successfully', [
                'share_id' => $share->id,
                'payment_intent_id' => $paymentIntent['id'],
            ]);

            // Extract checkout URL from the next_action
            $checkoutUrl = $paymentIntent['attributes']['next_action']['redirect']['url'] ?? null;

            return [
                'payment_intent' => [
                    'id' => $paymentIntent['id'],
                    'client_key' => $paymentIntent['attributes']['client_key'],
                    'status' => $paymentIntent['attributes']['status'],
                ],
                'checkout_url' => $checkoutUrl,
            ];
        } catch (Exception $e) {
            Log::channel('payment')->error('Payment intent creation exception', [
                'share_id' => $share->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify PayMongo webhook signature
     *
     * @param string $payload Raw request body
     * @param string $signature Signature from PayMongo-Signature header
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            Log::channel('payment')->warning('Webhook secret not configured');
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        $isValid = hash_equals($computedSignature, $signature);

        Log::channel('payment')->info('Webhook signature verification', [
            'is_valid' => $isValid,
        ]);

        return $isValid;
    }

    /**
     * Handle PayMongo webhook event
     *
     * @param array $payload Webhook payload
     * @return void
     * @throws Exception
     */
    public function handleWebhook(array $payload): void
    {
        try {
            $event = $payload['data'];
            $eventType = $event['type'];

            Log::channel('payment')->info('Processing PayMongo webhook', [
                'event_type' => $eventType,
                'event_id' => $event['id'],
            ]);

            if ($eventType === 'payment.paid') {
                $this->handlePaymentPaid($event);
            } elseif ($eventType === 'payment.failed') {
                $this->handlePaymentFailed($event);
            } else {
                Log::channel('payment')->info('Unhandled webhook event type', [
                    'event_type' => $eventType,
                ]);
            }
        } catch (Exception $e) {
            Log::channel('payment')->error('Webhook processing exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle payment.paid webhook event
     *
     * @param array $event
     * @return void
     */
    private function handlePaymentPaid(array $event): void
    {
        $paymentData = $event['attributes']['data'];
        $metadata = $paymentData['attributes']['metadata'] ?? [];
        $shareId = $metadata['share_id'] ?? null;

        if (!$shareId) {
            Log::channel('payment')->warning('Payment paid webhook missing share_id in metadata');
            return;
        }

        // Find the pending transaction for this share
        $transaction = Transaction::where('share_id', $shareId)
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            Log::channel('payment')->warning('No pending transaction found for share', [
                'share_id' => $shareId,
            ]);
            return;
        }

        // Update transaction status
        $transaction->update([
            'status' => 'paid',
            'paymongo_transaction_id' => $paymentData['id'],
            'paid_at' => now(),
        ]);

        // Update share status
        $transaction->share->update(['status' => 'paid']);

        Log::channel('payment')->info('Payment marked as paid', [
            'transaction_id' => $transaction->id,
            'share_id' => $shareId,
            'paymongo_transaction_id' => $paymentData['id'],
        ]);

        // Send notification to bill creator
        $bill = $transaction->share->bill;
        SendPushNotification::dispatch(
            $bill->creator_id,
            'Payment Received',
            "{$transaction->user->username} paid their share for {$bill->title}",
            [
                'type' => 'payment_received',
                'bill_id' => $bill->id,
                'share_id' => $shareId,
            ]
        );

        // Check if bill is fully settled
        $unpaidShares = $bill->shares()->where('status', 'unpaid')->count();
        if ($unpaidShares === 0) {
            // Notify all group members that bill is fully settled
            $groupMembers = $bill->group->members;
            foreach ($groupMembers as $member) {
                SendPushNotification::dispatch(
                    $member->id,
                    'Bill Fully Settled',
                    "All payments received for {$bill->title}",
                    [
                        'type' => 'bill_settled',
                        'bill_id' => $bill->id,
                    ]
                );
            }

            Log::channel('payment')->info('Bill fully settled', [
                'bill_id' => $bill->id,
            ]);
        }
    }

    /**
     * Handle payment.failed webhook event
     *
     * @param array $event
     * @return void
     */
    private function handlePaymentFailed(array $event): void
    {
        $paymentData = $event['attributes']['data'];
        $metadata = $paymentData['attributes']['metadata'] ?? [];
        $shareId = $metadata['share_id'] ?? null;

        if (!$shareId) {
            Log::channel('payment')->warning('Payment failed webhook missing share_id in metadata');
            return;
        }

        // Find the pending transaction for this share
        $transaction = Transaction::where('share_id', $shareId)
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            Log::channel('payment')->warning('No pending transaction found for share', [
                'share_id' => $shareId,
            ]);
            return;
        }

        // Update transaction status
        $transaction->update([
            'status' => 'failed',
            'paymongo_transaction_id' => $paymentData['id'],
        ]);

        Log::channel('payment')->info('Payment marked as failed', [
            'transaction_id' => $transaction->id,
            'share_id' => $shareId,
            'paymongo_transaction_id' => $paymentData['id'],
        ]);

        // Send notification to user about failed payment
        SendPushNotification::dispatch(
            $transaction->user_id,
            'Payment Failed',
            "Your payment for {$transaction->share->bill->title} failed. Please try again.",
            [
                'type' => 'payment_failed',
                'share_id' => $shareId,
            ]
        );
    }
}
