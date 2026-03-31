<?php

namespace Tests\Feature\Groups;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Group Creator Authorization
 * 
 * This test validates that only the group creator can remove members,
 * and non-creators can leave groups (Requirements 3.5, 3.6).
 */
class GroupCreatorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Group Creator Authorization
     * 
     * Test that only creator can remove members, non-creators can leave.
     */
    public function group_creator_authorization_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create a group with a creator
            $creator = User::factory()->create();
            $group = Group::factory()->create(['creator_id' => $creator->id]);
            $group->members()->attach($creator->id, ['joined_at' => now()]);

            // Generate random number of additional members (between 4 and 8)
            $numMembers = rand(4, 8);
            $members = [];

            for ($j = 0; $j < $numMembers; $j++) {
                $member = User::factory()->create();
                $group->members()->attach($member->id, ['joined_at' => now()]);
                $members[] = $member;
            }

            // Pick specific members for each test
            $randomMember = $members[0];
            $targetMember = $members[1];
            $memberToRemove = $members[2];
            $leavingMember = $members[3];

            // Non-creator cannot remove members
            $response = $this->actingAs($randomMember, 'sanctum')
                ->deleteJson("/api/groups/{$group->id}/members/{$targetMember->id}");

            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'Only the group creator can remove members'
            ]);

            // Verify member was NOT removed
            $this->assertTrue(
                $group->members()->where('user_id', $targetMember->id)->exists(),
                "Iteration $i: Non-creator should not be able to remove members"
            );

            // Creator CAN remove members
            $response = $this->actingAs($creator, 'sanctum')
                ->deleteJson("/api/groups/{$group->id}/members/{$memberToRemove->id}");

            $response->assertStatus(200);
            $response->assertJson([
                'message' => 'Member removed successfully'
            ]);

            // Verify member WAS removed
            $this->assertFalse(
                $group->members()->where('user_id', $memberToRemove->id)->exists(),
                "Iteration $i: Creator should be able to remove members"
            );

            // Creator cannot remove themselves
            $response = $this->actingAs($creator, 'sanctum')
                ->deleteJson("/api/groups/{$group->id}/members/{$creator->id}");

            $response->assertStatus(422);
            $response->assertJson([
                'message' => 'Cannot remove the group creator'
            ]);

            // Verify creator is still a member
            $this->assertTrue(
                $group->members()->where('user_id', $creator->id)->exists(),
                "Iteration $i: Creator should not be able to remove themselves"
            );

            // Non-creator CAN leave group
            $response = $this->actingAs($leavingMember, 'sanctum')
                ->postJson("/api/groups/{$group->id}/leave");

            $response->assertStatus(200);
            $response->assertJson([
                'message' => 'You have left the group'
            ]);

            // Verify member left the group
            $this->assertFalse(
                $group->members()->where('user_id', $leavingMember->id)->exists(),
                "Iteration $i: Non-creator should be able to leave group"
            );

            // Creator CANNOT leave group
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson("/api/groups/{$group->id}/leave");

            $response->assertStatus(422);
            $response->assertJson([
                'message' => 'Group creator cannot leave the group'
            ]);

            // Verify creator is still a member
            $this->assertTrue(
                $group->members()->where('user_id', $creator->id)->exists(),
                "Iteration $i: Creator should not be able to leave group"
            );
        }
    }

    /**
     * @test
     * Edge case: Creator tries to remove non-existent member
     */
    public function creator_cannot_remove_non_member()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create a user who is NOT a member
        $nonMember = User::factory()->create();

        $creatorToken = $creator->createToken('test-token')->plainTextToken;

        // Try to remove non-member (should succeed silently as detach is idempotent)
        $response = $this->withHeader('Authorization', 'Bearer ' . $creatorToken)
            ->deleteJson("/api/groups/{$group->id}/members/{$nonMember->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Member removed successfully'
        ]);
    }

    /**
     * @test
     * Edge case: Non-member tries to leave group
     */
    public function non_member_cannot_leave_group()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create a user who is NOT a member
        $nonMember = User::factory()->create();
        $nonMemberToken = $nonMember->createToken('test-token')->plainTextToken;

        // Try to leave group (should fail with 403 because not a member)
        $response = $this->withHeader('Authorization', 'Bearer ' . $nonMemberToken)
            ->postJson("/api/groups/{$group->id}/leave");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Forbidden'
        ]);
    }

    /**
     * @test
     * Edge case: Unauthenticated user cannot remove members or leave
     */
    public function unauthenticated_user_cannot_perform_group_actions()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        $member = User::factory()->create();
        $group->members()->attach($member->id, ['joined_at' => now()]);

        // Try to remove member without authentication
        $response = $this->deleteJson("/api/groups/{$group->id}/members/{$member->id}");
        $response->assertStatus(401);

        // Try to leave group without authentication
        $response = $this->postJson("/api/groups/{$group->id}/leave");
        $response->assertStatus(401);
    }

    /**
     * @test
     * Edge case: Group with only creator
     */
    public function group_with_only_creator_cannot_be_left()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        $creatorToken = $creator->createToken('test-token')->plainTextToken;

        // Creator tries to leave (should fail)
        $response = $this->withHeader('Authorization', 'Bearer ' . $creatorToken)
            ->postJson("/api/groups/{$group->id}/leave");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Group creator cannot leave the group'
        ]);

        // Verify creator is still a member
        $this->assertTrue($group->members()->where('user_id', $creator->id)->exists());
    }

    /**
     * @test
     * Scenario: Multiple members, creator removes all except themselves
     */
    public function creator_can_remove_all_members_except_themselves()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Add 5 members
        $members = [];
        for ($i = 0; $i < 5; $i++) {
            $member = User::factory()->create();
            $group->members()->attach($member->id, ['joined_at' => now()]);
            $members[] = $member;
        }

        $creatorToken = $creator->createToken('test-token')->plainTextToken;

        // Remove all members one by one
        foreach ($members as $member) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $creatorToken)
                ->deleteJson("/api/groups/{$group->id}/members/{$member->id}");

            $response->assertStatus(200);
            $this->assertFalse($group->members()->where('user_id', $member->id)->exists());
        }

        // Verify only creator remains
        $this->assertCount(1, $group->members);
        $this->assertTrue($group->members()->where('user_id', $creator->id)->exists());

        // Creator still cannot leave
        $response = $this->withHeader('Authorization', 'Bearer ' . $creatorToken)
            ->postJson("/api/groups/{$group->id}/leave");

        $response->assertStatus(422);
    }

    /**
     * @test
     * Scenario: All non-creator members leave voluntarily
     */
    public function all_non_creator_members_can_leave_voluntarily()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Add 5 members
        $members = [];
        for ($i = 0; $i < 5; $i++) {
            $member = User::factory()->create();
            $group->members()->attach($member->id, ['joined_at' => now()]);
            $members[] = $member;
        }

        // Verify initial member count
        $this->assertCount(6, $group->members); // 5 members + 1 creator

        // All members leave voluntarily
        foreach ($members as $member) {
            // Refresh group to get updated member list
            $group->refresh();
            
            // Verify member is still in group before leaving
            $this->assertTrue(
                $group->members()->where('user_id', $member->id)->exists(),
                "Member {$member->id} should be in group before leaving"
            );

            $response = $this->actingAs($member, 'sanctum')
                ->postJson("/api/groups/{$group->id}/leave");

            $response->assertStatus(200);
            
            // Verify member left the group
            $group->refresh();
            $this->assertFalse(
                $group->members()->where('user_id', $member->id)->exists(),
                "Member {$member->id} should have left the group"
            );
        }

        // Verify only creator remains
        $group->refresh();
        $this->assertCount(1, $group->members);
        $this->assertTrue($group->members()->where('user_id', $creator->id)->exists());
    }
}
