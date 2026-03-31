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
 * Property-Based Test: Successful Payment Transaction Recording
 * 
 * This test validates that when a payment is successfully completed,
 * the transaction record is created with all required fields.
 */
class SuccessfulPaymentTransactionRecordingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function successful_payment_creates_complete_transaction_record()
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

            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertNotNull($transaction, "Iteration $i: Transaction should be created");
            $this->assertEquals('pending', $transaction->status, "Iteration $i: Initial status should be 'pending'");

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
            $transaction->refresh();

            $this->assertNotNull($transaction, "Iteration $i: Transaction should exist");
            $this->assertIsInt($transaction->id, "Iteration $i: Transaction should have valid ID");
            $this->assertGreaterThan(0, $transaction->id, "Iteration $i: Transaction ID should be positive");
            $this->assertEquals($share->id, $transaction->share_id, "Iteration $i: Correct share reference");
            $this->assertEquals($user->id, $transaction->user_id, "Iteration $i: Correct user reference");
            $this->assertEquals($shareAmount, (float) $transaction->amount, "Iteration $i: Correct amount");
            $this->assertEquals($paymentMethod, $transaction->payment_method, "Iteration $i: Correct payment method");
            $this->assertNotNull($transaction->paymongo_transaction_id, "Iteration $i: Has PayMongo transaction ID");
            $this->assertEquals($paymongoTransactionId, $transaction->paymongo_transaction_id, "Iteration $i: Correct PayMongo ID");
            $this->assertEquals('paid', $transaction->status, "Iteration $i: Status is 'paid'");
            $this->assertNotNull($transaction->paid_at, "Iteration $i: Has paid_at timestamp");
            $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $transaction->paid_at, "Iteration $i: paid_at is Carbon");
            $this->assertTrue($transaction->paid_at->greaterThan(now()->subMinute()), "Iteration $i: paid_at is recent");
            $this->assertNotNull($transaction->created_at, "Iteration $i: Has created_at");
            $this->assertNotNull($transaction->updated_at, "Iteration $i: Has updated_at");
            $this->assertTrue($transaction->updated_at->greaterThanOrEqualTo($transaction->created_at), "Iteration $i: updated_at >= created_at");

            $share->refresh();
            $this->assertEquals('paid', $share->status, "Iteration $i: Share status is 'paid'");
            $this->assertNotNull($transaction->share, "Iteration $i: Share relationship accessible");
            $this->assertNotNull($transaction->user, "Iteration $i: User relationship accessible");
            $this->assertEquals($share->amount, $transaction->amount, "Iteration $i: Amount matches share");

            Http::clearResolvedInstances();
        }
    }

    /**
     * @test
     */
    public function multiple_successful_payments_create_separate_transactions()
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

        $shares = [];
        for ($i = 0; $i < 3; $i++) {
            $shares[] = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 500.00,
                'status' => 'unpaid',
            ]);
        }

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

            $webhookPayload = [
                'data' => [
                    'id' => 'evt_' . $index,
                    'type' => 'payment.paid',
                    'attributes' => [
                        'data' => [
                            'id' => 'pay_' . $index,
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
            Http::clearResolvedInstances();
        }

        $transactions = Transaction::where('user_id', $user->id)->get();
        $this->assertCount(3, $transactions, 'Should create separate transaction for each share');

        foreach ($transactions as $transaction) {
            $this->assertEquals('paid', $transaction->status);
            $this->assertNotNull($transaction->paymongo_transaction_id);
            $this->assertNotNull($transaction->paid_at);
            $this->assertEquals(500.00, (float) $transaction->amount);
        }

        foreach ($shares as $share) {
            $share->refresh();
            $this->assertEquals('paid', $share->status);
        }
    }

    /**
     * @test
     */
    public function transaction_fields_remain_consistent_after_payment()
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

        $transaction = Transaction::where('share_id', $share->id)->first();
        $initialAmount = $transaction->amount;
        $initialPaymentMethod = $transaction->payment_method;
        $initialUserId = $transaction->user_id;
        $initialShareId = $transaction->share_id;

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

        $transaction->refresh();
        $this->assertEquals($initialAmount, $transaction->amount, 'Amount should not change');
        $this->assertEquals($initialPaymentMethod, $transaction->payment_method, 'Payment method should not change');
        $this->assertEquals($initialUserId, $transaction->user_id, 'User ID should not change');
        $this->assertEquals($initialShareId, $transaction->share_id, 'Share ID should not change');
        $this->assertEquals('paid', $transaction->status);
        $this->assertEquals('pay_test', $transaction->paymongo_transaction_id);
        $this->assertNotNull($transaction->paid_at);
    }

    /**
     * @test
     */
    public function transaction_amount_maintains_decimal_precision()
    {
        config(['services.paymongo.webhook_secret' => 'test_webhook_secret']);

        $testAmounts = [1.00, 10.50, 99.99, 100.00, 1234.56, 0.01, 9999.99, 123.45, 456.78, 789.01];

        foreach ($testAmounts as $index => $amount) {
            $user = User::factory()->create([
                'username' => 'user_precision_' . $index . '_' . uniqid(),
                'email' => 'user_precision_' . $index . '_' . uniqid() . '@test.com',
            ]);
            $creator = User::factory()->create([
                'username' => 'creator_precision_' . $index . '_' . uniqid(),
                'email' => 'creator_precision_' . $index . '_' . uniqid() . '@test.com',
            ]);
            $group = Group::factory()->create([
                'creator_id' => $creator->id,
                'name' => 'Group_precision_' . $index . '_' . uniqid(),
            ]);
            $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => "Bill_precision_" . $index . "_" . uniqid(),
                'total_amount' => $amount * 2,
            ]);
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'unpaid',
            ]);

            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => 'pi_' . $index,
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => (int) ($amount * 100),
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

            $webhookPayload = [
                'data' => [
                    'id' => 'evt_' . $index,
                    'type' => 'payment.paid',
                    'attributes' => [
                        'data' => [
                            'id' => 'pay_' . $index,
                            'type' => 'payment',
                            'attributes' => [
                                'status' => 'paid',
                                'amount' => (int) ($amount * 100),
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

            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertEquals(
                number_format($amount, 2, '.', ''),
                number_format((float) $transaction->amount, 2, '.', ''),
                "Amount PHP {$amount} should maintain 2 decimal precision"
            );

            Http::clearResolvedInstances();
        }
    }
}
