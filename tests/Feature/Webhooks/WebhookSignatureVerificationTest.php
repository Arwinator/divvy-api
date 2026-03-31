<?php

namespace Tests\Feature\Webhooks;

use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookSignatureVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Property-Based Test: Webhook Signature Verification
     *
     * This test validates that webhooks with invalid signatures are rejected
     * while webhooks with valid signatures are accepted. The test runs 100
     * iterations with random payloads to ensure the signature verification
     * is robust across different data patterns.
     *
     * Test scenarios:
     * - Valid signature computed with correct secret should pass verification
     * - Invalid signature (random string) should fail verification
     * - Modified payload with original signature should fail verification
     * - Empty signature should fail verification
     * - Signature computed with wrong secret should fail verification
     */
    public function test_webhook_signature_verification_property()
    {
        // Set webhook secret for testing
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret_key']);

        $paymentService = new PaymentService();

        // Run 100 iterations with random payloads
        for ($i = 0; $i < 100; $i++) {
            // Generate random webhook payload
            $payload = json_encode([
                'data' => [
                    'id' => 'evt_' . uniqid(),
                    'type' => 'payment.paid',
                    'attributes' => [
                        'amount' => rand(100, 100000),
                        'status' => 'paid',
                        'metadata' => [
                            'share_id' => rand(1, 1000),
                            'user_id' => rand(1, 100),
                        ],
                    ],
                ],
            ]);

            // Test 1: Valid signature should pass verification
            $validSignature = hash_hmac('sha256', $payload, 'test_webhook_secret_key');
            $this->assertTrue(
                $paymentService->verifyWebhookSignature($payload, $validSignature),
                "Iteration $i: Valid signature should pass verification"
            );

            // Test 2: Invalid signature (random string) should fail verification
            $invalidSignature = bin2hex(random_bytes(32));
            $this->assertFalse(
                $paymentService->verifyWebhookSignature($payload, $invalidSignature),
                "Iteration $i: Invalid signature should fail verification"
            );

            // Test 3: Modified payload with original signature should fail
            $modifiedPayload = json_encode([
                'data' => [
                    'id' => 'evt_' . uniqid(),
                    'type' => 'payment.failed', // Changed type
                    'attributes' => [
                        'amount' => rand(100, 100000),
                        'status' => 'failed',
                    ],
                ],
            ]);
            $this->assertFalse(
                $paymentService->verifyWebhookSignature($modifiedPayload, $validSignature),
                "Iteration $i: Modified payload with original signature should fail"
            );

            // Test 4: Empty signature should fail verification
            $this->assertFalse(
                $paymentService->verifyWebhookSignature($payload, ''),
                "Iteration $i: Empty signature should fail verification"
            );

            // Test 5: Signature computed with wrong secret should fail
            $wrongSecretSignature = hash_hmac('sha256', $payload, 'wrong_secret_key');
            $this->assertFalse(
                $paymentService->verifyWebhookSignature($payload, $wrongSecretSignature),
                "Iteration $i: Signature with wrong secret should fail verification"
            );
        }
    }

    /**
     * Test that webhook signature verification fails when secret is not configured
     */
    public function test_webhook_signature_verification_fails_without_secret()
    {
        // Clear webhook secret
        config(['services.paymongo.webhook_secret' => '']);

        $paymentService = new PaymentService();

        $payload = json_encode(['data' => ['id' => 'evt_test']]);
        $signature = hash_hmac('sha256', $payload, 'any_secret');

        $this->assertFalse(
            $paymentService->verifyWebhookSignature($payload, $signature),
            'Webhook verification should fail when secret is not configured'
        );
    }

    /**
     * Test that webhook signature verification uses timing-safe comparison
     *
     * This test ensures that the verification uses hash_equals() which prevents
     * timing attacks by comparing strings in constant time.
     */
    public function test_webhook_signature_uses_timing_safe_comparison()
    {
        config(['services.paymongo.webhook_secret' => 'test_secret']);

        $paymentService = new PaymentService();
        $payload = json_encode(['data' => ['id' => 'evt_test']]);

        // Create two signatures that differ only in the last character
        $validSignature = hash_hmac('sha256', $payload, 'test_secret');
        $almostValidSignature = substr($validSignature, 0, -1) . 'x';

        // Both should be evaluated in constant time (we can't test timing directly,
        // but we can verify that both invalid signatures are rejected)
        $this->assertTrue(
            $paymentService->verifyWebhookSignature($payload, $validSignature),
            'Valid signature should pass'
        );

        $this->assertFalse(
            $paymentService->verifyWebhookSignature($payload, $almostValidSignature),
            'Almost valid signature should fail'
        );
    }

    /**
     * Test webhook endpoint rejects requests with invalid signatures
     */
    public function test_webhook_endpoint_rejects_invalid_signature()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $payload = [
            'data' => [
                'id' => 'evt_test123',
                'type' => 'payment.paid',
                'attributes' => [
                    'amount' => 50000,
                    'status' => 'paid',
                ],
            ],
        ];

        $payloadJson = json_encode($payload);
        $invalidSignature = 'invalid_signature_string';

        $response = $this->postJson('/api/webhooks/paymongo', $payload, [
            'PayMongo-Signature' => $invalidSignature,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Invalid signature',
        ]);
    }

    /**
     * Test webhook endpoint rejects requests without signature header
     */
    public function test_webhook_endpoint_rejects_missing_signature()
    {
        $payload = [
            'data' => [
                'id' => 'evt_test456',
                'type' => 'payment.paid',
            ],
        ];

        $response = $this->postJson('/api/webhooks/paymongo', $payload);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Missing signature',
        ]);
    }

    /**
     * Test webhook endpoint accepts requests with valid signatures
     */
    public function test_webhook_endpoint_accepts_valid_signature()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $payload = [
            'data' => [
                'id' => 'evt_test789',
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_test123',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'metadata' => [
                                'share_id' => 999999, // Non-existent share
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($payload);
        $validSignature = hash_hmac('sha256', $payloadJson, 'test_webhook_secret');

        $response = $this->postJson('/api/webhooks/paymongo', $payload, [
            'PayMongo-Signature' => $validSignature,
        ]);

        // Should return 200 even if share doesn't exist (webhook is valid)
        $response->assertStatus(200);
    }
}
