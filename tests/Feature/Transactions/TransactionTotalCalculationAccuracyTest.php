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
 * Property-Based Test: Transaction Total Calculation Accuracy
 * 
 * This test validates that total_paid in the summary equals the sum of all paid
 * transaction amounts that match the applied filters (date range and group_id).
 */
class TransactionTotalCalculationAccuracyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Transaction Total Calculation Accuracy
     * 
     * Test that total_paid equals sum of filtered transaction amounts.
     */
    public function transaction_total_paid_equals_sum_of_filtered_paid_transactions()
    {
        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
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
            
            // Create multiple groups (2-3 groups)
            $groupCount = mt_rand(2, 3);
            $groups = [];
            
            for ($g = 0; $g < $groupCount; $g++) {
                $group = Group::factory()->create([
                    'name' => 'Group_' . $i . '_' . $g . '_' . uniqid(),
                    'creator_id' => $creator->id,
                ]);
                
                $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
                $groups[] = $group;
            }
            
            // Generate random number of transactions (5-20)
            $transactionCount = mt_rand(5, 20);
            $allTransactions = [];
            
            // Random date range for some transactions
            $baseDate = Carbon::now()->subDays(mt_rand(30, 60));
            $filterFromDate = $baseDate->copy()->addDays(mt_rand(0, 10));
            $filterToDate = $filterFromDate->copy()->addDays(mt_rand(5, 15));
            
            // Create transactions with random attributes
            for ($t = 0; $t < $transactionCount; $t++) {
                // Random group
                $group = $groups[mt_rand(0, $groupCount - 1)];
                
                // Random date (some within filter range, some outside)
                $inDateRange = mt_rand(0, 1);
                if ($inDateRange) {
                    $daysDiff = $filterFromDate->diffInDays($filterToDate);
                    $daysOffset = $daysDiff > 0 ? mt_rand(0, $daysDiff) : 0;
                    $createdAt = $filterFromDate->copy()->addDays($daysOffset);
                } else {
                    // Outside date range
                    if (mt_rand(0, 1)) {
                        $createdAt = $filterFromDate->copy()->subDays(mt_rand(1, 10));
                    } else {
                        $createdAt = $filterToDate->copy()->addDays(mt_rand(1, 10));
                    }
                }
                
                // Random status
                $status = ['pending', 'paid', 'failed'][mt_rand(0, 2)];
                
                // Create bill
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_{$i}_{$t}_" . uniqid(),
                    'total_amount' => round(mt_rand(100, 10000) / 100, 2),
                ]);
                
                // Create share
                $shareAmount = round(mt_rand(50, 5000) / 100, 2);
                $share = Share::create([
                    'bill_id' => $bill->id,
                    'user_id' => $user->id,
                    'amount' => $shareAmount,
                    'status' => $status === 'paid' ? 'paid' : 'unpaid',
                ]);
                
                // Create transaction
                $transaction = new Transaction([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $shareAmount,
                    'payment_method' => mt_rand(0, 1) ? 'gcash' : 'paymaya',
                    'paymongo_transaction_id' => 'pay_' . $i . '_' . $t . '_' . uniqid(),
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? $createdAt : null,
                ]);
                $transaction->created_at = $createdAt;
                $transaction->updated_at = $createdAt;
                $transaction->save();
                
                $allTransactions[] = [
                    'transaction' => $transaction,
                    'group_id' => $group->id,
                    'created_at' => $createdAt,
                    'status' => $status,
                    'amount' => $shareAmount,
                ];
            }
            
            // Test Scenario 1: No filters
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions');
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            $this->assertArrayHasKey('summary', $responseData, "Iteration $i: Response should have 'summary' key");
            $this->assertArrayHasKey('total_paid', $responseData['summary'], "Iteration $i: Summary should have 'total_paid' key");
            
            // Calculate expected total_paid (all paid transactions)
            $expectedTotalPaid = collect($allTransactions)
                ->where('status', 'paid')
                ->sum('amount');
            
            $actualTotalPaid = (float) $responseData['summary']['total_paid'];
            
            $this->assertEquals(
                round($expectedTotalPaid, 2),
                round($actualTotalPaid, 2),
                "Iteration $i (No filters): total_paid should equal sum of all paid transactions. Expected: $expectedTotalPaid, Got: $actualTotalPaid"
            );
            
            // Test Scenario 2: Date range filter only
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?' . http_build_query([
                    'from_date' => $filterFromDate->format('Y-m-d'),
                    'to_date' => $filterToDate->format('Y-m-d'),
                ]));
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Calculate expected total_paid (paid transactions within date range)
            $expectedTotalPaid = collect($allTransactions)
                ->filter(function ($tx) use ($filterFromDate, $filterToDate) {
                    $createdAt = $tx['created_at'];
                    return $tx['status'] === 'paid' &&
                           $createdAt->greaterThanOrEqualTo($filterFromDate->startOfDay()) &&
                           $createdAt->lessThanOrEqualTo($filterToDate->endOfDay());
                })
                ->sum('amount');
            
            $actualTotalPaid = (float) $responseData['summary']['total_paid'];
            
            $this->assertEquals(
                round($expectedTotalPaid, 2),
                round($actualTotalPaid, 2),
                "Iteration $i (Date filter): total_paid should equal sum of paid transactions within date range. Expected: $expectedTotalPaid, Got: $actualTotalPaid"
            );
            
            // Test Scenario 3: Group filter only
            $targetGroup = $groups[0];
            
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?group_id=' . $targetGroup->id);
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Calculate expected total_paid (paid transactions in target group)
            $expectedTotalPaid = collect($allTransactions)
                ->where('status', 'paid')
                ->where('group_id', $targetGroup->id)
                ->sum('amount');
            
            $actualTotalPaid = (float) $responseData['summary']['total_paid'];
            
            $this->assertEquals(
                round($expectedTotalPaid, 2),
                round($actualTotalPaid, 2),
                "Iteration $i (Group filter): total_paid should equal sum of paid transactions in target group. Expected: $expectedTotalPaid, Got: $actualTotalPaid"
            );
            
            // Test Scenario 4: Combined filters (date range + group)
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?' . http_build_query([
                    'from_date' => $filterFromDate->format('Y-m-d'),
                    'to_date' => $filterToDate->format('Y-m-d'),
                    'group_id' => $targetGroup->id,
                ]));
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Calculate expected total_paid (paid transactions in target group within date range)
            $expectedTotalPaid = collect($allTransactions)
                ->filter(function ($tx) use ($filterFromDate, $filterToDate, $targetGroup) {
                    $createdAt = $tx['created_at'];
                    return $tx['status'] === 'paid' &&
                           $tx['group_id'] === $targetGroup->id &&
                           $createdAt->greaterThanOrEqualTo($filterFromDate->startOfDay()) &&
                           $createdAt->lessThanOrEqualTo($filterToDate->endOfDay());
                })
                ->sum('amount');
            
            $actualTotalPaid = (float) $responseData['summary']['total_paid'];
            
            $this->assertEquals(
                round($expectedTotalPaid, 2),
                round($actualTotalPaid, 2),
                "Iteration $i (Combined filters): total_paid should equal sum of paid transactions matching all filters. Expected: $expectedTotalPaid, Got: $actualTotalPaid"
            );
        }
    }

    /**
     * @test
     * Edge case: No transactions (total_paid = 0)
     */
    public function transaction_total_paid_is_zero_when_no_transactions()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        $this->assertEquals(0.0, (float) $responseData['summary']['total_paid']);
    }

    /**
     * @test
     * Edge case: All transactions paid
     */
    public function transaction_total_paid_equals_sum_when_all_transactions_paid()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $expectedTotal = 0.0;
        
        // Create 5 paid transactions
        for ($i = 0; $i < 5; $i++) {
            $amount = round(mt_rand(100, 1000) / 100, 2);
            $expectedTotal += $amount;
            
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'total_amount' => $amount,
            ]);
            
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'paid',
            ]);
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_all_paid_' . $i . '_' . uniqid(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        $this->assertEquals(
            round($expectedTotal, 2),
            round((float) $responseData['summary']['total_paid'], 2)
        );
    }

    /**
     * @test
     * Edge case: All transactions pending/failed (total_paid = 0)
     */
    public function transaction_total_paid_is_zero_when_all_transactions_not_paid()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create pending and failed transactions
        $statuses = ['pending', 'failed'];
        
        for ($i = 0; $i < 4; $i++) {
            $amount = round(mt_rand(100, 1000) / 100, 2);
            $status = $statuses[$i % 2];
            
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'total_amount' => $amount,
            ]);
            
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'unpaid',
            ]);
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_not_paid_' . $i . '_' . uniqid(),
                'status' => $status,
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        $this->assertEquals(0.0, (float) $responseData['summary']['total_paid']);
    }

    /**
     * @test
     * Edge case: Date range with no matching transactions (total_paid = 0)
     */
    public function transaction_total_paid_is_zero_when_no_transactions_in_date_range()
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
            'status' => 'paid',
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
        $responseData = $response->json();
        
        $this->assertEquals(0.0, (float) $responseData['summary']['total_paid']);
    }

    /**
     * @test
     * Edge case: Group filter with no transactions (total_paid = 0)
     */
    public function transaction_total_paid_is_zero_when_no_transactions_in_group()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create empty group
        $emptyGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $emptyGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create another group with transactions
        $otherGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $otherGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $otherGroup->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'paid',
        ]);
        
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
        $responseData = $response->json();
        
        $this->assertEquals(0.0, (float) $responseData['summary']['total_paid']);
    }

    /**
     * @test
     * Edge case: Multiple groups with different transaction amounts
     */
    public function transaction_total_paid_calculates_correctly_across_multiple_groups()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create 3 groups
        $group1 = Group::factory()->create(['creator_id' => $creator->id]);
        $group2 = Group::factory()->create(['creator_id' => $creator->id]);
        $group3 = Group::factory()->create(['creator_id' => $creator->id]);
        
        $group1->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        $group2->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        $group3->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Group 1: 100.00 paid
        $bill1 = Bill::factory()->create(['group_id' => $group1->id, 'creator_id' => $creator->id]);
        $share1 = Share::create(['bill_id' => $bill1->id, 'user_id' => $user->id, 'amount' => 100.00, 'status' => 'paid']);
        Transaction::create([
            'share_id' => $share1->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_g1_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Group 2: 200.00 paid
        $bill2 = Bill::factory()->create(['group_id' => $group2->id, 'creator_id' => $creator->id]);
        $share2 = Share::create(['bill_id' => $bill2->id, 'user_id' => $user->id, 'amount' => 200.00, 'status' => 'paid']);
        Transaction::create([
            'share_id' => $share2->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'payment_method' => 'paymaya',
            'paymongo_transaction_id' => 'pay_g2_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Group 3: 300.00 paid
        $bill3 = Bill::factory()->create(['group_id' => $group3->id, 'creator_id' => $creator->id]);
        $share3 = Share::create(['bill_id' => $bill3->id, 'user_id' => $user->id, 'amount' => 300.00, 'status' => 'paid']);
        Transaction::create([
            'share_id' => $share3->id,
            'user_id' => $user->id,
            'amount' => 300.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_g3_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Test: No filter (all groups)
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertEquals(600.00, (float) $response->json('summary.total_paid'));
        
        // Test: Filter by group1
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group1->id);
        
        $response->assertStatus(200);
        $this->assertEquals(100.00, (float) $response->json('summary.total_paid'));
        
        // Test: Filter by group2
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group2->id);
        
        $response->assertStatus(200);
        $this->assertEquals(200.00, (float) $response->json('summary.total_paid'));
    }

    /**
     * @test
     * Edge case: Mixed statuses - only paid transactions counted
     */
    public function transaction_total_paid_only_counts_paid_status_transactions()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create transactions with different statuses
        $amounts = [
            'paid' => 100.00,
            'pending' => 200.00,
            'failed' => 300.00,
        ];
        
        foreach ($amounts as $status => $amount) {
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'total_amount' => $amount,
            ]);
            
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => $status === 'paid' ? 'paid' : 'unpaid',
            ]);
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_' . $status . '_' . uniqid(),
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Only the paid transaction (100.00) should be counted
        $this->assertEquals(100.00, (float) $responseData['summary']['total_paid']);
    }
}
