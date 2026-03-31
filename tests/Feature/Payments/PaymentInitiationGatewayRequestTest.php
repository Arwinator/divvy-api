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
 * Property-Based Test: Payment Initiation Triggers Gateway Request
 * 
 * This test validates that when a user initiates a payment for a share,
 * the system creates a PayMongo payment intent with correct data including:
 * - Amount properly converted to cents
 * - Payment method correctly specified (gcash or paymaya)
 * - Metadata includes share_id and user_id
 * - Transaction record is created with pending status
 */
class PaymentInitiationGatewayRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Payment Initiation Triggers Gateway Request
     * 
     * Test that payment request creates PayMongo payment intent with correct data
     */
    public function payment_initiation_triggers_gateway_request_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Generate random amount between 1.00 and 10000.00
            $shareAmount = round(mt_rand(100, 1000000) / 100, 2);
            
            // Randomly select payment method
            $paymentMethods = ['gcash', 'paymaya'];
            $paymentMethod = $paymentMethods[mt_rand(0, 1)];

            // Create users
            $user = User::factory()->create([
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => 'user_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);

            // Create group and bill
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

            // Create unpaid share for user
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);

            // Mock PayMongo API response
            $expectedAmountInCents = (int) ($shareAmount * 100);
            $paymentIntentId = 'pi_' . uniqid();
            $clientKey = 'pi_' . uniqid() . '_client';
            $checkoutUrl = 'https://paymongo.com/checkout/' . $paymentIntentId;

            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => $paymentIntentId,
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => $expectedAmountInCents,
                            'currency' => 'PHP',
                            'status' => 'awaiting_payment_method',
                            'client_key' => $clientKey,
                            'next_action' => [
                                'type' => 'redirect',
                                'redirect' => [
                                    'url' => $checkoutUrl,
                                ],
                            ],
                        ],
                    ],
                ], 200),
            ]);

            // Initiate payment
            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/shares/{$share->id}/pay", [
                    'payment_method' => $paymentMethod,
                ]);

            // Request should succeed with 200 status
            $response->assertStatus(200);

            // Response should contain payment intent data
            $response->assertJsonStructure([
                'message',
                'transaction',
                'payment_intent' => [
                    'id',
                    'client_key',
                    'status',
                ],
                'checkout_url',
            ]);

            // Transaction record should be created with pending status
            $transaction = Transaction::where('share_id', $share->id)->first();
            $this->assertNotNull(
                $transaction,
                "Iteration $i: Transaction record should be created"
            );
            
            $this->assertEquals(
                'pending',
                $transaction->status,
                "Iteration $i: Transaction status should be 'pending'"
            );

            // Transaction should have correct user_id
            $this->assertEquals(
                $user->id,
                $transaction->user_id,
                "Iteration $i: Transaction should belong to the authenticated user"
            );

            // Transaction should have correct share_id
            $this->assertEquals(
                $share->id,
                $transaction->share_id,
                "Iteration $i: Transaction should reference the correct share"
            );

            // Transaction should have correct amount
            $this->assertEquals(
                $shareAmount,
                (float) $transaction->amount,
                "Iteration $i: Transaction amount should match share amount"
            );

            // Transaction should have correct payment method
            $this->assertEquals(
                $paymentMethod,
                $transaction->payment_method,
                "Iteration $i: Transaction should have correct payment method"
            );

            // PayMongo API should be called with correct amount in cents
            Http::assertSent(function ($request) use ($expectedAmountInCents, $shareAmount, $i) {
                $body = json_decode($request->body(), true);
                $actualAmount = $body['data']['attributes']['amount'] ?? null;
                
                $this->assertEquals(
                    $expectedAmountInCents,
                    $actualAmount,
                    "Iteration $i: PayMongo should receive amount in cents (PHP {$shareAmount} = {$expectedAmountInCents} cents)"
                );
                
                return true;
            });

            // PayMongo API should be called with correct payment method
            Http::assertSent(function ($request) use ($paymentMethod, $i) {
                $body = json_decode($request->body(), true);
                $allowedMethods = $body['data']['attributes']['payment_method_allowed'] ?? [];
                
                $this->assertContains(
                    $paymentMethod,
                    $allowedMethods,
                    "Iteration $i: PayMongo should receive correct payment method"
                );
                
                return true;
            });

            // PayMongo API should be called with correct metadata
            Http::assertSent(function ($request) use ($share, $user, $i) {
                $body = json_decode($request->body(), true);
                $metadata = $body['data']['attributes']['metadata'] ?? [];
                
                $this->assertEquals(
                    $share->id,
                    $metadata['share_id'] ?? null,
                    "Iteration $i: Metadata should include share_id"
                );
                
                $this->assertEquals(
                    $user->id,
                    $metadata['user_id'] ?? null,
                    "Iteration $i: Metadata should include user_id"
                );
                
                $this->assertEquals(
                    $share->bill_id,
                    $metadata['bill_id'] ?? null,
                    "Iteration $i: Metadata should include bill_id"
                );
                
                return true;
            });

            // PayMongo API should be called with PHP currency
            Http::assertSent(function ($request) use ($i) {
                $body = json_decode($request->body(), true);
                $currency = $body['data']['attributes']['currency'] ?? null;
                
                $this->assertEquals(
                    'PHP',
                    $currency,
                    "Iteration $i: Currency should be PHP"
                );
                
                return true;
            });

            // Response should include checkout URL
            $responseData = $response->json();
            $this->assertNotEmpty(
                $responseData['checkout_url'] ?? null,
                "Iteration $i: Response should include checkout URL"
            );

            // Clean up for next iteration
            Http::clearResolvedInstances();
        }
    }

    /**
     * @test
     * Edge case: Payment initiation for already paid share should fail
     */
    public function payment_initiation_for_paid_share_should_fail()
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

        // Create already paid share
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'paid',
        ]);

        // Attempt to initiate payment
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'This share has already been paid',
            'error_code' => 'ALREADY_PAID',
        ]);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for already paid share');
    }

    /**
     * @test
     * Edge case: Payment initiation for another user's share should fail
     */
    public function payment_initiation_for_another_users_share_should_fail()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $otherUser->id, $creator->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);

        // Create share for other user
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $otherUser->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Attempt to pay another user's share
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You do not have permission to pay this share',
            'error_code' => 'FORBIDDEN',
        ]);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for unauthorized payment');
    }

    /**
     * @test
     * Edge case: Payment initiation with invalid payment method should fail
     */
    public function payment_initiation_with_invalid_payment_method_should_fail()
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

        // Attempt with invalid payment method
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'invalid_method',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);

        // Verify no transaction was created
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(0, $transactionCount, 'No transaction should be created for invalid payment method');
    }

    /**
     * @test
     * Edge case: Payment initiation without authentication should fail
     */
    public function payment_initiation_without_authentication_should_fail()
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

        // Attempt without authentication
        $response = $this->postJson("/api/shares/{$share->id}/pay", [
            'payment_method' => 'gcash',
        ]);

        $response->assertStatus(401);
    }

    /**
     * @test
     * Edge case: Duplicate payment initiation should fail
     */
    public function duplicate_payment_initiation_should_fail()
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
        Transaction::create([
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

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'A payment is already in progress for this share',
            'error_code' => 'PAYMENT_IN_PROGRESS',
        ]);

        // Verify only one transaction exists
        $transactionCount = Transaction::where('share_id', $share->id)->count();
        $this->assertEquals(1, $transactionCount, 'Should not create duplicate pending transaction');
    }

    /**
     * @test
     * Edge case: Amount conversion accuracy for various amounts
     */
    public function amount_conversion_accuracy_for_various_amounts()
    {
        $testCases = [
            ['amount' => 1.00, 'expected_cents' => 100],
            ['amount' => 10.50, 'expected_cents' => 1050],
            ['amount' => 99.99, 'expected_cents' => 9999],
            ['amount' => 100.00, 'expected_cents' => 10000],
            ['amount' => 1234.56, 'expected_cents' => 123456],
            ['amount' => 0.01, 'expected_cents' => 1],
            ['amount' => 9999.99, 'expected_cents' => 999999],
        ];

        foreach ($testCases as $index => $testCase) {
            $user = User::factory()->create([
                'username' => 'user_amount_' . $index . '_' . uniqid(),
                'email' => 'user_amount_' . $index . '_' . uniqid() . '@test.com',
            ]);
            
            $creator = User::factory()->create([
                'username' => 'creator_amount_' . $index . '_' . uniqid(),
                'email' => 'creator_amount_' . $index . '_' . uniqid() . '@test.com',
            ]);

            $group = Group::factory()->create([
                'creator_id' => $creator->id,
                'name' => 'Group_amount_' . $index . '_' . uniqid(),
            ]);
            
            $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);

            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => "Bill_amount_" . $index . "_" . uniqid(),
                'total_amount' => $testCase['amount'] * 2,
            ]);

            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $testCase['amount'],
                'status' => 'unpaid',
            ]);

            // Mock PayMongo API
            Http::fake([
                'api.paymongo.com/v1/payment_intents' => Http::response([
                    'data' => [
                        'id' => 'pi_' . uniqid(),
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => $testCase['expected_cents'],
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

            // Initiate payment
            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/shares/{$share->id}/pay", [
                    'payment_method' => 'gcash',
                ]);

            $response->assertStatus(200);

            // Verify amount conversion
            Http::assertSent(function ($request) use ($testCase) {
                $body = json_decode($request->body(), true);
                $actualAmount = $body['data']['attributes']['amount'] ?? null;
                
                return $actualAmount === $testCase['expected_cents'];
            });

            Http::clearResolvedInstances();
        }
    }
}
