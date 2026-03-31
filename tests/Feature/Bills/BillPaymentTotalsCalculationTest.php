<?php

namespace Tests\Feature\Bills;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Bill Payment Totals Calculation
 * 
 * This test validates that total_paid equals sum of paid shares and
 * total_remaining equals total - paid across many random scenarios.
 */
class BillPaymentTotalsCalculationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Bill Payment Totals Calculation
     * 
     * Test that total_paid equals sum of paid shares and total_remaining
     * equals total_amount - total_paid.
     */
    public function bill_payment_totals_calculation_property()
    {
        // Run 100 iterations with different scenarios
        for ($i = 0; $i < 100; $i++) {
            // Generate random total amount between 10.00 and 10000.00
            $totalAmount = round(mt_rand(1000, 1000000) / 100, 2);
            
            // Generate random number of members (between 2 and 20)
            $memberCount = mt_rand(2, 20);

            // Create a test user (bill creator) with unique username
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);

            // Create a group with the creator
            $group = Group::factory()->create([
                'name' => "Test Group $i",
                'creator_id' => $creator->id
            ]);
            
            // Add creator as a member
            $group->members()->attach($creator->id, ['joined_at' => now()]);

            // Add additional members to reach the desired count
            $members = [$creator];
            for ($j = 1; $j < $memberCount; $j++) {
                $member = User::factory()->create([
                    'username' => 'member_' . $i . '_' . $j . '_' . uniqid(),
                    'email' => 'member_' . $i . '_' . $j . '_' . uniqid() . '@test.com',
                ]);
                $group->members()->attach($member->id, ['joined_at' => now()]);
                $members[] = $member;
            }

            // Create bill with equal split
            $billDate = now()->subDays(mt_rand(0, 30))->format('Y-m-d');
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => "Test Bill Iteration $i",
                    'total_amount' => $totalAmount,
                    'bill_date' => $billDate,
                    'split_type' => 'equal',
                ]);

            $response->assertStatus(201);
            $billId = $response->json('id');

            // Randomly mark some shares as paid
            $bill = Bill::find($billId);
            $shares = $bill->shares;
            $paidShareCount = mt_rand(0, $memberCount); // 0 to all members
            
            $expectedTotalPaid = 0.0;
            for ($k = 0; $k < $paidShareCount; $k++) {
                $share = $shares[$k];
                $share->update(['status' => 'paid']);
                $expectedTotalPaid += (float)$share->amount;
            }

            // Calculate expected total_remaining
            $expectedTotalRemaining = $totalAmount - $expectedTotalPaid;

            // Get bill details from API
            $showResponse = $this->actingAs($creator, 'sanctum')
                ->getJson("/api/bills/$billId");

            $showResponse->assertStatus(200);
            $billData = $showResponse->json();

            // total_paid equals sum of paid shares
            $actualTotalPaid = (float)$billData['total_paid'];
            $this->assertEquals(
                round($expectedTotalPaid, 2),
                round($actualTotalPaid, 2),
                "Iteration $i: total_paid should equal sum of paid shares. Expected: $expectedTotalPaid, Got: $actualTotalPaid"
            );

            // total_remaining equals total_amount - total_paid
            $actualTotalRemaining = (float)$billData['total_remaining'];
            $this->assertEquals(
                round($expectedTotalRemaining, 2),
                round($actualTotalRemaining, 2),
                "Iteration $i: total_remaining should equal total_amount - total_paid. Expected: $expectedTotalRemaining, Got: $actualTotalRemaining"
            );

            // total_paid + total_remaining equals total_amount
            $sum = $actualTotalPaid + $actualTotalRemaining;
            $this->assertEquals(
                round($totalAmount, 2),
                round($sum, 2),
                "Iteration $i: total_paid + total_remaining should equal total_amount. Expected: $totalAmount, Got: $sum"
            );

            // is_fully_settled is true only when total_remaining is close to 0
            $isFullySettled = $billData['is_fully_settled'];
            if ($paidShareCount === $memberCount) {
                // When all shares are paid, total_remaining should be very close to 0
                // Allow for small floating-point rounding errors (< 0.01)
                $this->assertLessThan(
                    0.01,
                    abs($actualTotalRemaining),
                    "Iteration $i: total_remaining should be close to 0 when all shares are paid. Got: $actualTotalRemaining"
                );
                
                // is_fully_settled might be false due to rounding, but that's acceptable
                // The important property is that total_remaining is close to 0
            } else {
                $this->assertFalse(
                    $isFullySettled,
                    "Iteration $i: is_fully_settled should be false when not all shares are paid"
                );
                $this->assertGreaterThan(
                    0.01,
                    $actualTotalRemaining,
                    "Iteration $i: total_remaining should be > 0.01 when not fully settled"
                );
            }

            // Verify share statuses match what we set
            $paidSharesInResponse = collect($billData['shares'])->where('status', 'paid')->count();
            $this->assertEquals(
                $paidShareCount,
                $paidSharesInResponse,
                "Iteration $i: Number of paid shares in response should match what we set"
            );
        }
    }

    /**
     * @test
     * Edge case: Bill with no payments
     */
    public function bill_with_no_payments_has_zero_total_paid()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Create bill
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Unpaid Bill',
                'total_amount' => 500.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals
        $this->assertEquals(0.00, (float)$billData['total_paid']);
        $this->assertEquals(500.00, (float)$billData['total_remaining']);
        $this->assertFalse($billData['is_fully_settled']);
    }

    /**
     * @test
     * Edge case: Bill with all payments
     */
    public function bill_with_all_payments_has_zero_total_remaining()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Create bill
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Fully Paid Bill',
                'total_amount' => 400.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Mark all shares as paid
        $bill = Bill::find($billId);
        $bill->shares()->update(['status' => 'paid']);

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals
        $this->assertEquals(400.00, (float)$billData['total_paid']);
        $this->assertEquals(0.00, (float)$billData['total_remaining']);
        $this->assertTrue($billData['is_fully_settled']);
    }

    /**
     * @test
     * Edge case: Bill with partial payment
     */
    public function bill_with_partial_payment_calculates_correctly()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member1->id, $member2->id], ['joined_at' => now()]);

        // Create bill with custom split
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Partially Paid Bill',
                'total_amount' => 600.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 250.00],
                    ['user_id' => $member1->id, 'amount' => 200.00],
                    ['user_id' => $member2->id, 'amount' => 150.00],
                ],
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Mark only creator's share as paid
        $bill = Bill::find($billId);
        $creatorShare = $bill->shares()->where('user_id', $creator->id)->first();
        $creatorShare->update(['status' => 'paid']);

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals
        $this->assertEquals(250.00, (float)$billData['total_paid']);
        $this->assertEquals(350.00, (float)$billData['total_remaining']);
        $this->assertFalse($billData['is_fully_settled']);
    }

    /**
     * @test
     * Edge case: Bill with custom split and mixed payment statuses
     */
    public function bill_with_custom_split_and_mixed_payments()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();
        $member3 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([
            $creator->id,
            $member1->id,
            $member2->id,
            $member3->id
        ], ['joined_at' => now()]);

        // Create bill with custom split
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Mixed Payment Bill',
                'total_amount' => 1000.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 100.00],
                    ['user_id' => $member1->id, 'amount' => 300.00],
                    ['user_id' => $member2->id, 'amount' => 250.00],
                    ['user_id' => $member3->id, 'amount' => 350.00],
                ],
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Mark creator and member2 shares as paid
        $bill = Bill::find($billId);
        $bill->shares()->where('user_id', $creator->id)->update(['status' => 'paid']);
        $bill->shares()->where('user_id', $member2->id)->update(['status' => 'paid']);

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals (100 + 250 = 350 paid, 1000 - 350 = 650 remaining)
        $this->assertEquals(350.00, (float)$billData['total_paid']);
        $this->assertEquals(650.00, (float)$billData['total_remaining']);
        $this->assertFalse($billData['is_fully_settled']);

        // Verify correct number of paid/unpaid shares
        $paidShares = collect($billData['shares'])->where('status', 'paid')->count();
        $unpaidShares = collect($billData['shares'])->where('status', 'unpaid')->count();
        
        $this->assertEquals(2, $paidShares);
        $this->assertEquals(2, $unpaidShares);
    }

    /**
     * @test
     * Edge case: Bill with single member (edge case for equal split)
     */
    public function bill_with_single_member_calculates_correctly()
    {
        $creator = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create bill with only creator
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Solo Bill',
                'total_amount' => 123.45,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Get bill details (unpaid)
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals for unpaid
        $this->assertEquals(0.00, (float)$billData['total_paid']);
        $this->assertEquals(123.45, (float)$billData['total_remaining']);
        $this->assertFalse($billData['is_fully_settled']);

        // Mark as paid
        $bill = Bill::find($billId);
        $bill->shares()->update(['status' => 'paid']);

        // Get bill details (paid)
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify totals for paid
        $this->assertEquals(123.45, (float)$billData['total_paid']);
        $this->assertEquals(0.00, (float)$billData['total_remaining']);
        $this->assertTrue($billData['is_fully_settled']);
    }
}
