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
 * Property-Based Test: Transaction Group Filtering
 * 
 * This test validates that when filtering transactions by group_id,
 * only transactions from bills belonging to that specific group are returned.
 * Transactions from other groups should be excluded, even if the user is a member
 * of those groups.
 */
class TransactionGroupFilteringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Transaction Group Filtering
     * 
     * Test that only transactions from the specified group are returned.
     * Transactions from other groups should be excluded.
     */
    public function transaction_group_filtering_returns_only_transactions_from_specified_group()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create test user with unique identifiers
            $user = User::factory()->create([
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => 'user_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group creator
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create multiple groups (2-4 groups)
            $groupCount = mt_rand(2, 4);
            $groups = [];
            
            for ($g = 0; $g < $groupCount; $g++) {
                $group = Group::factory()->create([
                    'name' => 'Group_' . $i . '_' . $g . '_' . uniqid(),
                    'creator_id' => $creator->id,
                ]);
                
                // Add user to all groups
                $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
                $groups[] = $group;
            }
            
            // Select target group (first group)
            $targetGroup = $groups[0];
            
            // Create transactions in target group
            $targetTransactionCount = mt_rand(2, 5);
            $targetTransactions = [];
            
            for ($t = 0; $t < $targetTransactionCount; $t++) {
                $bill = Bill::factory()->create([
                    'group_id' => $targetGroup->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_target_{$i}_{$t}_" . uniqid(),
                    'total_amount' => round(mt_rand(100, 5000) / 100, 2),
                ]);
                
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => round(mt_rand(50, 2000) / 100, 2),
                    'status' => 'unpaid',
                ]);
                
                $transaction = Transaction::create([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $share->amount,
                    'payment_method' => mt_rand(0, 1) ? 'gcash' : 'paymaya',
                    'paymongo_transaction_id' => 'pay_target_' . $i . '_' . $t . '_' . uniqid(),
                    'status' => ['pending', 'paid', 'failed'][mt_rand(0, 2)],
                    'paid_at' => now()->subMinutes(mt_rand(1, 1000)),
                ]);
                
                $targetTransactions[] = $transaction;
            }
            
            // Create transactions in other groups (should be excluded)
            $otherTransactions = [];
            
            for ($g = 1; $g < $groupCount; $g++) {
                $otherGroup = $groups[$g];
                $otherTransactionCount = mt_rand(1, 3);
                
                for ($t = 0; $t < $otherTransactionCount; $t++) {
                    $bill = Bill::factory()->create([
                        'group_id' => $otherGroup->id,
                        'creator_id' => $creator->id,
                        'title' => "Bill_other_{$i}_{$g}_{$t}_" . uniqid(),
                        'total_amount' => round(mt_rand(100, 5000) / 100, 2),
                    ]);
                    
                    $share = Share::create([
                        'bill_id' => $bill->id,
                        'user_id' => $user->id,
                        'amount' => round(mt_rand(50, 2000) / 100, 2),
                        'status' => 'unpaid',
                    ]);
                    
                    $transaction = Transaction::create([
                        'share_id' => $share->id,
                        'user_id' => $user->id,
                        'amount' => $share->amount,
                        'payment_method' => mt_rand(0, 1) ? 'gcash' : 'paymaya',
                        'paymongo_transaction_id' => 'pay_other_' . $i . '_' . $g . '_' . $t . '_' . uniqid(),
                        'status' => ['pending', 'paid', 'failed'][mt_rand(0, 2)],
                        'paid_at' => now()->subMinutes(mt_rand(1, 1000)),
                    ]);
                    
                    $otherTransactions[] = $transaction;
                }
            }
            
            // Query with group_id filter
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?group_id=' . $targetGroup->id);
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Response has correct structure
            $this->assertArrayHasKey('data', $responseData, "Iteration $i: Response should have 'data' key");
            $this->assertArrayHasKey('summary', $responseData, "Iteration $i: Response should have 'summary' key");
            $this->assertArrayHasKey('meta', $responseData, "Iteration $i: Response should have 'meta' key");
            
            $transactions = $responseData['data'];
            
            // Only transactions from target group are returned
            $this->assertCount(
                $targetTransactionCount,
                $transactions,
                "Iteration $i: Should return exactly $targetTransactionCount transactions from target group"
            );
            
            // All returned transactions belong to target group
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertEquals(
                    $targetGroup->id,
                    $transaction['share']['bill']['group_id'],
                    "Iteration $i, Transaction $txIndex: Transaction should belong to target group"
                );
            }
            
            // Transactions from other groups are excluded
            $returnedIds = collect($transactions)->pluck('id')->toArray();
            
            foreach ($otherTransactions as $txIndex => $otherTx) {
                $this->assertNotContains(
                    $otherTx->id,
                    $returnedIds,
                    "Iteration $i, Other Transaction $txIndex: Transaction from other group should be excluded"
                );
            }
            
            // All target group transactions are included
            foreach ($targetTransactions as $txIndex => $targetTx) {
                $this->assertContains(
                    $targetTx->id,
                    $returnedIds,
                    "Iteration $i, Target Transaction $txIndex: Transaction from target group should be included"
                );
            }
            
            // Returned transactions match created data
            foreach ($targetTransactions as $txIndex => $targetTx) {
                $foundTransaction = collect($transactions)->firstWhere('id', $targetTx->id);
                
                $this->assertNotNull(
                    $foundTransaction,
                    "Iteration $i, Transaction $txIndex: Transaction should be in response"
                );
                
                $this->assertEquals(
                    (float) $targetTx->amount,
                    (float) $foundTransaction['amount'],
                    "Iteration $i, Transaction $txIndex: Amount should match"
                );
                
                $this->assertEquals(
                    $targetTx->payment_method,
                    $foundTransaction['payment_method'],
                    "Iteration $i, Transaction $txIndex: Payment method should match"
                );
                
                $this->assertEquals(
                    $targetTx->status,
                    $foundTransaction['status'],
                    "Iteration $i, Transaction $txIndex: Status should match"
                );
            }
            
            // Group information is included in response
            foreach ($transactions as $txIndex => $transaction) {
                $this->assertArrayHasKey('share', $transaction, "Iteration $i, Transaction $txIndex: Should have 'share' key");
                $this->assertArrayHasKey('bill', $transaction['share'], "Iteration $i, Transaction $txIndex: Share should have 'bill' key");
                $this->assertArrayHasKey('group', $transaction['share']['bill'], "Iteration $i, Transaction $txIndex: Bill should have 'group' key");
                
                $group = $transaction['share']['bill']['group'];
                $this->assertEquals(
                    $targetGroup->id,
                    $group['id'],
                    "Iteration $i, Transaction $txIndex: Group ID should match target group"
                );
                
                $this->assertEquals(
                    $targetGroup->name,
                    $group['name'],
                    "Iteration $i, Transaction $txIndex: Group name should match target group"
                );
            }
        }
    }

    /**
     * @test
     * Edge case: User with transactions in multiple groups
     */
    public function transaction_group_filtering_works_with_multiple_groups()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create 3 groups
        $group1 = Group::factory()->create(['creator_id' => $creator->id]);
        $group2 = Group::factory()->create(['creator_id' => $creator->id]);
        $group3 = Group::factory()->create(['creator_id' => $creator->id]);
        
        // Add user to all groups
        $group1->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        $group2->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        $group3->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create transactions in each group
        $bill1 = Bill::factory()->create(['group_id' => $group1->id, 'creator_id' => $creator->id]);
        $share1 = Share::create(['bill_id' => $bill1->id, 'user_id' => $user->id, 'amount' => 100.00, 'status' => 'unpaid']);
        $tx1 = Transaction::create([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_g1_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        $bill2 = Bill::factory()->create(['group_id' => $group2->id, 'creator_id' => $creator->id]);
        $share2 = Share::create(['bill_id' => $bill2->id, 'user_id' => $user->id, 'amount' => 200.00, 'status' => 'unpaid']);
        $tx2 = Transaction::create([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_g2_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        $bill3 = Bill::factory()->create(['group_id' => $group3->id, 'creator_id' => $creator->id]);
        $share3 = Share::create(['bill_id' => $bill3->id, 'user_id' => $user->id, 'amount' => 300.00, 'status' => 'unpaid']);
        $tx3 = Transaction::create([
            'share_id' => $share3->id,
            'user_id' => $user->id,
            'amount' => 300.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_g3_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Filter by group1
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group1->id);
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(1, $transactions);
        $this->assertEquals($tx1->id, $transactions[0]['id']);
        $this->assertEquals($group1->id, $transactions[0]['share']['bill']['group_id']);
    }

    /**
     * @test
     * Edge case: Empty result when no transactions in specified group
     */
    public function transaction_group_filtering_returns_empty_when_no_transactions_in_group()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create group with no transactions
        $emptyGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $emptyGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create another group with transactions
        $otherGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $otherGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create(['group_id' => $otherGroup->id, 'creator_id' => $creator->id]);
        $share = Share::create(['bill_id' => $bill->id, 'user_id' => $user->id, 'amount' => 100.00, 'status' => 'unpaid']);
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_other_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Query empty group
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $emptyGroup->id);
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(0, $transactions, 'Should return empty array when no transactions in group');
    }

    /**
     * @test
     * Edge case: Invalid group_id returns validation error
     */
    public function transaction_group_filtering_rejects_invalid_group_id()
    {
        $user = User::factory()->create();
        
        // Query with non-existent group_id
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=99999');
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['group_id']);
    }

    /**
     * @test
     * Edge case: User not member of group returns empty results
     */
    public function transaction_group_filtering_returns_empty_for_non_member()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create group without adding user as member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id], ['joined_at' => now()]);
        
        // Create transaction in that group for creator
        $bill = Bill::factory()->create(['group_id' => $group->id, 'creator_id' => $creator->id]);
        $share = Share::create(['bill_id' => $bill->id, 'user_id' => $creator->id, 'amount' => 100.00, 'status' => 'unpaid']);
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $creator->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_creator_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // User tries to filter by group they're not a member of
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group->id);
        
        // Should return 200 with empty results (user has no transactions in that group)
        $response->assertStatus(200);
        $transactions = $response->json('data');
        $this->assertCount(0, $transactions, 'Should return empty array when user is not member of group');
    }

    /**
     * @test
     * Edge case: Filtering works with combined filters (group_id + date range)
     */
    public function transaction_group_filtering_works_with_combined_filters()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create old transaction (should be excluded by date filter)
        $oldBill = Bill::factory()->create(['group_id' => $group->id, 'creator_id' => $creator->id]);
        $oldShare = Share::create(['bill_id' => $oldBill->id, 'user_id' => $user->id, 'amount' => 100.00, 'status' => 'unpaid']);
        $oldDate = now()->subDays(30);
        $oldTx = new Transaction([
            'share_id' => $oldShare->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_old_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $oldDate,
        ]);
        $oldTx->created_at = $oldDate;
        $oldTx->updated_at = $oldDate;
        $oldTx->save();
        
        // Create recent transaction (should be included)
        $recentBill = Bill::factory()->create(['group_id' => $group->id, 'creator_id' => $creator->id]);
        $recentShare = Share::create(['bill_id' => $recentBill->id, 'user_id' => $user->id, 'amount' => 200.00, 'status' => 'unpaid']);
        $recentDate = now()->subDays(5);
        $recentTx = new Transaction([
            'share_id' => $recentShare->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_recent_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $recentDate,
        ]);
        $recentTx->created_at = $recentDate;
        $recentTx->updated_at = $recentDate;
        $recentTx->save();
        
        // Query with group_id and date range
        $fromDate = now()->subDays(10)->format('Y-m-d');
        $toDate = now()->format('Y-m-d');
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'group_id' => $group->id,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(1, $transactions, 'Should return only recent transaction matching both filters');
        $this->assertEquals($recentTx->id, $transactions[0]['id']);
    }
}
