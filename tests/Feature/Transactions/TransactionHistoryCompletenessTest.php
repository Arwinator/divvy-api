<?php

namespace Tests\Feature\Transactions;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Transaction History Completeness
 * 
 * 
 * This test validates that all user transactions are returned with complete details
 * including share_id, bill info, group info, payment method, amount, status, and timestamps.
 */
class TransactionHistoryCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Transaction History Completeness
     * 
     * Test that all user transactions are returned with complete details
     * (share_id, bill info, group info, payment method, amount, status, timestamps).
     */
    public function transaction_history_returns_all_user_transactions_with_complete_details()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Generate random number of transactions (between 1 and 10)
            $transactionCount = mt_rand(1, 10);
            
            // Create test user
            $user = User::factory()->create([
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => 'user_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create another user to verify isolation
            $otherUser = User::factory()->create([
                'username' => 'other_' . $i . '_' . uniqid(),
                'email' => 'other_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group creator
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group
            $group = Group::factory()->create([
                'name' => 'Group_' . $i . '_' . uniqid(),
                'creator_id' => $creator->id,
            ]);
            
            // Add users to group
            $group->members()->attach([$user->id, $otherUser->id, $creator->id], ['joined_at' => now()]);
            
            // Create transactions for the test user
            $createdTransactions = [];
            $paymentMethods = ['gcash', 'paymaya'];
            $statuses = ['pending', 'paid', 'failed'];
            
            for ($j = 0; $j < $transactionCount; $j++) {
                // Create bill
                $billAmount = round(mt_rand(100, 10000) / 100, 2);
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_{$i}_{$j}_" . uniqid(),
                    'total_amount' => $billAmount,
                    'bill_date' => now()->subDays(mt_rand(0, 30)),
                ]);
                
                // Create share for user
                $shareAmount = round(mt_rand(50, (int)($billAmount * 100)) / 100, 2);
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => $shareAmount,
                    'status' => 'unpaid',
                ]);
                
                // Create transaction
                $paymentMethod = $paymentMethods[mt_rand(0, 1)];
                $status = $statuses[mt_rand(0, 2)];
                
                $transaction = Transaction::create([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $shareAmount,
                    'payment_method' => $paymentMethod,
                    'paymongo_transaction_id' => 'pay_' . uniqid(),
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? now()->subMinutes(mt_rand(1, 60)) : null,
                ]);
                
                $createdTransactions[] = [
                    'transaction' => $transaction,
                    'share' => $share,
                    'bill' => $bill,
                    'group' => $group,
                ];
            }
            
            // Create some transactions for other user (should not appear in results)
            $otherBill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => "OtherBill_{$i}_" . uniqid(),
                'total_amount' => 500.00,
            ]);
            
            $otherShare = Share::create([
                'bill_id' => $otherBill->id,
                'user_id' => $otherUser->id,
                'amount' => 250.00,
                'status' => 'unpaid',
            ]);
            
            Transaction::create([
                'share_id' => $otherShare->id,
                'user_id' => $otherUser->id,
                'amount' => 250.00,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_other_' . uniqid(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            
            // Get transaction history for the test user
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions');
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Response has correct structure
            $this->assertArrayHasKey('data', $responseData, "Iteration $i: Response should have 'data' key");
            $this->assertArrayHasKey('summary', $responseData, "Iteration $i: Response should have 'summary' key");
            $this->assertArrayHasKey('meta', $responseData, "Iteration $i: Response should have 'meta' key");
            
            $transactions = $responseData['data'];
            
            // All user transactions are returned (no more, no less)
            $this->assertCount(
                $transactionCount,
                $transactions,
                "Iteration $i: Should return exactly $transactionCount transactions for user"
            );
            
            // No transactions from other users are included
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertEquals(
                    $user->id,
                    $transaction['user_id'],
                    "Iteration $i, Transaction $txIndex: Should only contain transactions for authenticated user"
                );
            }
            
            // Each transaction has all required fields
            foreach ($transactions as $txIndex => $transaction) {
                // Core transaction fields
                $this->assertArrayHasKey('id', $transaction, "Iteration $i, Transaction $txIndex: Should have 'id'");
                $this->assertArrayHasKey('share_id', $transaction, "Iteration $i, Transaction $txIndex: Should have 'share_id'");
                $this->assertArrayHasKey('user_id', $transaction, "Iteration $i, Transaction $txIndex: Should have 'user_id'");
                $this->assertArrayHasKey('amount', $transaction, "Iteration $i, Transaction $txIndex: Should have 'amount'");
                $this->assertArrayHasKey('payment_method', $transaction, "Iteration $i, Transaction $txIndex: Should have 'payment_method'");
                $this->assertArrayHasKey('paymongo_transaction_id', $transaction, "Iteration $i, Transaction $txIndex: Should have 'paymongo_transaction_id'");
                $this->assertArrayHasKey('status', $transaction, "Iteration $i, Transaction $txIndex: Should have 'status'");
                $this->assertArrayHasKey('paid_at', $transaction, "Iteration $i, Transaction $txIndex: Should have 'paid_at'");
                $this->assertArrayHasKey('created_at', $transaction, "Iteration $i, Transaction $txIndex: Should have 'created_at'");
                $this->assertArrayHasKey('updated_at', $transaction, "Iteration $i, Transaction $txIndex: Should have 'updated_at'");
                
                // Relationship fields
                $this->assertArrayHasKey('share', $transaction, "Iteration $i, Transaction $txIndex: Should have 'share' relationship");
                $this->assertArrayHasKey('user', $transaction, "Iteration $i, Transaction $txIndex: Should have 'user' relationship");
            }
            
            // Share relationship includes bill and group info
            foreach ($transactions as $txIndex => $transaction) {
                $share = $transaction['share'];
                
                $this->assertNotNull($share, "Iteration $i, Transaction $txIndex: Share should not be null");
                $this->assertArrayHasKey('id', $share, "Iteration $i, Transaction $txIndex: Share should have 'id'");
                $this->assertArrayHasKey('bill_id', $share, "Iteration $i, Transaction $txIndex: Share should have 'bill_id'");
                $this->assertArrayHasKey('user_id', $share, "Iteration $i, Transaction $txIndex: Share should have 'user_id'");
                $this->assertArrayHasKey('amount', $share, "Iteration $i, Transaction $txIndex: Share should have 'amount'");
                $this->assertArrayHasKey('status', $share, "Iteration $i, Transaction $txIndex: Share should have 'status'");
                
                // Bill relationship
                $this->assertArrayHasKey('bill', $share, "Iteration $i, Transaction $txIndex: Share should have 'bill' relationship");
                $bill = $share['bill'];
                
                $this->assertNotNull($bill, "Iteration $i, Transaction $txIndex: Bill should not be null");
                $this->assertArrayHasKey('id', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'id'");
                $this->assertArrayHasKey('title', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'title'");
                $this->assertArrayHasKey('total_amount', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'total_amount'");
                $this->assertArrayHasKey('bill_date', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'bill_date'");
                $this->assertArrayHasKey('group_id', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'group_id'");
                $this->assertArrayHasKey('creator_id', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'creator_id'");
                
                // Group relationship
                $this->assertArrayHasKey('group', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'group' relationship");
                $groupData = $bill['group'];
                
                $this->assertNotNull($groupData, "Iteration $i, Transaction $txIndex: Group should not be null");
                $this->assertArrayHasKey('id', $groupData, "Iteration $i, Transaction $txIndex: Group should have 'id'");
                $this->assertArrayHasKey('name', $groupData, "Iteration $i, Transaction $txIndex: Group should have 'name'");
                $this->assertArrayHasKey('creator_id', $groupData, "Iteration $i, Transaction $txIndex: Group should have 'creator_id'");
                
                // Creator relationship
                $this->assertArrayHasKey('creator', $bill, "Iteration $i, Transaction $txIndex: Bill should have 'creator' relationship");
                $creatorData = $bill['creator'];
                
                $this->assertNotNull($creatorData, "Iteration $i, Transaction $txIndex: Creator should not be null");
                $this->assertArrayHasKey('id', $creatorData, "Iteration $i, Transaction $txIndex: Creator should have 'id'");
                $this->assertArrayHasKey('username', $creatorData, "Iteration $i, Transaction $txIndex: Creator should have 'username'");
                $this->assertArrayHasKey('email', $creatorData, "Iteration $i, Transaction $txIndex: Creator should have 'email'");
            }
            
            // Transaction data matches created data
            foreach ($createdTransactions as $txIndex => $created) {
                $foundTransaction = collect($transactions)->firstWhere('id', $created['transaction']->id);
                
                $this->assertNotNull(
                    $foundTransaction,
                    "Iteration $i, Transaction $txIndex: Created transaction should be in response"
                );
                
                $this->assertEquals(
                    $created['transaction']->share_id,
                    $foundTransaction['share_id'],
                    "Iteration $i, Transaction $txIndex: share_id should match"
                );
                
                $this->assertEquals(
                    $created['transaction']->user_id,
                    $foundTransaction['user_id'],
                    "Iteration $i, Transaction $txIndex: user_id should match"
                );
                
                $this->assertEquals(
                    (float) $created['transaction']->amount,
                    (float) $foundTransaction['amount'],
                    "Iteration $i, Transaction $txIndex: amount should match"
                );
                
                $this->assertEquals(
                    $created['transaction']->payment_method,
                    $foundTransaction['payment_method'],
                    "Iteration $i, Transaction $txIndex: payment_method should match"
                );
                
                $this->assertEquals(
                    $created['transaction']->status,
                    $foundTransaction['status'],
                    "Iteration $i, Transaction $txIndex: status should match"
                );
                
                // Verify bill title is included
                $this->assertEquals(
                    $created['bill']->title,
                    $foundTransaction['share']['bill']['title'],
                    "Iteration $i, Transaction $txIndex: bill title should match"
                );
                
                // Verify group name is included
                $this->assertEquals(
                    $created['group']->name,
                    $foundTransaction['share']['bill']['group']['name'],
                    "Iteration $i, Transaction $txIndex: group name should match"
                );
            }
            
            // Payment method is valid
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertContains(
                    $transaction['payment_method'],
                    ['gcash', 'paymaya'],
                    "Iteration $i, Transaction $txIndex: payment_method should be 'gcash' or 'paymaya'"
                );
            }
            
            // Status is valid
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertContains(
                    $transaction['status'],
                    ['pending', 'paid', 'failed'],
                    "Iteration $i, Transaction $txIndex: status should be 'pending', 'paid', or 'failed'"
                );
            }
            
            // paid_at is set only for paid transactions
            foreach ($transactions as $txIndex => $transaction) {
                if ($transaction['status'] === 'paid') {
                    $this->assertNotNull(
                        $transaction['paid_at'],
                        "Iteration $i, Transaction $txIndex: paid_at should be set for paid transactions"
                    );
                }
            }
            
            // Amount is positive
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertGreaterThan(
                    0,
                    $transaction['amount'],
                    "Iteration $i, Transaction $txIndex: amount should be positive"
                );
            }
        }
    }

    /**
     * @test
     * Edge case: User with no transactions
     */
    public function transaction_history_returns_empty_array_for_user_with_no_transactions()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(0, $responseData['data']);
        $this->assertArrayHasKey('summary', $responseData);
        $this->assertEquals(0, $responseData['summary']['total_paid']);
        $this->assertEquals(0, $responseData['summary']['total_owed']);
    }

    /**
     * @test
     * Edge case: Transaction with all possible statuses
     */
    public function transaction_history_includes_transactions_with_all_statuses()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 900.00,
        ]);
        
        // Create transactions with different statuses
        $statuses = ['pending', 'paid', 'failed'];
        foreach ($statuses as $status) {
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 300.00,
                'status' => $status === 'paid' ? 'paid' : 'unpaid',
            ]);
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => 300.00,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_' . $status . '_' . uniqid(),
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(3, $transactions);
        
        // Verify all statuses are present
        $foundStatuses = collect($transactions)->pluck('status')->toArray();
        $this->assertContains('pending', $foundStatuses);
        $this->assertContains('paid', $foundStatuses);
        $this->assertContains('failed', $foundStatuses);
    }

    /**
     * @test
     * Edge case: Transaction with both payment methods
     */
    public function transaction_history_includes_transactions_with_both_payment_methods()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 600.00,
        ]);
        
        // Create transactions with different payment methods
        $paymentMethods = ['gcash', 'paymaya'];
        foreach ($paymentMethods as $method) {
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 300.00,
                'status' => 'unpaid',
            ]);
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => 300.00,
                'payment_method' => $method,
                'paymongo_transaction_id' => 'pay_' . $method . '_' . uniqid(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(2, $transactions);
        
        // Verify both payment methods are present
        $foundMethods = collect($transactions)->pluck('payment_method')->toArray();
        $this->assertContains('gcash', $foundMethods);
        $this->assertContains('paymaya', $foundMethods);
    }

    /**
     * @test
     * Edge case: Transactions are ordered by created_at descending
     */
    public function transaction_history_returns_transactions_in_descending_order()
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
        
        // Create transactions at different times with explicit timestamps
        $transactions = [];
        for ($i = 0; $i < 5; $i++) {
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 200.00,
                'status' => 'unpaid',
            ]);
            
            // Create transaction with explicit timestamp
            $createdAt = now()->subMinutes(10 - ($i * 2)); // Older to newer with 2 minute gaps
            $transaction = new Transaction([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => 200.00,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_' . $i . '_' . uniqid(),
                'status' => 'pending',
            ]);
            $transaction->created_at = $createdAt;
            $transaction->updated_at = $createdAt;
            $transaction->save();
            
            $transactions[] = $transaction;
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseTransactions = $response->json('data');
        
        $this->assertCount(5, $responseTransactions);
        
        // Verify transactions are in descending order (newest first)
        $returnedIds = collect($responseTransactions)->pluck('id')->toArray();
        
        // Expected order: newest to oldest (reverse of creation order)
        $expectedOrder = array_reverse(array_map(fn($t) => $t->id, $transactions));
        
        $this->assertEquals($expectedOrder, $returnedIds, 'Transactions should be ordered by created_at descending (newest first)');
    }
}
