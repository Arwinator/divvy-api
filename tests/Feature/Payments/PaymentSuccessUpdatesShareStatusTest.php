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

/**
 * Property-Based Test: Payment Success Updates Share Status
 * 
 * This test validates that when a payment is successfully completed,
 * the share status changes from 'unpaid' to 'paid'.
 */
class PaymentSuccessUpdatesShareStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function successful_payment_updates_share_status_from_unpaid_to_paid()
    {
        // Set webhook secret for testing
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

            // Verify initial state
            $this->assertEquals('unpaid', $share->status, "Iteration $i: Share should start as 'unpaid'");

            $paymentIntentId = 'pi_' . uniqid();
            $clientKey = 'pi_' . uniqid() . '_client';
            $checkoutUrl = 'https://paymongo.com/checkout/' . $paymentIntentId;

            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => $paymentIntentId,
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => (int) ($shareAmount * 100),
                            'currency' => 'PHP',
                            'status' => 'awaiting_payment_method',
                            'client_key' => $clientKey,
                            'next_action' => [
                                'type' => 'redirect',
                                'redirect' => ['url' => $checkoutUrl],
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

            // Verify share is still unpaid after payment initiation
            $share->refresh();
            $this->assertEquals('unpaid', $share->status, "Iteration $i: Share should remain 'unpaid' after payment initiation");

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

            $webhookSecret = 'test_webhook_secret';
            $payloadJson = json_encode($webhookPayload);
            $signature = hash_hmac('sha256', $payloadJson, $webhookSecret);

            $webhookResponse = $this->withHeaders([
                'PayMongo-Signature' => $signature,
            ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);

            $webhookResponse->assertStatus(200);

            // Verify share status changed to paid after webhook
            $share->refresh();
            $this->assertEquals('paid', $share->status, "Iteration $i: Share status should be 'paid' after successful payment");

            // Verify transaction also has paid status
            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertNotNull($transaction, "Iteration $i: Transaction should exist");
            $this->assertEquals('paid', $transaction->status, "Iteration $i: Transaction status should be 'paid'");
            $this->assertNotNull($transaction->paid_at, "Iteration $i: Transaction should have paid_at timestamp");

            Http::clearResolvedInstances();
        }
    }

    /**
     * @test
     */
    public function failed_payment_does_not_update_share_status()
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

        // Send failed payment webhook
        $webhookPayload = [
            'data' => [
                'id' => 'evt_test',
                'type' => 'payment.failed',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_test',
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
        $webhookResponse = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse->assertStatus(200);

        // Verify share status remains unpaid
        $share->refresh();
        $this->assertEquals('unpaid', $share->status, 'Share should remain unpaid after failed payment');

        // Verify transaction has failed status
        $transaction = Transaction::where('share_id', $share->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('failed', $transaction->status);
        $this->assertNull($transaction->paid_at, 'Failed transaction should not have paid_at timestamp');
    }

    /**
     * @test
     */
    public function multiple_shares_in_same_bill_update_independently()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user1->id, $user2->id, $creator->id], ['joined_at' => now()]);
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1500.00,
        ]);

        $share1 = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user1->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        $share2 = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user2->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        $share3 = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $creator->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Pay only share1
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_1',
                    'type' => 'payment_intent',
                    'attributes' => [
                        'amount' => 50000,
                        'currency' => 'PHP',
                        'status' => 'awaiting_payment_method',
                        'client_key' => 'pi_client_1',
                        'next_action' => ['type' => 'redirect', 'redirect' => ['url' => 'https://paymongo.com/checkout/test']],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user1, 'sanctum')
            ->postJson("/api/shares/{$share1->id}/pay", ['payment_method' => 'gcash']);
        $response->assertStatus(200);

        $webhookPayload = [
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
                            'metadata' => ['share_id' => $share1->id, 'user_id' => $user1->id, 'bill_id' => $bill->id],
                        ],
                    ],
                ],
            ],
        ];

        $signature = hash_hmac('sha256', json_encode($webhookPayload), 'test_webhook_secret');
        $webhookResponse = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse->assertStatus(200);

        // Verify only share1 is paid
        $share1->refresh();
        $share2->refresh();
        $share3->refresh();

        $this->assertEquals('paid', $share1->status, 'Share 1 should be paid');
        $this->assertEquals('unpaid', $share2->status, 'Share 2 should remain unpaid');
        $this->assertEquals('unpaid', $share3->status, 'Share 3 should remain unpaid');
    }

    /**
     * @test
     */
    public function share_status_persists_after_multiple_refreshes()
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
        $webhookResponse = $this->withHeaders([
            'PayMongo-Signature' => $signature,
        ])->json('POST', '/api/webhooks/paymongo', $webhookPayload);
        $webhookResponse->assertStatus(200);

        // Refresh multiple times and verify status persists
        for ($i = 0; $i < 10; $i++) {
            $share->refresh();
            $this->assertEquals('paid', $share->status, "Share should remain 'paid' after refresh $i");
        }

        // Fetch from database directly
        $shareFromDb = Share::find($share->id);
        $this->assertEquals('paid', $shareFromDb->status, 'Share should be paid when fetched from database');
    }
}
