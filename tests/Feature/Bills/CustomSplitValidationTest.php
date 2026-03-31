<?php

namespace Tests\Feature\Bills;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Custom Split Validation
 * 
 * This test validates that bills with custom splits where the sum of share amounts
 * does not equal the total bill amount are rejected with proper validation error.
 */
class CustomSplitValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Property: Custom Split Validation
     * 
     * Test that bills with custom splits where sum != total are rejected
     * across various random scenarios.
     */
    public function custom_split_validation_property()
    {
        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            // Generate random total amount between 10.00 and 10000.00
            $totalAmount = round(mt_rand(1000, 1000000) / 100, 2);
            
            // Generate random number of members (between 2 and 10)
            $memberCount = mt_rand(2, 10);

            // Create a test user (bill creator) with unique username
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);

            // Create a group with the creator
            $group = Group::factory()->create(['creator_id' => $creator->id]);
            
            // Add creator as a member
            $group->members()->attach($creator->id, ['joined_at' => now()]);

            // Add additional members and collect their IDs
            $memberIds = [$creator->id];
            for ($j = 1; $j < $memberCount; $j++) {
                $member = User::factory()->create([
                    'username' => 'member_' . $i . '_' . $j . '_' . uniqid(),
                    'email' => 'member_' . $i . '_' . $j . '_' . uniqid() . '@test.com',
                ]);
                $group->members()->attach($member->id, ['joined_at' => now()]);
                $memberIds[] = $member->id;
            }

            // Generate invalid custom shares (sum != total)
            // Strategy: Create shares that sum to a different amount
            $shares = [];
            $invalidSum = 0;
            
            // Randomly choose: sum too high or sum too low
            $sumTooHigh = mt_rand(0, 1) === 1;
            
            if ($sumTooHigh) {
                // Make sum higher than total by 1% to 50%
                $targetSum = $totalAmount * (1 + mt_rand(1, 50) / 100);
            } else {
                // Make sum lower than total by 1% to 50%
                $targetSum = $totalAmount * (1 - mt_rand(1, 50) / 100);
            }
            
            // Distribute the invalid sum among members
            foreach ($memberIds as $index => $userId) {
                if ($index === count($memberIds) - 1) {
                    // Last member gets the remainder
                    $amount = round($targetSum - $invalidSum, 2);
                    // Ensure last amount is positive (at least 0.01)
                    if ($amount <= 0) {
                        $amount = 0.01;
                    }
                } else {
                    // Random amount between 1% and 40% of target sum, minimum 0.01
                    $amount = max(0.01, round($targetSum * mt_rand(1, 40) / 100, 2));
                }
                
                $invalidSum += $amount;
                $shares[] = [
                    'user_id' => $userId,
                    'amount' => $amount,
                ];
            }

            // Attempt to create bill with invalid custom split
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => "Test Bill Iteration $i",
                    'total_amount' => $totalAmount,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'custom',
                    'shares' => $shares,
                ]);

            // Property: Should be rejected with 422 validation error
            $response->assertStatus(422);
            
            // Property: Should have validation error for shares
            $response->assertJsonValidationErrors(['shares']);
            
            // Property: Error message should mention sum mismatch
            $errorMessage = $response->json('errors.shares.0');
            $this->assertStringContainsString('sum', strtolower($errorMessage),
                "Iteration $i: Error message should mention 'sum'. Got: $errorMessage");
            $this->assertStringContainsString('equal', strtolower($errorMessage),
                "Iteration $i: Error message should mention 'equal'. Got: $errorMessage");
        }
    }

    /**
     * @test
     * Edge case: Custom split with sum slightly higher than total
     */
    public function custom_split_sum_slightly_higher_than_total()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 1000.00, Sum: 1000.50 (0.50 higher)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Dinner',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 500.50],
                    ['user_id' => $member->id, 'amount' => 500.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Edge case: Custom split with sum slightly lower than total
     */
    public function custom_split_sum_slightly_lower_than_total()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 1000.00, Sum: 999.50 (0.50 lower)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Lunch',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 499.50],
                    ['user_id' => $member->id, 'amount' => 500.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Edge case: Custom split with sum significantly higher than total
     */
    public function custom_split_sum_much_higher_than_total()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 1000.00, Sum: 1500.00 (50% higher)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Party',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 750.00],
                    ['user_id' => $member->id, 'amount' => 750.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Edge case: Custom split with sum significantly lower than total
     */
    public function custom_split_sum_much_lower_than_total()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 1000.00, Sum: 500.00 (50% lower)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Coffee',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 250.00],
                    ['user_id' => $member->id, 'amount' => 250.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Edge case: Custom split with many members and sum mismatch
     */
    public function custom_split_many_members_sum_mismatch()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Add 9 more members (total 10)
        $shares = [['user_id' => $creator->id, 'amount' => 100.00]];
        for ($i = 0; $i < 9; $i++) {
            $member = User::factory()->create();
            $group->members()->attach($member->id, ['joined_at' => now()]);
            $shares[] = ['user_id' => $member->id, 'amount' => 100.00];
        }

        // Total: 1000.00, Sum: 1000.00 (10 * 100.00)
        // But we'll make one share 101.00 to create mismatch
        $shares[5]['amount'] = 101.00;

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Big Group Dinner',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => $shares,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Edge case: Custom split with very small amount mismatch
     */
    public function custom_split_very_small_mismatch()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 100.00, Sum: 100.02 (0.02 higher - just outside tolerance)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Small Mismatch',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 50.01],
                    ['user_id' => $member->id, 'amount' => 50.01],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * @test
     * Positive case: Custom split with exact sum should be accepted
     */
    public function custom_split_exact_sum_accepted()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 1000.00, Sum: 1000.00 (exact match)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Exact Match',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 600.00],
                    ['user_id' => $member->id, 'amount' => 400.00],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bills', [
            'title' => 'Exact Match',
            'total_amount' => 1000.00,
        ]);
    }

    /**
     * @test
     * Positive case: Custom split within rounding tolerance should be accepted
     */
    public function custom_split_within_tolerance_accepted()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Total: 100.00, Sum: 100.01 (0.01 difference - within tolerance)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Within Tolerance',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 50.00],
                    ['user_id' => $member->id, 'amount' => 50.01],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bills', [
            'title' => 'Within Tolerance',
            'total_amount' => 100.00,
        ]);
    }
}
