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
 * Property-Based Test: Unpaid Shares Total Calculation
 * 
 * This test validates that total_owed in the transaction summary equals
 * the sum of all unpaid share amounts for the authenticated user.
 * The calculation should respect group filters when applied.
 */
class UnpaidSharesTotalCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Unpaid Shares Total Calculation
     * 
     * Test that total_owed equals sum of unpaid share amounts.
     */
    public function unpaid_shares_total_calculation_property()
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
            
            // Generate random number of bills (5-15)
            $billCount = mt_rand(5, 15);
            $allShares = [];
            
            // Create bills with shares
            for ($b = 0; $b < $billCount; $b++) {
                // Random group
                $group = $groups[mt_rand(0, $groupCount - 1)];
                
                // Random bill amount
                $totalAmount = round(mt_rand(100, 10000) / 100, 2);
                
                // Create bill
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $creator->id,
                    'title' => "Bill_{$i}_{$b}_" . uniqid(),
                    'total_amount' => $totalAmount,
                ]);
                
                // Create shares for the user (1-3 shares per bill)
                $shareCount = mt_rand(1, 3);
                
                for ($s = 0; $s < $shareCount; $s++) {
                    // Random share amount
                    $shareAmount = round(mt_rand(50, 2000) / 100, 2);
                    
                    // Random status (60% unpaid, 40% paid)
                    $isPaid = mt_rand(1, 100) <= 40;
                    $status = $isPaid ? 'paid' : 'unpaid';
                    
                    // Create share
                    $share = Share::create([
                        'bill_id' => $bill->id,
                        'user_id' => $user->id,
                        'amount' => $shareAmount,
                        'status' => $status,
                    ]);
                    
                    // If paid, create a transaction
                    if ($isPaid) {
                        Transaction::create([
                            'share_id' => $share->id,
                            'user_id' => $user->id,
                            'amount' => $shareAmount,
                            'payment_method' => mt_rand(0, 1) ? 'gcash' : 'paymaya',
                            'paymongo_transaction_id' => 'pay_' . $i . '_' . $b . '_' . $s . '_' . uniqid(),
                            'status' => 'paid',
                            'paid_at' => now(),
                        ]);
                    }
                    
                    $allShares[] = [
                        'share' => $share,
                        'group_id' => $group->id,
                        'status' => $status,
                        'amount' => $shareAmount,
                    ];
                }
            }
            
            // Test Scenario 1: No filters (all groups)
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions');
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            $this->assertArrayHasKey('summary', $responseData, "Iteration $i: Response should have 'summary' key");
            $this->assertArrayHasKey('total_owed', $responseData['summary'], "Iteration $i: Summary should have 'total_owed' key");
            
            // Calculate expected total_owed (all unpaid shares)
            $expectedTotalOwed = collect($allShares)
                ->where('status', 'unpaid')
                ->sum('amount');
            
            $actualTotalOwed = (float) $responseData['summary']['total_owed'];
            
            $this->assertEquals(
                round($expectedTotalOwed, 2),
                round($actualTotalOwed, 2),
                "Iteration $i (No filters): total_owed should equal sum of all unpaid shares. Expected: $expectedTotalOwed, Got: $actualTotalOwed"
            );
            
            // Test Scenario 2: Group filter
            $targetGroup = $groups[0];
            
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/transactions?group_id=' . $targetGroup->id);
            
            $response->assertStatus(200);
            $responseData = $response->json();
            
            // Calculate expected total_owed (unpaid shares in target group)
            $expectedTotalOwed = collect($allShares)
                ->where('status', 'unpaid')
                ->where('group_id', $targetGroup->id)
                ->sum('amount');
            
            $actualTotalOwed = (float) $responseData['summary']['total_owed'];
            
            $this->assertEquals(
                round($expectedTotalOwed, 2),
                round($actualTotalOwed, 2),
                "Iteration $i (Group filter): total_owed should equal sum of unpaid shares in target group. Expected: $expectedTotalOwed, Got: $actualTotalOwed"
            );
        }
    }

    /**
     * @test
     * Edge case: No unpaid shares (total_owed = 0)
     */
    public function total_owed_is_zero_when_no_unpaid_shares()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create only paid shares
        for ($i = 0; $i < 3; $i++) {
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
            
            Transaction::create([
                'share_id' => $share->id,
                'user_id' => $user->id,
                'amount' => 100.00,
                'payment_method' => 'gcash',
                'paymongo_transaction_id' => 'pay_all_paid_' . $i . '_' . uniqid(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertEquals(0.0, (float) $response->json('summary.total_owed'));
    }

    /**
     * @test
     * Edge case: All shares unpaid
     */
    public function total_owed_equals_sum_when_all_shares_unpaid()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $expectedTotal = 0.0;
        
        // Create 5 unpaid shares
        for ($i = 0; $i < 5; $i++) {
            $amount = round(mt_rand(100, 1000) / 100, 2);
            $expectedTotal += $amount;
            
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'total_amount' => $amount,
            ]);
            
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'unpaid',
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertEquals(
            round($expectedTotal, 2),
            round((float) $response->json('summary.total_owed'), 2)
        );
    }

    /**
     * @test
     * Edge case: Mixed paid and unpaid shares
     */
    public function total_owed_only_counts_unpaid_shares()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $unpaidTotal = 0.0;
        $paidTotal = 0.0;
        
        // Create 3 unpaid shares
        for ($i = 0; $i < 3; $i++) {
            $amount = round(mt_rand(100, 500) / 100, 2);
            $unpaidTotal += $amount;
            
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'total_amount' => $amount,
            ]);
            
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'unpaid',
            ]);
        }
        
        // Create 2 paid shares
        for ($i = 0; $i < 2; $i++) {
            $amount = round(mt_rand(100, 500) / 100, 2);
            $paidTotal += $amount;
            
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
                'paymongo_transaction_id' => 'pay_mixed_' . $i . '_' . uniqid(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        
        // total_owed should only include unpaid shares
        $this->assertEquals(
            round($unpaidTotal, 2),
            round((float) $response->json('summary.total_owed'), 2)
        );
        
        // Verify paid shares are not included
        $actualTotalOwed = (float) $response->json('summary.total_owed');
        $this->assertNotEquals(
            round($unpaidTotal + $paidTotal, 2),
            round($actualTotalOwed, 2),
            'total_owed should not include paid shares'
        );
    }

    /**
     * @test
     * Edge case: Multiple groups with different unpaid amounts
     */
    public function total_owed_calculates_correctly_across_multiple_groups()
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
        
        // Group 1: 100.00 unpaid
        $bill1 = Bill::factory()->create(['group_id' => $group1->id, 'creator_id' => $creator->id]);
        Share::create(['bill_id' => $bill1->id, 'user_id' => $user->id, 'amount' => 100.00, 'status' => 'unpaid']);
        
        // Group 2: 200.00 unpaid
        $bill2 = Bill::factory()->create(['group_id' => $group2->id, 'creator_id' => $creator->id]);
        Share::create(['bill_id' => $bill2->id, 'user_id' => $user->id, 'amount' => 200.00, 'status' => 'unpaid']);
        
        // Group 3: 300.00 unpaid
        $bill3 = Bill::factory()->create(['group_id' => $group3->id, 'creator_id' => $creator->id]);
        Share::create(['bill_id' => $bill3->id, 'user_id' => $user->id, 'amount' => 300.00, 'status' => 'unpaid']);
        
        // Test: No filter (all groups)
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertEquals(600.00, (float) $response->json('summary.total_owed'));
        
        // Test: Filter by group1
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group1->id);
        
        $response->assertStatus(200);
        $this->assertEquals(100.00, (float) $response->json('summary.total_owed'));
        
        // Test: Filter by group2
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $group2->id);
        
        $response->assertStatus(200);
        $this->assertEquals(200.00, (float) $response->json('summary.total_owed'));
    }

    /**
     * @test
     * Edge case: Group filter with no unpaid shares
     */
    public function total_owed_is_zero_when_group_has_no_unpaid_shares()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create empty group
        $emptyGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $emptyGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        // Create another group with unpaid shares
        $otherGroup = Group::factory()->create(['creator_id' => $creator->id]);
        $otherGroup->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $otherGroup->id,
            'creator_id' => $creator->id,
            'total_amount' => 100.00,
        ]);
        
        Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        
        // Query empty group
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=' . $emptyGroup->id);
        
        $response->assertStatus(200);
        $this->assertEquals(0.0, (float) $response->json('summary.total_owed'));
    }

    /**
     * @test
     * Edge case: User with no shares at all
     */
    public function total_owed_is_zero_when_user_has_no_shares()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $this->assertEquals(0.0, (float) $response->json('summary.total_owed'));
    }

    /**
     * @test
     * Edge case: Shares from other users should not affect total_owed
     */
    public function total_owed_only_includes_authenticated_user_shares()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $creator = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $otherUser->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 600.00,
        ]);
        
        // User's unpaid share: 200.00
        Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 200.00,
            'status' => 'unpaid',
        ]);
        
        // Other user's unpaid share: 400.00 (should not be included)
        Share::create([
            'bill_id' => $bill->id,
            'user_id' => $otherUser->id,
            'amount' => 400.00,
            'status' => 'unpaid',
        ]);
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        
        // Should only include user's unpaid share (200.00), not other user's (400.00)
        $this->assertEquals(200.00, (float) $response->json('summary.total_owed'));
        $this->assertNotEquals(600.00, (float) $response->json('summary.total_owed'));
    }
}
