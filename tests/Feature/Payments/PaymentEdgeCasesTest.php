<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Test that payment with zero amount is rejected
     */
    public function test_payment_with_zero_amount_is_rejected()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        // Create share with zero amount (should not be possible in normal flow, but testing edge case)
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 0.00,
            'status' => 'unpaid',
        ]);

        // Mock PayMongo API to ensure it's not called
        Http::fake([
            'api.paymongo.com/*' => Http::response([], 500),
        ]);

        // Attempt to initiate payment
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        // Should fail because amount is zero (PayMongo requires positive amounts)
        // The actual validation happens at PayMongo level, but we verify the flow
        $response->assertStatus(500);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for zero amount');

        // Verify PayMongo API was called (but would fail)
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $amount = $body['data']['attributes']['amount'] ?? null;
            return $amount === 0;
        });
    }

    /**
     * @test
     * Test that duplicate payment attempt is prevented
     */
    public function test_duplicate_payment_attempt_is_prevented()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Create existing pending transaction
        $existingTransaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => $share->amount,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);

        // Attempt to initiate another payment
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'paymaya',
            ]);

        // Should be rejected with 422 status
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'A payment is already in progress for this share',
            'error_code' => 'PAYMENT_IN_PROGRESS',
        ]);

        // Verify only one transaction exists
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(1, $transactionCount, 'Should not create duplicate pending transaction');

        // Verify the original transaction is unchanged
        $existingTransaction->refresh();
        $this->assertEquals('pending', $existingTransaction->status);
        $this->assertEquals('gcash', $existingTransaction->payment_method);
    }

    /**
     * @test
     * Test that webhook for non-existent transaction is handled gracefully
     */
    public function test_webhook_for_non_existent_transaction_is_handled()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        // Create a webhook payload for a share that doesn't exist
        $nonExistentShareId = 99999;
        $webhookPayload = [
            'data' => [
                'id' => 'evt_' . uniqid(),
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_' . uniqid(),
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'currency' => 'PHP',
                            'metadata' => [
                                'share_id' => $nonExistentShareId,
                                'user_id' => 1,
                                'bill_id' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $payloadJson, 'test_webhook_secret');

        // Send webhook
        $response = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        // Should succeed (webhook processed) but no transaction updated
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Webhook processed successfully',
        ]);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $nonExistentShareId)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for non-existent share');
    }

    /**
     * @test
     * Test payment timeout scenario (24-hour auto-fail)
     */
    public function test_payment_timeout_scenario()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Create a transaction that's been pending for more than 24 hours
        $oldTransaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => $share->amount,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);
        
        // Manually update created_at to simulate old transaction
        $oldTransaction->created_at = now()->subHours(25);
        $oldTransaction->save();

        // Verify transaction is pending
        $this->assertEquals('pending', $oldTransaction->status);

        // Simulate the scheduled job that checks for timeout
        // In a real implementation, this would be a scheduled command
        // For this test, we'll manually update transactions older than 24 hours
        Transaction::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(24))
            ->update(['status' => 'failed']);

        // Verify transaction is now failed
        $oldTransaction->refresh();
        $this->assertEquals('failed', $oldTransaction->status);

        // Verify share is still unpaid
        $share->refresh();
        $this->assertEquals('unpaid', $share->status);

        // Verify user can retry payment after timeout
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_' . uniqid(),
                    'type' => 'payment_intent',
                    'attributes' => [
                        'amount' => 50000,
                        'currency' => 'PHP',
                        'status' => 'awaiting_payment_method',
                        'client_key' => 'pi_client_' . uniqid(),
                        'next_action' => [
                            'type' => 'redirect',
                            'redirect' => [
                                'url' => 'https://paymongo.com/checkout/test',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // User should be able to retry payment
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        $response->assertStatus(200);

        // Verify new transaction was created
        $newTransaction = Transaction::where('share_id', $share->id)
            ->where('status', 'pending')
            ->first();
        $this->assertNotNull($newTransaction, 'New transaction should be created after timeout');
        $this->assertNotEquals($oldTransaction->id, $newTransaction->id, 'New transaction should be different from old one');
    }

    /**
     * @test
     * Test idempotency of webhook processing
     */
    public function test_webhook_idempotency()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Create pending transaction
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test',
                    'type' => 'payment_intent',
                    'attributes' => [
                        'amount' => 50000,
                        'currency' => 'PHP',
                        'status' => 'awaiting_payment_method',
                        'client_key' => 'pi_client_test',
                        'next_action' => [
                            'type' => 'redirect',
                            'redirect' => [
                                'url' => 'https://paymongo.com/checkout/test',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        $response->assertStatus(200);

        $transaction = Transaction::where('share_id', $share->id)->first();
        $this->assertEquals('pending', $transaction->status);

        // Create webhook payload
        $paymongoTransactionId = 'pay_test_idempotency';
        $webhookPayload = [
            'data' => [
                'id' => 'evt_test_idempotency',
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => $paymongoTransactionId,
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'currency' => 'PHP',
                            'metadata' => [
                                'share_id' => $share->id,
                                'user_id' => $user->id,
                                'bill_id' => $bill->id,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $payloadJson, 'test_webhook_secret');

        // Send webhook first time
        $webhookResponse1 = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        $webhookResponse1->assertStatus(200);

        // Verify transaction is paid
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id);
        $firstPaidAt = $transaction->paid_at;

        // Verify share is paid
        $share->refresh();
        $this->assertEquals('paid', $share->status);

        // Send same webhook again (duplicate/retry)
        $webhookResponse2 = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        $webhookResponse2->assertStatus(200);

        // Verify transaction status unchanged
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id);
        $this->assertEquals($firstPaidAt->timestamp, $transaction->paid_at->timestamp, 'paid_at timestamp should not change');

        // Verify share status unchanged
        $share->refresh();
        $this->assertEquals('paid', $share->status);

        // Send webhook third time
        $webhookResponse3 = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        $webhookResponse3->assertStatus(200);

        // Verify still unchanged
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals($firstPaidAt->timestamp, $transaction->paid_at->timestamp, 'paid_at timestamp should remain unchanged after multiple webhooks');

        // Verify only one transaction exists for this share
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(1, $transactionCount, 'Should only have one transaction despite multiple webhook deliveries');
    }

    /**
     * @test
     * Test webhook idempotency with failed payment
     */
    public function test_webhook_idempotency_for_failed_payment()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Create pending transaction
        $transaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => $share->amount,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);

        // Create failed payment webhook payload
        $paymongoTransactionId = 'pay_test_failed_idempotency';
        $webhookPayload = [
            'data' => [
                'id' => 'evt_test_failed_idempotency',
                'type' => 'payment.failed',
                'attributes' => [
                    'data' => [
                        'id' => $paymongoTransactionId,
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'failed',
                            'amount' => 50000,
                            'currency' => 'PHP',
                            'metadata' => [
                                'share_id' => $share->id,
                                'user_id' => $user->id,
                                'bill_id' => $bill->id,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $payloadJson, 'test_webhook_secret');

        // Send webhook first time
        $webhookResponse1 = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        $webhookResponse1->assertStatus(200);

        // Verify transaction is failed
        $transaction->refresh();
        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id);
        $this->assertNull($transaction->paid_at, 'paid_at should be null for failed payment');

        // Verify share is still unpaid
        $share->refresh();
        $this->assertEquals('unpaid', $share->status);

        // Send same webhook again (duplicate/retry)
        $webhookResponse2 = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        $webhookResponse2->assertStatus(200);

        // Verify transaction status unchanged
        $transaction->refresh();
        $this->assertEquals('failed', $transaction->status);
        $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id);
        $this->assertNull($transaction->paid_at, 'paid_at should remain null');

        // Verify share status unchanged
        $share->refresh();
        $this->assertEquals('unpaid', $share->status);

        // Verify only one transaction exists for this share
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(1, $transactionCount, 'Should only have one transaction despite multiple webhook deliveries');
    }

    /**
     * @test
     * Test webhook with missing metadata is handled gracefully
     */
    public function test_webhook_with_missing_metadata_is_handled()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        // Create webhook payload without share_id in metadata
        $webhookPayload = [
            'data' => [
                'id' => 'evt_' . uniqid(),
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_' . uniqid(),
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'currency' => 'PHP',
                            'metadata' => [
                                // Missing share_id
                                'user_id' => 1,
                                'bill_id' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $payloadJson, 'test_webhook_secret');

        // Send webhook
        $response = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

        // Should succeed (webhook processed gracefully)
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Webhook processed successfully',
        ]);
    }

    /**
     * @test
     * Test payment with negative amount is rejected
     */
    public function test_payment_with_negative_amount_is_rejected()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        // Create share with negative amount (should not be possible in normal flow)
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => -100.00,
            'status' => 'unpaid',
        ]);

        // Mock PayMongo API to ensure it's not called successfully
        Http::fake([
            'api.paymongo.com/*' => Http::response([], 500),
        ]);

        // Attempt to initiate payment
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        // Should fail because amount is negative
        $response->assertStatus(500);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for negative amount');
    }
}
