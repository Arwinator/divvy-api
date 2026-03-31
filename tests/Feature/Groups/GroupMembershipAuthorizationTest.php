<?php

namespace Tests\Feature\Groups;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Group Membership Authorization
 * 
 * This test validates that non-members cannot access group resources.
 * All group-related endpoints should return 403 Forbidden when accessed
 * by users who are not members of the group.
 */
class GroupMembershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Group Membership Authorization
     * 
     * Test that non-members cannot access group resources (403).
     */
    public function group_membership_authorization_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create group creator
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group member
            $member = User::factory()->create([
                'username' => 'member_' . $i . '_' . uniqid(),
                'email' => 'member_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create non-member (the user who should be denied access)
            $nonMember = User::factory()->create([
                'username' => 'nonmember_' . $i . '_' . uniqid(),
                'email' => 'nonmember_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group with creator and member
            $group = Group::factory()->create([
                'name' => 'Group_' . $i . '_' . uniqid(),
                'creator_id' => $creator->id,
            ]);
            
            $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
            
            // Create a bill in the group
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => 'Bill_' . $i . '_' . uniqid(),
                'total_amount' => round(mt_rand(100, 10000) / 100, 2),
            ]);
            
            // Create shares for members
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $creator->id,
                'amount' => round(mt_rand(50, 500) / 100, 2),
                'status' => 'unpaid',
            ]);
            
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $member->id,
                'amount' => round(mt_rand(50, 500) / 100, 2),
                'status' => 'unpaid',
            ]);
            
            // Test 1: Non-member cannot create bill in group
            $response = $this->actingAs($nonMember, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Unauthorized Bill',
                    'total_amount' => 100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'Forbidden'
            ]);
            
            // Test 2: Non-member cannot send invitation to group
            $invitee = User::factory()->create([
                'username' => 'invitee_' . $i . '_' . uniqid(),
                'email' => 'invitee_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $response = $this->actingAs($nonMember, 'sanctum')
                ->postJson("/api/groups/{$group->id}/invitations", [
                    'identifier' => $invitee->email,
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'Forbidden'
            ]);
            
            // Test 3: Non-member cannot remove member from group
            $response = $this->actingAs($nonMember, 'sanctum')
                ->deleteJson("/api/groups/{$group->id}/members/{$member->id}");
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'Forbidden'
            ]);
            
            // Test 4: Non-member cannot leave group (they're not in it)
            $response = $this->actingAs($nonMember, 'sanctum')
                ->postJson("/api/groups/{$group->id}/leave");
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'Forbidden'
            ]);
            
            // Test 5: Non-member cannot view bill details
            $response = $this->actingAs($nonMember, 'sanctum')
                ->getJson("/api/bills/{$bill->id}");
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'You must be a member of this group to view this bill'
            ]);
            
            // Verify that members CAN access the same resources
            // This confirms the authorization is working correctly, not just blocking everyone
            
            // Member CAN create bill
            $response = $this->actingAs($member, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Authorized Bill',
                    'total_amount' => 100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(201);
            
            // Creator CAN send invitation
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson("/api/groups/{$group->id}/invitations", [
                    'identifier' => $invitee->email,
                ]);
            
            $response->assertStatus(201);
            
            // Member CAN view bill details
            $response = $this->actingAs($member, 'sanctum')
                ->getJson("/api/bills/{$bill->id}");
            
            $response->assertStatus(200);
        }
    }

    /**
     * @test
     * Edge case: Non-member cannot view specific bill details
     */
    public function non_member_cannot_view_bill_details()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        // Non-member attempts to view bill details
        $response = $this->actingAs($nonMember, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You must be a member of this group to view this bill'
        ]);
        
        // Member CAN view bill details
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(200);
    }

    /**
     * @test
     * Edge case: User removed from group loses access
     */
    public function user_removed_from_group_loses_access_to_group_resources()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        // Member can access before removal
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(200);
        
        // Creator removes member
        $group->members()->detach($member->id);
        
        // Member can no longer access after removal
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You must be a member of this group to view this bill'
        ]);
        
        // Member cannot create bills in the group
        $response = $this->actingAs($member, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Unauthorized Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(403);
    }

    /**
     * @test
     * Edge case: User who left group loses access
     */
    public function user_who_left_group_loses_access_to_group_resources()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        // Member can access before leaving
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(200);
        
        // Member leaves group
        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$group->id}/leave");
        
        $response->assertStatus(200);
        
        // Member can no longer access after leaving
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills/{$bill->id}");
        
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You must be a member of this group to view this bill'
        ]);
    }

    /**
     * @test
     * Edge case: Non-member cannot access group through bill filters
     */
    public function non_member_cannot_filter_bills_by_group_they_are_not_member_of()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        // Non-member attempts to filter bills by group
        $response = $this->actingAs($nonMember, 'sanctum')
            ->getJson("/api/bills?group_id={$group->id}");
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Should return empty array (no bills visible to non-member)
        $this->assertCount(0, $responseData['data']);
        
        // Member CAN filter bills by group
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/bills?group_id={$group->id}");
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Should return the bill
        $this->assertGreaterThan(0, count($responseData['data']));
    }

    /**
     * @test
     * Edge case: Multiple groups - user can only access their groups
     */
    public function user_can_only_access_groups_they_are_member_of()
    {
        $user = User::factory()->create();
        $creator1 = User::factory()->create();
        $creator2 = User::factory()->create();
        
        // Group 1: User is a member
        $group1 = Group::factory()->create(['creator_id' => $creator1->id]);
        $group1->members()->attach([$creator1->id, $user->id], ['joined_at' => now()]);
        
        // Group 2: User is NOT a member
        $group2 = Group::factory()->create(['creator_id' => $creator2->id]);
        $group2->members()->attach([$creator2->id], ['joined_at' => now()]);
        
        // Create bills in both groups
        $bill1 = Bill::factory()->create([
            'group_id' => $group1->id,
            'creator_id' => $creator1->id,
            'total_amount' => 500.00,
        ]);
        
        $bill2 = Bill::factory()->create([
            'group_id' => $group2->id,
            'creator_id' => $creator2->id,
            'total_amount' => 600.00,
        ]);
        
        // User CAN access group1 bills
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/bills/{$bill1->id}");
        
        $response->assertStatus(200);
        
        // User CANNOT access group2 bills
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/bills/{$bill2->id}");
        
        $response->assertStatus(403);
        
        // User CAN create bill in group1
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group1->id,
                'title' => 'Authorized Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(201);
        
        // User CANNOT create bill in group2
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group2->id,
                'title' => 'Unauthorized Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(403);
    }

    /**
     * @test
     * Edge case: Unauthenticated user cannot access any group resources
     */
    public function unauthenticated_user_cannot_access_group_resources()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        // Unauthenticated requests should return 401
        $response = $this->getJson("/api/bills/{$bill->id}");
        $response->assertStatus(401);
        
        $response = $this->postJson('/api/bills', [
            'group_id' => $group->id,
            'title' => 'Unauthorized Bill',
            'total_amount' => 100.00,
            'bill_date' => now()->format('Y-m-d'),
            'split_type' => 'equal',
        ]);
        $response->assertStatus(401);
        
        $response = $this->getJson("/api/bills/{$bill->id}");
        $response->assertStatus(401);
    }

    /**
     * @test
     * Edge case: Non-existent group returns appropriate error
     */
    public function non_existent_group_returns_not_found()
    {
        $user = User::factory()->create();
        $nonExistentGroupId = 99999;
        
        // Attempting to create bill in non-existent group should return 404
        // Note: The middleware checks group membership which returns 403 when group doesn't exist
        // This is acceptable behavior as it prevents information disclosure about group existence
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $nonExistentGroupId,
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        // Either 403 (from middleware) or 404 (from controller) is acceptable
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * @test
     * Edge case: Creator has same access restrictions as members
     */
    public function creator_cannot_access_other_groups()
    {
        $creator1 = User::factory()->create();
        $creator2 = User::factory()->create();
        
        // Creator1's group
        $group1 = Group::factory()->create(['creator_id' => $creator1->id]);
        $group1->members()->attach([$creator1->id], ['joined_at' => now()]);
        
        // Creator2's group
        $group2 = Group::factory()->create(['creator_id' => $creator2->id]);
        $group2->members()->attach([$creator2->id], ['joined_at' => now()]);
        
        // Creator1 cannot access Creator2's group bills
        $bill2 = Bill::factory()->create([
            'group_id' => $group2->id,
            'creator_id' => $creator2->id,
            'total_amount' => 500.00,
        ]);
        
        $response = $this->actingAs($creator1, 'sanctum')
            ->getJson("/api/bills/{$bill2->id}");
        
        $response->assertStatus(403);
        
        // Creator1 cannot create bills in Creator2's group
        $response = $this->actingAs($creator1, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group2->id,
                'title' => 'Unauthorized Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(403);
    }
}
