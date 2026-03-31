<?php

namespace Tests\Feature\Transactions;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

/**
 * Property-Based Test: Transaction Date Range Filtering
 * 
 * This test validates that when filtering transactions by date range (from_date and to_date),
 * only transactions within that range are returned. Transactions outside the date range
 * should be excluded. Edge cases include transactions on boundary dates, empty results,
 * and invalid date ranges.
 */
class TransactionDateRangeFilteringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Transaction Date Range Filtering
     * 
     * Test that only transactions within the specified date range are returned.
     * Transactions outside the date range should be excluded.
     */
    public function transaction_date_range_filtering_returns_only_transactions_within_range()
    {
        // Run 75 iterations with different scenarios
        for ($i = 0; $i < 75; $i++) {
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
            
            // Create group
            $group = Group::factory()->create([
                'name' => 'Group_' . $i . '_' . uniqid(),
                'creator_id' => $creator->id,
            ]);
            
            // Add user to group
            $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
            
            // Generate random date range for filtering
            $baseDate = Carbon::now()->subDays(mt_rand(30, 60));
            $fromDate = $baseDate->copy()->addDays(mt_rand(0, 10));
            $toDate = $fromDate->copy()->addDays(mt_rand(5, 15));
            
            // Create transactions before, within, and after the date range
            $transactionsBeforeRange = [];
            $transactionsWithinRange = [];
            $transactionsAfterRange = [];
            
            // Transactions before the range (should be excluded)
            $beforeCount = mt_rand(1, 3);
            for ($j = 0; $j < $beforeCount; $j++) {
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_before_{$i}_{$j}_" . uniqid(),
                    'total_amount' => round(mt_rand(100, 5000) / 100, 2),
                ]);
                
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => round(mt_rand(50, 2000) / 100, 2),
                    'status' => 'unpaid',
                ]);
                
                $createdAt = $fromDate->copy()->subDays(mt_rand(1, 10));
                $transaction = new Transaction([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $share->amount,
                    'payment_method' => 'gcash',
                    'paymongo_transaction_id' => 'pay_before_' . $i . '_' . $j . '_' . uniqid(),
                    'status' => 'paid',
                    'paid_at' => $createdAt,
                ]);
                $transaction->created_at = $createdAt;
                $transaction->updated_at = $createdAt;
                $transaction->save();
                
                $transactionsBeforeRange[] = $transaction;
            }
            
            // Transactions within the range (should be included)
            $withinCount = mt_rand(3, 7);
            for ($j = 0; $j < $withinCount; $j++) {
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_within_{$i}_{$j}_" . uniqid(),
                    'total_amount' => round(mt_rand(100, 5000) / 100, 2),
                ]);
                
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => round(mt_rand(50, 2000) / 100, 2),
                    'status' => 'unpaid',
                ]);
                
                // Create transaction within the date range
                $daysDiff = $fromDate->diffInDays($toDate);
                $daysOffset = $daysDiff > 0 ? mt_rand(0, $daysDiff) : 0;
                $createdAt = $fromDate->copy()->addDays($daysOffset);
                
                $transaction = new Transaction([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $share->amount,
                    'payment_method' => mt_rand(0, 1) ? 'gcash' : 'paymaya',
                    'paymongo_transaction_id' => 'pay_within_' . $i . '_' . $j . '_' . uniqid(),
                    'status' => ['pending', 'paid', 'failed'][mt_rand(0, 2)],
                    'paid_at' => $createdAt,
                ]);
                $transaction->created_at = $createdAt;
                $transaction->updated_at = $createdAt;
                $transaction->save();
                
                $transactionsWithinRange[] = $transaction;
            }
            
            // Transactions after the range (should be excluded)
            $afterCount = mt_rand(1, 3);
            for ($j = 0; $j < $afterCount; $j++) {
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_after_{$i}_{$j}_" . uniqid(),
                    'total_amount' => round(mt_rand(100, 5000) / 100, 2),
                ]);
                
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => round(mt_rand(50, 2000) / 100, 2),
                    'status' => 'unpaid',
                ]);
                
                $createdAt = $toDate->copy()->addDays(mt_rand(1, 10));
                $transaction = new Transaction([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $share->amount,
                    'payment_method' => 'paymaya',
                    'paymongo_transaction_id' => 'pay_after_' . $i . '_' . $j . '_' . uniqid(),
                    'status' => 'pending',
                ]);
                $transaction->created_at = $createdAt;
                $transaction->updated_at = $createdAt;
                $transaction->save();
                
                $transactionsAfterRange[] = $transaction;
            }
            
            // Query with date range filter
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?' . http_build_query([
                    'from_date' => $fromDate->format('Y-m-d'),
                    'to_date' => $toDate->format('Y-m-d'),
                ]));
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Response has correct structure
            $this->assertArrayHasKey('data', $responseData, "Iteration $i: Response should have 'data' key");
            $this->assertArrayHasKey('summary', $responseData, "Iteration $i: Response should have 'summary' key");
            $this->assertArrayHasKey('meta', $responseData, "Iteration $i: Response should have 'meta' key");
            
            $transactions = $responseData['data'];
            
            // Only transactions within date range are returned
            $this->assertCount(
                $withinCount,
                $transactions,
                "Iteration $i: Should return exactly $withinCount transactions within date range"
            );
            
            // All returned transactions are within the date range
            foreach ($transactions as $txIndex => $transaction) {
                $createdAt = Carbon::parse($transaction['created_at']);
                
                $this->assertTrue(
                    $createdAt->greaterThanOrEqualTo($fromDate->startOfDay()) &&
                    $createdAt->lessThanOrEqualTo($toDate->endOfDay()),
                    "Iteration $i, Transaction $txIndex: Transaction created_at ({$createdAt->format('Y-m-d')}) should be within range ({$fromDate->format('Y-m-d')} to {$toDate->format('Y-m-d')})"
                );
            }
            
            // Transactions before range are excluded
            $returnedIds = collect($transactions)->pluck('id')->toArray();
            foreach ($transactionsBeforeRange as $txIndex => $beforeTx) {
                $this->assertNotContains(
                    $beforeTx->id,
                    $returnedIds,
                    "Iteration $i, Before Transaction $txIndex: Transaction before date range should be excluded"
                );
            }
            
            // Transactions after range are excluded
            foreach ($transactionsAfterRange as $txIndex => $afterTx) {
                $this->assertNotContains(
                    $afterTx->id,
                    $returnedIds,
                    "Iteration $i, After Transaction $txIndex: Transaction after date range should be excluded"
                );
            }
            
            // All transactions within range are included
            foreach ($transactionsWithinRange as $txIndex => $withinTx) {
                $this->assertContains(
                    $withinTx->id,
                    $returnedIds,
                    "Iteration $i, Within Transaction $txIndex: Transaction within date range should be included"
                );
            }
            
            // Returned transactions match created data
            foreach ($transactionsWithinRange as $txIndex => $withinTx) {
                $foundTransaction = collect($transactions)->firstWhere('id', $withinTx->id);
                
                $this->assertNotNull(
                    $foundTransaction,
                    "Iteration $i, Transaction $txIndex: Transaction should be in response"
                );
                
                $this->assertEquals(
                    (float) $withinTx->amount,
                    (float) $foundTransaction['amount'],
                    "Iteration $i, Transaction $txIndex: Amount should match"
                );
                
                $this->assertEquals(
                    $withinTx->payment_method,
                    $foundTransaction['payment_method'],
                    "Iteration $i, Transaction $txIndex: Payment method should match"
                );
                
                $this->assertEquals(
                    $withinTx->status,
                    $foundTransaction['status'],
                    "Iteration $i, Transaction $txIndex: Status should match"
                );
            }
        }
    }

    /**
     * @test
     * Edge case: Transactions on boundary dates (from_date and to_date) are included
     */
    public function transaction_date_range_filtering_includes_boundary_dates()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $fromDate = Carbon::now()->subDays(10);
        $toDate = Carbon::now()->subDays(5);
        
        // Create transaction exactly on from_date
        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        $share1 = Share::create([
            'bill_id' => $bill1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        $tx1 = new Transaction([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_boundary_from_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $fromDate,
        ]);
        $tx1->created_at = $fromDate->copy()->setTime(0, 0, 0);
        $tx1->updated_at = $fromDate->copy()->setTime(0, 0, 0);
        $tx1->save();
        
        // Create transaction exactly on to_date
        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 200.00,
        ]);
        $share2 = Share::create([
            'bill_id' => $bill2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'unpaid',
        ]);
        $tx2 = new Transaction([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_boundary_to_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $toDate,
        ]);
        $tx2->created_at = $toDate->copy()->setTime(23, 59, 59);
        $tx2->updated_at = $toDate->copy()->setTime(23, 59, 59);
        $tx2->save();
        
        // Query with date range
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        // Both boundary transactions should be included
        $this->assertCount(2, $transactions, 'Both boundary date transactions should be included');
        
        $returnedIds = collect($transactions)->pluck('id')->toArray();
        $this->assertContains($tx1->id, $returnedIds, 'Transaction on from_date should be included');
        $this->assertContains($tx2->id, $returnedIds, 'Transaction on to_date should be included');
    }

    /**
     * @test
     * Edge case: Empty result when no transactions exist in date range
     */
    public function transaction_date_range_filtering_returns_empty_when_no_transactions_in_range()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create transaction outside the query range
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        $oldDate = Carbon::now()->subDays(30);
        $tx = new Transaction([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_old_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $oldDate,
        ]);
        $tx->created_at = $oldDate;
        $tx->updated_at = $oldDate;
        $tx->save();
        
        // Query with date range that excludes the transaction
        $fromDate = Carbon::now()->subDays(10);
        $toDate = Carbon::now()->subDays(5);
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(0, $transactions, 'Should return empty array when no transactions in range');
    }

    /**
     * @test
     * Edge case: Invalid date range (to_date before from_date) returns validation error
     */
    public function transaction_date_range_filtering_rejects_invalid_date_range()
    {
        $user = User::factory()->create();
        
        $fromDate = Carbon::now()->subDays(5);
        $toDate = Carbon::now()->subDays(10); // Invalid: to_date before from_date
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to_date']);
    }

    /**
     * @test
     * Edge case: Only from_date specified (no to_date)
     */
    public function transaction_date_range_filtering_works_with_only_from_date()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $fromDate = Carbon::now()->subDays(10);
        
        // Create transaction before from_date (should be excluded)
        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        $share1 = Share::create([
            'bill_id' => $bill1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        $oldDate = $fromDate->copy()->subDays(5);
        $tx1 = new Transaction([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_old_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $oldDate,
        ]);
        $tx1->created_at = $oldDate;
        $tx1->updated_at = $oldDate;
        $tx1->save();
        
        // Create transaction after from_date (should be included)
        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 200.00,
        ]);
        $share2 = Share::create([
            'bill_id' => $bill2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'unpaid',
        ]);
        $recentDate = $fromDate->copy()->addDays(2);
        $tx2 = new Transaction([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_recent_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $recentDate,
        ]);
        $tx2->created_at = $recentDate;
        $tx2->updated_at = $recentDate;
        $tx2->save();
        
        // Query with only from_date
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'from_date' => $fromDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(1, $transactions, 'Should return only transactions after from_date');
        $this->assertEquals($tx2->id, $transactions[0]['id'], 'Should return the recent transaction');
    }

    /**
     * @test
     * Edge case: Only to_date specified (no from_date)
     */
    public function transaction_date_range_filtering_works_with_only_to_date()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $toDate = Carbon::now()->subDays(5);
        
        // Create transaction before to_date (should be included)
        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        $share1 = Share::create([
            'bill_id' => $bill1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        $oldDate = $toDate->copy()->subDays(5);
        $tx1 = new Transaction([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_old_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $oldDate,
        ]);
        $tx1->created_at = $oldDate;
        $tx1->updated_at = $oldDate;
        $tx1->save();
        
        // Create transaction after to_date (should be excluded)
        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 200.00,
        ]);
        $share2 = Share::create([
            'bill_id' => $bill2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'unpaid',
        ]);
        $recentDate = $toDate->copy()->addDays(5);
        $tx2 = new Transaction([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_recent_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $recentDate,
        ]);
        $tx2->created_at = $recentDate;
        $tx2->updated_at = $recentDate;
        $tx2->save();
        
        // Query with only to_date
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'to_date' => $toDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(1, $transactions, 'Should return only transactions before to_date');
        $this->assertEquals($tx1->id, $transactions[0]['id'], 'Should return the old transaction');
    }

    /**
     * @test
     * Edge case: Same from_date and to_date (single day)
     */
    public function transaction_date_range_filtering_works_with_same_from_and_to_date()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $targetDate = Carbon::now()->subDays(7);
        
        // Create transaction on target date (should be included)
        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        $share1 = Share::create([
            'bill_id' => $bill1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        $tx1 = new Transaction([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_target_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $targetDate,
        ]);
        $tx1->created_at = $targetDate->copy()->setTime(12, 0, 0);
        $tx1->updated_at = $targetDate->copy()->setTime(12, 0, 0);
        $tx1->save();
        
        // Create transaction on different date (should be excluded)
        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 200.00,
        ]);
        $share2 = Share::create([
            'bill_id' => $bill2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'unpaid',
        ]);
        $otherDate = $targetDate->copy()->addDays(1);
        $tx2 = new Transaction([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_other_' . uniqid(),
            'status' => 'paid',
            'paid_at' => $otherDate,
        ]);
        $tx2->created_at = $otherDate;
        $tx2->updated_at = $otherDate;
        $tx2->save();
        
        // Query with same from_date and to_date
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?' . http_build_query([
                'from_date' => $targetDate->format('Y-m-d'),
                'to_date' => $targetDate->format('Y-m-d'),
            ]));
        
        $response->assertStatus(200);
        $transactions = $response->json('data');
        
        $this->assertCount(1, $transactions, 'Should return only transactions on the target date');
        $this->assertEquals($tx1->id, $transactions[0]['id'], 'Should return the transaction on target date');
    }
}
