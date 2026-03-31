<?php

namespace Tests\Feature\Bills;

use App\Models\Bill;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillManagementEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test creating bill with zero amount.
     */
    public function test_creating_bill_with_zero_amount_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Zero Amount Bill',
            'total_amount' => 0,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'equal',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_amount']);
        $response->assertJson([
            'errors' => [
                'total_amount' => ['Total amount must be greater than zero']
            ]
        ]);
    }

    /**
     * Test creating bill with negative amount.
     */
    public function test_creating_bill_with_negative_amount_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Negative Amount Bill',
            'total_amount' => -100.50,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'equal',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_amount']);
    }

    /**
     * Test creating bill in non-existent group.
     */
    public function test_creating_bill_in_non_existent_group_fails()
    {
        $creator = User::factory()->create();

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Bill in Non-existent Group',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => 99999, // Non-existent group ID
            'split_type' => 'equal',
        ]);

        // Laravel returns 403 when findOrFail fails with authorization middleware
        $response->assertStatus(403);
    }

    /**
     * Test creating bill in group where user is not a member.
     */
    public function test_creating_bill_in_group_where_user_is_not_member_fails()
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $otherUser->id]);
        $group->members()->attach($otherUser->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Bill in Other User Group',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'equal',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Forbidden'
        ]);
    }

    /**
     * Test custom split with negative amounts.
     */
    public function test_custom_split_with_negative_amounts_fails()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Bill with Negative Share',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            'shares' => [
                ['user_id' => $creator->id, 'amount' => 1200.00],
                ['user_id' => $member->id, 'amount' => -200.00], // Negative amount
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.1.amount']);
    }

    /**
     * Test custom split with zero amount.
     */
    public function test_custom_split_with_zero_amount_fails()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Bill with Zero Share',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            'shares' => [
                ['user_id' => $creator->id, 'amount' => 1000.00],
                ['user_id' => $member->id, 'amount' => 0], // Zero amount
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.1.amount']);
    }

    /**
     * Test custom split where sum does not equal total.
     */
    public function test_custom_split_sum_mismatch_fails()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Bill with Mismatched Sum',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            'shares' => [
                ['user_id' => $creator->id, 'amount' => 400.00],
                ['user_id' => $member->id, 'amount' => 500.00], // Sum = 900, not 1000
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
        $response->assertJsonFragment([
            'shares' => ['The sum of shares (900) must equal the total amount (1000)']
        ]);
    }

    /**
     * Test filtering bills by date range.
     */
    public function test_filtering_bills_by_date_range()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        // Create bills with different dates
        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-01-15',
        ]);

        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-02-15',
        ]);

        $bill3 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-03-15',
        ]);

        // Filter bills from Feb 1 to Feb 28
        $response = $this->actingAs($creator)->getJson('/api/bills?from_date=2024-02-01&to_date=2024-02-28');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $bill2->id]);
        
        // Verify bill1 and bill3 are not in the response
        $billIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bill1->id, $billIds);
        $this->assertNotContains($bill3->id, $billIds);
    }

    /**
     * Test filtering bills by from_date only.
     */
    public function test_filtering_bills_by_from_date_only()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-01-15',
        ]);

        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-02-15',
        ]);

        // Filter bills from Feb 1 onwards
        $response = $this->actingAs($creator)->getJson('/api/bills?from_date=2024-02-01');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $bill2->id]);
        
        // Verify bill1 is not in the response
        $billIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bill1->id, $billIds);
    }

    /**
     * Test filtering bills by to_date only.
     */
    public function test_filtering_bills_by_to_date_only()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $bill1 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-01-15',
        ]);

        $bill2 = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'bill_date' => '2024-02-15',
        ]);

        // Filter bills up to Jan 31
        $response = $this->actingAs($creator)->getJson('/api/bills?to_date=2024-01-31');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $bill1->id]);
        
        // Verify bill2 is not in the response
        $billIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bill2->id, $billIds);
    }

    /**
     * Test bill with single member (creator only).
     */
    public function test_bill_with_single_member_equal_split()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id); // Only creator in group

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Solo Bill',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'equal',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('shares.0.user_id', $creator->id);
        $response->assertJsonPath('shares.0.amount', '1000.00');
        $response->assertJsonCount(1, 'shares');
    }

    /**
     * Test bill with single member custom split.
     */
    public function test_bill_with_single_member_custom_split()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Solo Custom Bill',
            'total_amount' => 500.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            'shares' => [
                ['user_id' => $creator->id, 'amount' => 500.00],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('shares.0.user_id', $creator->id);
        $response->assertJsonPath('shares.0.amount', '500.00');
        $response->assertJsonCount(1, 'shares');
    }

    /**
     * Test filtering bills by group_id.
     */
    public function test_filtering_bills_by_group_id()
    {
        $creator = User::factory()->create();
        
        $group1 = Group::factory()->create(['creator_id' => $creator->id]);
        $group1->members()->attach($creator->id);
        
        $group2 = Group::factory()->create(['creator_id' => $creator->id]);
        $group2->members()->attach($creator->id);

        $bill1 = Bill::factory()->create([
            'group_id' => $group1->id,
            'creator_id' => $creator->id,
        ]);

        $bill2 = Bill::factory()->create([
            'group_id' => $group2->id,
            'creator_id' => $creator->id,
        ]);

        // Filter bills by group1
        $response = $this->actingAs($creator)->getJson("/api/bills?group_id={$group1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $bill1->id]);
        
        // Verify bill2 is not in the response
        $billIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($bill2->id, $billIds);
    }

    /**
     * Test creating bill with missing required fields.
     */
    public function test_creating_bill_with_missing_required_fields_fails()
    {
        $creator = User::factory()->create();

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            // Missing all required fields
        ]);

        // Laravel returns 400 for completely empty request body
        $response->assertStatus(400);
    }

    /**
     * Test creating bill with invalid date format.
     */
    public function test_creating_bill_with_invalid_date_format_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Invalid Date Bill',
            'total_amount' => 1000.00,
            'bill_date' => 'not-a-date',
            'group_id' => $group->id,
            'split_type' => 'equal',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['bill_date']);
    }

    /**
     * Test creating bill with invalid split_type.
     */
    public function test_creating_bill_with_invalid_split_type_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Invalid Split Type Bill',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'percentage', // Invalid split type
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['split_type']);
        $response->assertJson([
            'errors' => [
                'split_type' => ['Split type must be either equal or custom']
            ]
        ]);
    }

    /**
     * Test custom split without shares array.
     */
    public function test_custom_split_without_shares_array_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Custom Split No Shares',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            // Missing shares array
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares']);
    }

    /**
     * Test custom split with non-existent user.
     */
    public function test_custom_split_with_non_existent_user_fails()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id);

        $response = $this->actingAs($creator)->postJson('/api/bills', [
            'title' => 'Custom Split Non-existent User',
            'total_amount' => 1000.00,
            'bill_date' => now()->format('Y-m-d'),
            'group_id' => $group->id,
            'split_type' => 'custom',
            'shares' => [
                ['user_id' => 99999, 'amount' => 1000.00], // Non-existent user
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.0.user_id']);
    }
}
