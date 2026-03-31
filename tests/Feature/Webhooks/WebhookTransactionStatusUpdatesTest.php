<?php

namespace Tests\Feature\Webhooks;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Property-Based Test: Webhook Transaction Status Updates
 * 
 * This test validates that webhook events correctly update transaction
 * and share status for both successful and failed payments.
 */
class WebhookTransactionStatusUpdatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function webhook_updates_transaction_and_share_status_for_successful_payment()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            $shareAmount = round(mt_rand(100, 1000000) / 100, 2);
            $paymentMethods = ['gcash', 'paymaya'];
            $paymentMethod = $paymentMethods[mt_rand(0, 1)];

            $user = User::factory()->create([
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => 'user_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);

            $group = Group::factory()->create([
                'creator_id' => $creator->id,
                'name' => 'Group_' . $i . '_' . uniqid(),
            ]);
            
            $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => "Bill_" . $i . "_" . uniqid(),
                'total_amount' => $shareAmount * 2,
                'bill_date' => now()->subDays(mt_rand(0, 30)),
            ]);

            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);

            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => 'pi_' . uniqid(),
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => (int) ($shareAmount * 100),
                            'currency' => 'PHP',
                            'status' => 'awaiting_payment_method',
                            'client_key' => 'pi_client_' . uniqid(),
                            'next_action' => [
                                'type' => 'redirect',
                                'redirect' => ['url' => 'https://paymongo.com/checkout/test'],
                            ],
                        ],
                    ],
                ], 200),
            ]);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/shares/{$share->id}/pay", [
                    'payment_method' => $paymentMethod,
                ]);

            $response->assertStatus(200);

            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertNotNull($transaction, "Iteration $i: Transaction should be created");
            $this->assertEquals('pending', $transaction->status, "Iteration $i: Initial status should be 'pending'");

            $share->refresh();
            $this->assertEquals('unpaid', $share->status, "Iteration $i: Share should remain 'unpaid' initially");

            $paymongoTransactionId = 'pay_' . uniqid();
            $webhookPayload = [
                'data' => [
                    'id' => 'evt_' . uniqid(),
                    'type' => 'payment.paid',
                    'attributes' => [
                        'data' => [
                            'id' => $paymongoTransactionId,
                            'type' => 'payment',
                            'attributes' => [
                                'status' => 'paid',
                                'amount' => (int) ($shareAmount * 100),
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

            $webhookResponse = $this->withHeaders([
                'PayMongo-Signature' => $signature,
            ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

            $webhookResponse->assertStatus(200);

            $transaction->refresh();
            $share->refresh();

            $this->assertEquals('paid', $transaction->status, "Iteration $i: Transaction status should be 'paid'");
            $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id, "Iteration $i: PayMongo transaction ID should be set");
            $this->assertNotNull($transaction->paid_at, "Iteration $i: paid_at timestamp should be set");
            $this->assertEquals('paid', $share->status, "Iteration $i: Share status should be 'paid'");

            Http::clearResolvedInstances();
        }
    }

    /**
     * @test
     */
    public function webhook_updates_transaction_status_for_failed_payment()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            $shareAmount = round(mt_rand(100, 1000000) / 100, 2);
            $paymentMethods = ['gcash', 'paymaya'];
            $paymentMethod = $paymentMethods[mt_rand(0, 1)];

            $user = User::factory()->create([
                'username' => 'user_fail_' . $i . '_' . uniqid(),
                'email' => 'user_fail_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $creator = User::factory()->create([
                'username' => 'creator_fail_' . $i . '_' . uniqid(),
                'email' => 'creator_fail_' . $i . '_' . uniqid() . '@test.com',
            ]);

            $group = Group::factory()->create([
                'creator_id' => $creator->id,
                'name' => 'Group_fail_' . $i . '_' . uniqid(),
            ]);
            
            $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => "Bill_fail_" . $i . "_" . uniqid(),
                'total_amount' => $shareAmount * 2,
                'bill_date' => now()->subDays(mt_rand(0, 30)),
            ]);

            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);

            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => 'pi_' . uniqid(),
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => (int) ($shareAmount * 100),
                            'currency' => 'PHP',
                            'status' => 'awaiting_payment_method',
                            'client_key' => 'pi_client_' . uniqid(),
                            'next_action' => [
                                'type' => 'redirect',
                                'redirect' => ['url' => 'https://paymongo.com/checkout/test'],
                            ],
                        ],
                    ],
                ], 200),
            ]);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/shares/{$share->id}/pay", [
                    'payment_method' => $paymentMethod,
                ]);

            $response->assertStatus(200);

            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertNotNull($transaction, "Iteration $i: Transaction should be created");
            $this->assertEquals('pending', $transaction->status, "Iteration $i: Initial status should be 'pending'");

            $paymongoTransactionId = 'pay_fail_' . uniqid();
            $webhookPayload = [
                'data' => [
                    'id' => 'evt_fail_' . uniqid(),
                    'type' => 'payment.failed',
                    'attributes' => [
                        'data' => [
                            'id' => $paymongoTransactionId,
                            'type' => 'payment',
                            'attributes' => [
                                'status' => 'failed',
                                'amount' => (int) ($shareAmount * 100),
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

            $webhookResponse = $this->withHeaders([
                'PayMongo-Signature' => $signature,
            ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

            $webhookResponse->assertStatus(200);

            $transaction->refresh();
            $share->refresh();

            $this->assertEquals('failed', $transaction->status, "Iteration $i: Transaction status should be 'failed'");
            $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id, "Iteration $i: PayMongo transaction ID should be set");
            $this->assertNull($transaction->paid_at, "Iteration $i: paid_at should be null for failed payment");
            $this->assertEquals('unpaid', $share->status, "Iteration $i: Share status should remain 'unpaid' for failed payment");

            Http::clearResolvedInstances();
        }
    }


    /**
     * @test
     */
    public function webhook_handles_multiple_status_transitions_correctly()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1500.00,
        ]);

        // Create 3 shares for the same user
        $shares = [];
        for ($i = 0; $i < 3; $i++) {
            $shares[] = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 500.00,
                'status' => 'unpaid',
            ]);
        }

        // Initiate payments for all shares
        foreach ($shares as $index => $share) {
            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => 'pi_' . $index,
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => 50000,
                            'currency' => 'PHP',
                            'status' => 'awaiting_payment_method',
                            'client_key' => 'pi_client_' . $index,
                            'next_action' => ['type' => 'redirect', 'redirect' => ['url' => 'https://paymongo.com/checkout/test']],
                        ],
                    ],
                ], 200),
            ]);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/shares/{$share->id}/pay", ['payment_method' => 'gcash']);
            $response->assertStatus(200);
            Http::clearResolvedInstances();
        }

        // Verify all transactions are pending
        $transactions = Transaction::whereIn('share_id', array_map(fn($s) => $s->id, $shares))->get();
        $this->assertCount(3, $transactions);
        foreach ($transactions as $transaction) {
            $this->assertEquals('pending', $transaction->status);
        }

        // First payment succeeds
        $webhookPayload1 = [
            'data' => [
                'id' => 'evt_1',
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_1',
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'metadata' => ['share_id' => $shares[0]->id, 'user_id' => $user->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature1 = hash_hmac('sha256', json_encode($webhookPayload1), 'test_webhook_secret');
        $webhookResponse1 = $this->withHeaders(['PayMongo-Signature' => $signature1])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload1);
        $webhookResponse1->assertStatus(200);

        // Second payment fails
        $webhookPayload2 = [
            'data' => [
                'id' => 'evt_2',
                'type' => 'payment.failed',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_2',
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'failed',
                            'amount' => 50000,
                            'metadata' => ['share_id' => $shares[1]->id, 'user_id' => $user->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature2 = hash_hmac('sha256', json_encode($webhookPayload2), 'test_webhook_secret');
        $webhookResponse2 = $this->withHeaders(['PayMongo-Signature' => $signature2])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload2);
        $webhookResponse2->assertStatus(200);

        // Third payment succeeds
        $webhookPayload3 = [
            'data' => [
                'id' => 'evt_3',
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_3',
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'metadata' => ['share_id' => $shares[2]->id, 'user_id' => $user->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature3 = hash_hmac('sha256', json_encode($webhookPayload3), 'test_webhook_secret');
        $webhookResponse3 = $this->withHeaders(['PayMongo-Signature' => $signature3])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload3);
        $webhookResponse3->assertStatus(200);

        // Verify final states
        $transactions->each(fn($t) => $t->refresh());
        $transaction1 = Transaction::where('share_id', $shares[0]->id)->first();
        $transaction2 = Transaction::where('share_id', $shares[1]->id)->first();
        $transaction3 = Transaction::where('share_id', $shares[2]->id)->first();

        $this->assertEquals('paid', $transaction1->status);
        $this->assertNotNull($transaction1->paid_at);
        $this->assertEquals('failed', $transaction2->status);
        $this->assertNull($transaction2->paid_at);
        $this->assertEquals('paid', $transaction3->status);
        $this->assertNotNull($transaction3->paid_at);

        // Verify share statuses
        $shares[0]->refresh();
        $shares[1]->refresh();
        $shares[2]->refresh();

        $this->assertEquals('paid', $shares[0]->status);
        $this->assertEquals('unpaid', $shares[1]->status);
        $this->assertEquals('paid', $shares[2]->status);
    }

    /**
     * @test
     */
    public function webhook_ignores_duplicate_payment_events()
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
                        'next_action' => ['type' => 'redirect', 'redirect' => ['url' => 'https://paymongo.com/checkout/test']],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", ['payment_method' => 'gcash']);
        $response->assertStatus(200);

        $webhookPayload = [
            'data' => [
                'id' => 'evt_test',
                'type' => 'payment.paid',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_test',
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'paid',
                            'amount' => 50000,
                            'metadata' => ['share_id' => $share->id, 'user_id' => $user->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature = hash_hmac('sha256', json_encode($webhookPayload), 'test_webhook_secret');

        // Send webhook first time
        $webhookResponse1 = $this->withHeaders(['PayMongo-Signature' => $signature])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse1->assertStatus(200);

        $transaction = Transaction::where('share_id', $share->id)->first();
        $this->assertEquals('paid', $transaction->status);
        $firstPaidAt = $transaction->paid_at;

        // Send same webhook again (duplicate)
        $webhookResponse2 = $this->withHeaders(['PayMongo-Signature' => $signature])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse2->assertStatus(200);

        // Verify transaction status unchanged and paid_at timestamp unchanged
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals($firstPaidAt->timestamp, $transaction->paid_at->timestamp);
    }

    /**
     * @test
     */
    public function webhook_does_not_update_already_paid_transaction()
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
            'status' => 'paid',
        ]);

        // Create a transaction that's already paid
        $transaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_original',
            'status' => 'paid',
            'paid_at' => now()->subHours(2),
        ]);

        $originalPaidAt = $transaction->paid_at;
        $originalPaymongoId = $transaction->paymongo_transaction_id;

        // Try to send a failed webhook for the same share
        $webhookPayload = [
            'data' => [
                'id' => 'evt_test',
                'type' => 'payment.failed',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_new_attempt',
                        'type' => 'payment',
                        'attributes' => [
                            'status' => 'failed',
                            'amount' => 50000,
                            'metadata' => ['share_id' => $share->id, 'user_id' => $user->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature = hash_hmac('sha256', json_encode($webhookPayload), 'test_webhook_secret');
        $webhookResponse = $this->withHeaders(['PayMongo-Signature' => $signature])
            ->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse->assertStatus(200);

        // Verify transaction remains paid and unchanged
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals($originalPaymongoId, $transaction->paymongo_transaction_id);
        $this->assertEquals($originalPaidAt->timestamp, $transaction->paid_at->timestamp);

        // Verify share remains paid
        $share->refresh();
        $this->assertEquals('paid', $share->status);
    }
}
