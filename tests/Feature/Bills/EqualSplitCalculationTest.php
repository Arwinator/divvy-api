<?php

namespace Tests\Feature\Bills;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Equal Split Calculation
 * 
 * This test validates that when a bill is split equally among group members,
 * the sum of all shares equals the total bill amount (within rounding tolerance).
 */
class EqualSplitCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Equal Split Calculation
     * 
     * Test that sum of equal shares equals total amount (within rounding tolerance)
     * for random amounts and member counts.
     */
    public function equal_split_calculation_property()
    {
        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            // Generate random total amount between 1.00 and 10000.00
            $totalAmount = round(mt_rand(100, 1000000) / 100, 2);
            
            // Generate random number of members (between 2 and 20)
            $memberCount = mt_rand(2, 20);

            // Create a test user (bill creator) with unique username
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);

            // Create a group with the creator
            $group = Group::factory()->create(['creator_id' => $creator->id]);
            
            // Add creator as a member
            $group->members()->attach($creator->id, ['joined_at' => now()]);

            // Add additional members to reach the desired count
            for ($j = 1; $j < $memberCount; $j++) {
                $member = User::factory()->create([
                    'username' => 'member_' . $i . '_' . $j . '_' . uniqid(),
                    'email' => 'member_' . $i . '_' . $j . '_' . uniqid() . '@test.com',
                ]);
                $group->members()->attach($member->id, ['joined_at' => now()]);
            }

            // Create bill with equal split using actingAs
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => "Test Bill Iteration $i",
                    'total_amount' => $totalAmount,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);

            $response->assertStatus(201);

            // Get the created bill
            $bill = Bill::with('shares')->find($response->json('id'));

            // Number of shares should equal number of members
            $this->assertCount(
                $memberCount,
                $bill->shares,
                "Iteration $i: Should create exactly $memberCount shares for $memberCount members"
            );

            // Sum of all shares should equal total amount (within 0.01 tolerance)
            $sumOfShares = $bill->shares->sum('amount');
            $difference = abs($sumOfShares - $totalAmount);
            
            $this->assertLessThanOrEqual(
                0.01,
                $difference,
                "Iteration $i: Sum of shares ($sumOfShares) should equal total amount ($totalAmount). " .
                "Difference: $difference, Members: $memberCount"
            );

            // Each share should be positive
            foreach ($bill->shares as $share) {
                $this->assertGreaterThan(
                    0,
                    $share->amount,
                    "Iteration $i: Each share must be positive. Got: {$share->amount}"
                );
            }

            // All shares should be reasonable (not zero, not exceeding total)
            foreach ($bill->shares as $share) {
                $this->assertLessThanOrEqual(
                    $totalAmount,
                    $share->amount,
                    "Iteration $i: Share amount ({$share->amount}) should not exceed total ($totalAmount)"
                );
            }

            // All shares should have 'unpaid' status
            foreach ($bill->shares as $share) {
                $this->assertEquals(
                    'unpaid',
                    $share->status,
                    "Iteration $i: All shares should start with 'unpaid' status"
                );
            }
        }
    }

    /**
     * @test
     * Edge case: Equal split with 2 members
     */
    public function equal_split_with_two_members()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Test with amount that divides evenly
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Dinner',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $bill = Bill::with('shares')->find($response->json('id'));

        $this->assertCount(2, $bill->shares);
        $this->assertEquals(500.00, $bill->shares[0]->amount);
        $this->assertEquals(500.00, $bill->shares[1]->amount);
        $this->assertEquals(1000.00, $bill->shares->sum('amount'));
    }

    /**
     * @test
     * Edge case: Equal split with odd amount
     */
    public function equal_split_with_odd_amount()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([
            $creator->id,
            $member1->id,
            $member2->id
        ], ['joined_at' => now()]);

        // Test with amount that doesn't divide evenly (100.00 / 3 = 33.33...)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Lunch',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $bill = Bill::with('shares')->find($response->json('id'));

        $this->assertCount(3, $bill->shares);
        
        // Sum should equal exactly 100.00
        $sum = $bill->shares->sum('amount');
        $this->assertEquals(100.00, $sum);

        // Each share should be approximately 33.33
        foreach ($bill->shares as $share) {
            $this->assertGreaterThanOrEqual(33.33, $share->amount);
            $this->assertLessThanOrEqual(33.34, $share->amount);
        }
    }

    /**
     * @test
     * Edge case: Equal split with large number of members
     */
    public function equal_split_with_many_members()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Add 19 more members (total 20)
        for ($i = 0; $i < 19; $i++) {
            $member = User::factory()->create();
            $group->members()->attach($member->id, ['joined_at' => now()]);
        }

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Big Party',
                'total_amount' => 5000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $bill = Bill::with('shares')->find($response->json('id'));

        $this->assertCount(20, $bill->shares);
        
        // Sum should equal exactly 5000.00
        $sum = $bill->shares->sum('amount');
        $this->assertEquals(5000.00, $sum);

        // Each share should be 250.00
        foreach ($bill->shares as $share) {
            $this->assertEquals(250.00, $share->amount);
        }
    }

    /**
     * @test
     * Edge case: Equal split with very small amount
     */
    public function equal_split_with_small_amount()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Test with very small amount (0.03 / 2 = 0.015, rounds to 0.02 + 0.01)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Candy',
                'total_amount' => 0.03,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $bill = Bill::with('shares')->find($response->json('id'));

        $this->assertCount(2, $bill->shares);
        
        // Sum should equal exactly 0.03
        $sum = $bill->shares->sum('amount');
        $this->assertEquals(0.03, $sum);

        // Each share should be positive
        foreach ($bill->shares as $share) {
            $this->assertGreaterThan(0, $share->amount);
        }
    }

    /**
     * @test
     * Edge case: Equal split with maximum precision
     */
    public function equal_split_with_maximum_precision()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([
            $creator->id,
            $member1->id,
            $member2->id
        ], ['joined_at' => now()]);

        // Test with amount that requires maximum precision (0.01 / 3 = 0.00333...)
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Penny Split',
                'total_amount' => 0.01,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $bill = Bill::with('shares')->find($response->json('id'));

        $this->assertCount(3, $bill->shares);
        
        // Sum should equal exactly 0.01
        $sum = $bill->shares->sum('amount');
        $this->assertEquals(0.01, $sum);

        // At least one share should be positive (remainder handling)
        $positiveShares = $bill->shares->filter(fn($s) => $s->amount > 0)->count();
        $this->assertGreaterThan(0, $positiveShares);
    }
}
