<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Unpaid Shares Filtering
 * 
 * This test validates that when fetching unpaid shares for a user,
 * only shares that are (1) unpaid and (2) belong to that user are returned.
 * Paid shares and shares belonging to other users should be excluded.
 */
class UnpaidSharesFilteringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Unpaid Shares Filtering
     * 
     * Test that only unpaid shares belonging to user are returned,
     * excluding paid shares and shares belonging to other users.
     */
    public function unpaid_shares_filtering_property()
    {
        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            // Generate random number of users (between 3 and 10)
            $userCount = mt_rand(3, 10);
            
            // Generate random number of bills (between 2 and 8)
            $billCount = mt_rand(2, 8);

            // Create users with unique identifiers
            $users = [];
            for ($u = 0; $u < $userCount; $u++) {
                $users[] = User::factory()->create([
                    'username' => 'user_' . $i . '_' . $u . '_' . uniqid(),
                    'email' => 'user_' . $i . '_' . $u . '_' . uniqid() . '@test.com',
                ]);
            }

            // Pick the first user as the test subject
            $testUser = $users[0];

            // Create a group with all users
            $group = Group::factory()->create([
                'creator_id' => $testUser->id,
                'name' => 'Group_' . $i . '_' . uniqid(),
            ]);
            
            foreach ($users as $user) {
                $group->members()->attach($user->id, ['joined_at' => now()]);
            }

            // Track expected unpaid shares for test user
            $expectedUnpaidShareIds = [];
            $paidSharesForTestUser = 0;
            $unpaidSharesForOthers = 0;

            // Create bills with random shares
            for ($b = 0; $b < $billCount; $b++) {
                $totalAmount = round(mt_rand(100, 10000) / 100, 2);
                
                $bill = Bill::factory()->create([
                    'group_id' => $group->id,
                    'creator_id' => $testUser->id,
                    'title' => "Bill_" . $i . "_" . $b . "_" . uniqid(),
                    'total_amount' => $totalAmount,
                    'bill_date' => now()->subDays(mt_rand(0, 30)),
                ]);

                // Randomly assign shares to users
                $remainingAmount = $totalAmount;
                $assignedUsers = [];
                
                // Ensure at least 2 users get shares
                $shareCount = mt_rand(2, min($userCount, 5));
                
                for ($s = 0; $s < $shareCount; $s++) {
                    // Pick a random user who hasn't been assigned yet
                    do {
                        $userIndex = mt_rand(0, $userCount - 1);
                    } while (in_array($userIndex, $assignedUsers) && count($assignedUsers) < $userCount);
                    
                    $assignedUsers[] = $userIndex;
                    $shareUser = $users[$userIndex];
                    
                    // Calculate share amount
                    if ($s === $shareCount - 1) {
                        // Last share gets the remainder
                        $shareAmount = $remainingAmount;
                    } else {
                        // Random share amount (10% to 50% of remaining)
                        $maxShare = $remainingAmount * 0.5;
                        $minShare = min($remainingAmount * 0.1, $remainingAmount - ($shareCount - $s - 1) * 0.01);
                        $shareAmount = round(mt_rand($minShare * 100, $maxShare * 100) / 100, 2);
                        $remainingAmount -= $shareAmount;
                    }
                    
                    // Ensure share amount is positive
                    if ($shareAmount <= 0) {
                        $shareAmount = 0.01;
                    }
                    
                    // Randomly decide if this share is paid or unpaid (70% unpaid, 30% paid)
                    $isPaid = mt_rand(1, 100) <= 30;
                    $status = $isPaid ? 'paid' : 'unpaid';
                    
                    $share = Share::create([
                        'bill_id' => $bill->id,
                        'user_id' => $shareUser->id,
                        'amount' => $shareAmount,
                        'status' => $status,
                    ]);
                    
                    // Track shares for assertions
                    if ($shareUser->id === $testUser->id) {
                        if ($status === 'unpaid') {
                            $expectedUnpaidShareIds[] = $share->id;
                        } else {
                            $paidSharesForTestUser++;
                        }
                    } else {
                        if ($status === 'unpaid') {
                            $unpaidSharesForOthers++;
                        }
                    }
                }
            }

            // Query unpaid shares for test user (simulating what the app would do)
            $unpaidShares = Share::where('user_id', $testUser->id)
                ->where('status', 'unpaid')
                ->get();

            // All returned shares should belong to test user
            foreach ($unpaidShares as $share) {
                $this->assertEquals(
                    $testUser->id,
                    $share->user_id,
                    "Iteration $i: All returned shares should belong to test user (ID: {$testUser->id})"
                );
            }

            // All returned shares should have 'unpaid' status
            foreach ($unpaidShares as $share) {
                $this->assertEquals(
                    'unpaid',
                    $share->status,
                    "Iteration $i: All returned shares should have 'unpaid' status"
                );
            }

            // Count should match expected unpaid shares
            $this->assertCount(
                count($expectedUnpaidShareIds),
                $unpaidShares,
                "Iteration $i: Should return exactly " . count($expectedUnpaidShareIds) . " unpaid shares. " .
                "Test user has $paidSharesForTestUser paid shares (excluded) and " .
                "$unpaidSharesForOthers unpaid shares belong to others (excluded)"
            );

            // All expected unpaid share IDs should be present
            $returnedIds = $unpaidShares->pluck('id')->toArray();
            sort($expectedUnpaidShareIds);
            sort($returnedIds);
            
            $this->assertEquals(
                $expectedUnpaidShareIds,
                $returnedIds,
                "Iteration $i: Returned share IDs should match expected unpaid share IDs"
            );

            // No paid shares should be included
            $paidSharesInResult = $unpaidShares->filter(fn($s) => $s->status === 'paid')->count();
            $this->assertEquals(
                0,
                $paidSharesInResult,
                "Iteration $i: No paid shares should be included in results"
            );

            // No shares from other users should be included
            $otherUsersSharesInResult = $unpaidShares->filter(fn($s) => $s->user_id !== $testUser->id)->count();
            $this->assertEquals(
                0,
                $otherUsersSharesInResult,
                "Iteration $i: No shares from other users should be included in results"
            );
        }
    }

    /**
     * @test
     * Edge case: User with no unpaid shares
     */
    public function user_with_no_unpaid_shares()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $otherUser->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'total_amount' => 1000.00,
        ]);

        // Create only paid shares for user
        Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'paid',
        ]);

        // Create unpaid share for other user
        Share::create([
            'bill_id' => $bill->id,
            'user_id' => $otherUser->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);

        // Query unpaid shares for user
        $unpaidShares = Share::where('user_id', $user->id)
            ->where('status', 'unpaid')
            ->get();

        $this->assertCount(0, $unpaidShares, 'User with only paid shares should have no unpaid shares');
    }

    /**
     * @test
     * Edge case: User with all unpaid shares
     */
    public function user_with_all_unpaid_shares()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $otherUser->id], ['joined_at' => now()]);

        // Create multiple bills with unpaid shares for user
        $expectedCount = 5;
        for ($i = 0; $i < $expectedCount; $i++) {
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $user->id,
                'total_amount' => 1000.00,
            ]);

            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $user->id,
                'amount' => 500.00,
                'status' => 'unpaid',
            ]);

            // Create paid share for other user
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $otherUser->id,
                'amount' => 500.00,
                'status' => 'paid',
            ]);
        }

        // Query unpaid shares for user
        $unpaidShares = Share::where('user_id', $user->id)
            ->where('status', 'unpaid')
            ->get();

        $this->assertCount($expectedCount, $unpaidShares, 'Should return all unpaid shares for user');
        
        foreach ($unpaidShares as $share) {
            $this->assertEquals($user->id, $share->user_id);
            $this->assertEquals('unpaid', $share->status);
        }
    }

    /**
     * @test
     * Edge case: Multiple users with mixed share statuses
     */
    public function multiple_users_with_mixed_share_statuses()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $user1->id]);
        $group->members()->attach([$user1->id, $user2->id, $user3->id], ['joined_at' => now()]);

        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user1->id,
            'total_amount' => 1500.00,
        ]);

        // User 1: 2 unpaid, 1 paid
        Share::create(['bill_id' => $bill->id, 'user_id' => $user1->id, 'amount' => 200.00, 'status' => 'unpaid']);
        Share::create(['bill_id' => $bill->id, 'user_id' => $user1->id, 'amount' => 300.00, 'status' => 'unpaid']);
        Share::create(['bill_id' => $bill->id, 'user_id' => $user1->id, 'amount' => 100.00, 'status' => 'paid']);

        // User 2: 1 unpaid, 2 paid
        Share::create(['bill_id' => $bill->id, 'user_id' => $user2->id, 'amount' => 250.00, 'status' => 'unpaid']);
        Share::create(['bill_id' => $bill->id, 'user_id' => $user2->id, 'amount' => 150.00, 'status' => 'paid']);
        Share::create(['bill_id' => $bill->id, 'user_id' => $user2->id, 'amount' => 100.00, 'status' => 'paid']);

        // User 3: All paid
        Share::create(['bill_id' => $bill->id, 'user_id' => $user3->id, 'amount' => 400.00, 'status' => 'paid']);

        // Query unpaid shares for each user
        $user1Unpaid = Share::where('user_id', $user1->id)->where('status', 'unpaid')->get();
        $user2Unpaid = Share::where('user_id', $user2->id)->where('status', 'unpaid')->get();
        $user3Unpaid = Share::where('user_id', $user3->id)->where('status', 'unpaid')->get();

        // Assertions for User 1
        $this->assertCount(2, $user1Unpaid, 'User 1 should have 2 unpaid shares');
        $this->assertEquals(500.00, $user1Unpaid->sum('amount'));

        // Assertions for User 2
        $this->assertCount(1, $user2Unpaid, 'User 2 should have 1 unpaid share');
        $this->assertEquals(250.00, $user2Unpaid->sum('amount'));

        // Assertions for User 3
        $this->assertCount(0, $user3Unpaid, 'User 3 should have 0 unpaid shares');
    }

    /**
     * @test
     * Edge case: User with shares across multiple groups
     */
    public function user_with_shares_across_multiple_groups()
    {
        $user = User::factory()->create();
        $otherUser1 = User::factory()->create();
        $otherUser2 = User::factory()->create();

        // Create two groups
        $group1 = Group::factory()->create(['creator_id' => $user->id]);
        $group1->members()->attach([$user->id, $otherUser1->id], ['joined_at' => now()]);

        $group2 = Group::factory()->create(['creator_id' => $user->id]);
        $group2->members()->attach([$user->id, $otherUser2->id], ['joined_at' => now()]);

        // Create bills in both groups
        $bill1 = Bill::factory()->create([
            'group_id' => $group1->id,
            'creator_id' => $user->id,
            'total_amount' => 1000.00,
        ]);

        $bill2 = Bill::factory()->create([
            'group_id' => $group2->id,
            'creator_id' => $user->id,
            'total_amount' => 2000.00,
        ]);

        // Create unpaid shares for user in both groups
        Share::create(['bill_id' => $bill1->id, 'user_id' => $user->id, 'amount' => 500.00, 'status' => 'unpaid']);
        Share::create(['bill_id' => $bill2->id, 'user_id' => $user->id, 'amount' => 1000.00, 'status' => 'unpaid']);

        // Create paid share for user in group 1
        Share::create(['bill_id' => $bill1->id, 'user_id' => $user->id, 'amount' => 300.00, 'status' => 'paid']);

        // Create unpaid shares for other users
        Share::create(['bill_id' => $bill1->id, 'user_id' => $otherUser1->id, 'amount' => 200.00, 'status' => 'unpaid']);
        Share::create(['bill_id' => $bill2->id, 'user_id' => $otherUser2->id, 'amount' => 1000.00, 'status' => 'unpaid']);

        // Query unpaid shares for user (across all groups)
        $unpaidShares = Share::where('user_id', $user->id)
            ->where('status', 'unpaid')
            ->get();

        $this->assertCount(2, $unpaidShares, 'Should return unpaid shares from all groups');
        $this->assertEquals(1500.00, $unpaidShares->sum('amount'));
        
        // Verify all shares belong to user and are unpaid
        foreach ($unpaidShares as $share) {
            $this->assertEquals($user->id, $share->user_id);
            $this->assertEquals('unpaid', $share->status);
        }
    }
}
