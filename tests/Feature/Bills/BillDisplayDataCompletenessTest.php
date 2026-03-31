<?php

namespace Tests\Feature\Bills;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Bill Display Data Completeness
 * 
 * This test validates that all bill data (total, date, shares with member info)
 * is returned correctly in API responses.
 */
class BillDisplayDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Bill Display Data Completeness
     * 
     * Test that all bill data (total, date, shares with member info) is returned
     * for both GET /api/bills and GET /api/bills/{id} endpoints.
     */
    public function bill_display_data_completeness_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Generate random total amount between 10.00 and 5000.00
            $totalAmount = round(mt_rand(1000, 500000) / 100, 2);
            
            // Generate random number of members (between 2 and 10)
            $memberCount = mt_rand(2, 10);

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

            // Test GET /api/bills (list endpoint)
            $listResponse = $this->actingAs($creator, 'sanctum')
                ->getJson('/api/bills');

            $listResponse->assertStatus(200);
            $bills = $listResponse->json('data');
            
            // Find our bill in the list
            $billInList = collect($bills)->firstWhere('id', $billId);
            $this->assertNotNull($billInList, "Iteration $i: Bill should be in the list");

            // Bill has all required fields
            $this->assertArrayHasKey('id', $billInList, "Iteration $i: Bill should have 'id'");
            $this->assertArrayHasKey('group_id', $billInList, "Iteration $i: Bill should have 'group_id'");
            $this->assertArrayHasKey('creator_id', $billInList, "Iteration $i: Bill should have 'creator_id'");
            $this->assertArrayHasKey('title', $billInList, "Iteration $i: Bill should have 'title'");
            $this->assertArrayHasKey('total_amount', $billInList, "Iteration $i: Bill should have 'total_amount'");
            $this->assertArrayHasKey('bill_date', $billInList, "Iteration $i: Bill should have 'bill_date'");
            $this->assertArrayHasKey('shares', $billInList, "Iteration $i: Bill should have 'shares'");

            // Bill has computed attributes
            $this->assertArrayHasKey('total_paid', $billInList, "Iteration $i: Bill should have 'total_paid'");
            $this->assertArrayHasKey('total_remaining', $billInList, "Iteration $i: Bill should have 'total_remaining'");
            $this->assertArrayHasKey('is_fully_settled', $billInList, "Iteration $i: Bill should have 'is_fully_settled'");

            // Bill data matches what was created
            $this->assertEquals($group->id, $billInList['group_id'], "Iteration $i: group_id should match");
            $this->assertEquals($creator->id, $billInList['creator_id'], "Iteration $i: creator_id should match");
            $this->assertEquals("Test Bill Iteration $i", $billInList['title'], "Iteration $i: title should match");
            $this->assertEquals($totalAmount, (float)$billInList['total_amount'], "Iteration $i: total_amount should match");

            // Shares array has correct count
            $this->assertCount(
                $memberCount,
                $billInList['shares'],
                "Iteration $i: Should have exactly $memberCount shares"
            );

            // Each share has complete member information
            foreach ($billInList['shares'] as $shareIndex => $share) {
                $this->assertArrayHasKey('id', $share, "Iteration $i, Share $shareIndex: Should have 'id'");
                $this->assertArrayHasKey('bill_id', $share, "Iteration $i, Share $shareIndex: Should have 'bill_id'");
                $this->assertArrayHasKey('user_id', $share, "Iteration $i, Share $shareIndex: Should have 'user_id'");
                $this->assertArrayHasKey('amount', $share, "Iteration $i, Share $shareIndex: Should have 'amount'");
                $this->assertArrayHasKey('status', $share, "Iteration $i, Share $shareIndex: Should have 'status'");
                $this->assertArrayHasKey('user', $share, "Iteration $i, Share $shareIndex: Should have 'user' object");

                // User object within share has complete information
                $this->assertArrayHasKey('id', $share['user'], "Iteration $i, Share $shareIndex: User should have 'id'");
                $this->assertArrayHasKey('username', $share['user'], "Iteration $i, Share $shareIndex: User should have 'username'");
                $this->assertArrayHasKey('email', $share['user'], "Iteration $i, Share $shareIndex: User should have 'email'");

                // Share amount is positive
                $this->assertGreaterThan(0, $share['amount'], "Iteration $i, Share $shareIndex: Amount should be positive");

                // Share status is 'unpaid' for new bills
                $this->assertEquals('unpaid', $share['status'], "Iteration $i, Share $shareIndex: Status should be 'unpaid'");
            }

            // Computed attributes are correct for unpaid bill
            $this->assertEquals(0, (float)$billInList['total_paid'], "Iteration $i: total_paid should be 0 for new bill");
            $this->assertEquals($totalAmount, (float)$billInList['total_remaining'], "Iteration $i: total_remaining should equal total_amount");
            $this->assertFalse($billInList['is_fully_settled'], "Iteration $i: is_fully_settled should be false");

            // Test GET /api/bills/{id} (show endpoint)
            $showResponse = $this->actingAs($creator, 'sanctum')
                ->getJson("/api/bills/$billId");

            $showResponse->assertStatus(200);
            $billDetails = $showResponse->json();

            // Show endpoint returns same data as list endpoint
            $this->assertEquals($billInList['id'], $billDetails['id'], "Iteration $i: IDs should match");
            $this->assertEquals($billInList['title'], $billDetails['title'], "Iteration $i: Titles should match");
            $this->assertEquals($billInList['total_amount'], $billDetails['total_amount'], "Iteration $i: Amounts should match");
            $this->assertCount(count($billInList['shares']), $billDetails['shares'], "Iteration $i: Share counts should match");

            // Group and creator relationships are loaded
            $this->assertArrayHasKey('group', $billDetails, "Iteration $i: Bill should have 'group' relationship");
            $this->assertArrayHasKey('creator', $billDetails, "Iteration $i: Bill should have 'creator' relationship");

            // Group has required fields
            $this->assertArrayHasKey('id', $billDetails['group'], "Iteration $i: Group should have 'id'");
            $this->assertArrayHasKey('name', $billDetails['group'], "Iteration $i: Group should have 'name'");
            $this->assertEquals($group->name, $billDetails['group']['name'], "Iteration $i: Group name should match");

            // Creator has required fields
            $this->assertArrayHasKey('id', $billDetails['creator'], "Iteration $i: Creator should have 'id'");
            $this->assertArrayHasKey('username', $billDetails['creator'], "Iteration $i: Creator should have 'username'");
            $this->assertEquals($creator->username, $billDetails['creator']['username'], "Iteration $i: Creator username should match");
        }
    }

    /**
     * @test
     * Edge case: Bill with some shares paid
     */
    public function bill_display_with_partial_payment()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member1->id, $member2->id], ['joined_at' => now()]);

        // Create bill
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Dinner',
                'total_amount' => 300.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Mark one share as paid
        $bill = Bill::find($billId);
        $firstShare = $bill->shares()->first();
        $firstShare->update(['status' => 'paid']);

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify computed attributes reflect partial payment
        $this->assertEquals(100.00, (float)$billData['total_paid']);
        $this->assertEquals(200.00, (float)$billData['total_remaining']);
        $this->assertFalse($billData['is_fully_settled']);

        // Verify shares have correct status
        $paidShares = collect($billData['shares'])->where('status', 'paid')->count();
        $unpaidShares = collect($billData['shares'])->where('status', 'unpaid')->count();
        
        $this->assertEquals(1, $paidShares);
        $this->assertEquals(2, $unpaidShares);
    }

    /**
     * @test
     * Edge case: Bill fully settled
     */
    public function bill_display_when_fully_settled()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

        // Create bill
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Lunch',
                'total_amount' => 200.00,
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

        // Verify computed attributes reflect full payment
        $this->assertEquals(200.00, (float)$billData['total_paid']);
        $this->assertEquals(0.00, (float)$billData['total_remaining']);
        $this->assertTrue($billData['is_fully_settled']);

        // Verify all shares are paid
        $paidShares = collect($billData['shares'])->where('status', 'paid')->count();
        $this->assertEquals(2, $paidShares);
    }

    /**
     * @test
     * Edge case: Bill with custom split
     */
    public function bill_display_with_custom_split()
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
                'title' => 'Grocery',
                'total_amount' => 500.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $creator->id, 'amount' => 200.00],
                    ['user_id' => $member1->id, 'amount' => 150.00],
                    ['user_id' => $member2->id, 'amount' => 150.00],
                ],
            ]);

        $response->assertStatus(201);
        $billId = $response->json('id');

        // Get bill details
        $showResponse = $this->actingAs($creator, 'sanctum')
            ->getJson("/api/bills/$billId");

        $showResponse->assertStatus(200);
        $billData = $showResponse->json();

        // Verify all data is present
        $this->assertCount(3, $billData['shares']);
        $this->assertEquals(500.00, (float)$billData['total_amount']);
        
        // Verify custom amounts are preserved
        $creatorShare = collect($billData['shares'])->firstWhere('user_id', $creator->id);
        $this->assertEquals(200.00, (float)$creatorShare['amount']);
        
        $member1Share = collect($billData['shares'])->firstWhere('user_id', $member1->id);
        $this->assertEquals(150.00, (float)$member1Share['amount']);
    }

    /**
     * @test
     * Edge case: Multiple bills in list
     */
    public function bill_list_displays_multiple_bills_correctly()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create 5 bills
        $billIds = [];
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => "Bill $i",
                    'total_amount' => ($i + 1) * 100.00,
                    'bill_date' => now()->subDays($i)->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);

            $response->assertStatus(201);
            $billIds[] = $response->json('id');
        }

        // Get all bills
        $listResponse = $this->actingAs($creator, 'sanctum')
            ->getJson('/api/bills');

        $listResponse->assertStatus(200);
        $bills = $listResponse->json('data');

        // Verify all bills are in the list
        $this->assertGreaterThanOrEqual(5, count($bills));

        // Verify each bill has complete data
        foreach ($billIds as $billId) {
            $bill = collect($bills)->firstWhere('id', $billId);
            $this->assertNotNull($bill);
            $this->assertArrayHasKey('shares', $bill);
            $this->assertArrayHasKey('total_paid', $bill);
            $this->assertArrayHasKey('total_remaining', $bill);
            $this->assertArrayHasKey('is_fully_settled', $bill);
        }
    }
}
