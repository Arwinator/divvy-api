<?php

namespace Tests\Feature\Groups;

use App\Models\User;
use App\Models\Group;
use App\Models\GroupInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests: Group Management Edge Cases
 * 
 * Tests edge cases in group management that weren't covered by property-based tests.
 */
class GroupManagementEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Edge Case: Creating group with empty name
     */
    public function cannot_create_group_with_empty_name()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/groups', [
                'name' => ''
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $response->assertJson([
            'message' => 'Group name is required',
            'errors' => [
                'name' => ['Group name is required']
            ]
        ]);
    }

    /**
     * @test
     * Edge Case: Creating group with missing name field
     */
    public function cannot_create_group_without_name_field()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/groups', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $response->assertJson([
            'message' => 'Group name is required',
            'errors' => [
                'name' => ['Group name is required']
            ]
        ]);
    }

    /**
     * @test
     * Edge Case: Inviting existing member
     */
    public function cannot_invite_existing_member()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        // Create group and add member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);
        $group->members()->attach($member->id, ['joined_at' => now()]);

        // Try to invite existing member
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $member->email
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'User is already a member of this group'
        ]);

        // Verify no invitation was created
        $this->assertDatabaseMissing('group_invitations', [
            'group_id' => $group->id,
            'invitee_id' => $member->id,
            'status' => 'pending'
        ]);
    }

    /**
     * @test
     * Edge Case: Inviting existing member by username
     */
    public function cannot_invite_existing_member_by_username()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        // Create group and add member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);
        $group->members()->attach($member->id, ['joined_at' => now()]);

        // Try to invite existing member by username
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $member->username
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'User is already a member of this group'
        ]);
    }

    /**
     * @test
     * Edge Case: Accepting already-accepted invitation
     */
    public function cannot_accept_already_accepted_invitation()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();

        // Create group
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create and accept invitation
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending'
        ]);

        // Accept invitation first time
        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/accept");

        $response->assertStatus(200);

        // Try to accept again
        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/accept");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'This invitation has already been processed'
        ]);

        // Verify user is only added once
        $this->assertEquals(1, $group->members()->where('user_id', $invitee->id)->count());
    }

    /**
     * @test
     * Edge Case: Accepting already-declined invitation
     */
    public function cannot_accept_already_declined_invitation()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();

        // Create group
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create and decline invitation
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending'
        ]);

        // Decline invitation
        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/decline");

        $response->assertStatus(200);

        // Try to accept declined invitation
        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/accept");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'This invitation has already been processed'
        ]);

        // Verify user was not added to group
        $this->assertFalse($group->members()->where('user_id', $invitee->id)->exists());
    }

    /**
     * @test
     * Edge Case: Removing group creator
     */
    public function cannot_remove_group_creator()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();

        // Create group with creator and member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);
        $group->members()->attach($member->id, ['joined_at' => now()]);

        // Try to remove creator (as creator themselves)
        $response = $this->actingAs($creator, 'sanctum')
            ->deleteJson("/api/groups/{$group->id}/members/{$creator->id}");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Cannot remove the group creator'
        ]);

        // Verify creator is still a member
        $this->assertTrue($group->members()->where('user_id', $creator->id)->exists());
    }

    /**
     * @test
     * Edge Case: Non-member trying to leave group
     */
    public function non_member_cannot_leave_group()
    {
        $creator = User::factory()->create();
        $nonMember = User::factory()->create();

        // Create group with only creator
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Try to leave group as non-member
        $response = $this->actingAs($nonMember, 'sanctum')
            ->postJson("/api/groups/{$group->id}/leave");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Forbidden'
        ]);

        // Verify group membership unchanged
        $this->assertCount(1, $group->members);
        $this->assertTrue($group->members()->where('user_id', $creator->id)->exists());
    }

    /**
     * @test
     * Edge Case: Creating group with whitespace-only name
     */
    public function cannot_create_group_with_whitespace_only_name()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/groups', [
                'name' => '   '
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * @test
     * Edge Case: Creating group with name exceeding max length
     */
    public function cannot_create_group_with_name_exceeding_max_length()
    {
        $user = User::factory()->create();

        // Create a name longer than 255 characters
        $longName = str_repeat('a', 256);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/groups', [
                'name' => $longName
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $response->assertJson([
            'errors' => [
                'name' => ['Group name cannot exceed 255 characters']
            ]
        ]);
    }

    /**
     * @test
     * Edge Case: Inviting user with pending invitation
     */
    public function cannot_send_duplicate_pending_invitation()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();

        // Create group
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Send first invitation
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $invitee->email
            ]);

        $response->assertStatus(201);

        // Try to send duplicate invitation
        $response = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $invitee->email
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'A pending invitation already exists for this user'
        ]);

        // Verify only one invitation exists
        $this->assertEquals(1, GroupInvitation::where('group_id', $group->id)
            ->where('invitee_id', $invitee->id)
            ->where('status', 'pending')
            ->count());
    }

    /**
     * @test
     * Edge Case: Non-creator trying to send invitation
     */
    public function non_creator_cannot_send_invitation()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $invitee = User::factory()->create();

        // Create group with creator and member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);
        $group->members()->attach($member->id, ['joined_at' => now()]);

        // Try to send invitation as non-creator member
        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $invitee->email
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Only the group creator can send invitations'
        ]);

        // Verify no invitation was created
        $this->assertDatabaseMissing('group_invitations', [
            'group_id' => $group->id,
            'invitee_id' => $invitee->id
        ]);
    }

    /**
     * @test
     * Edge Case: Accepting invitation for different user
     */
    public function cannot_accept_invitation_for_different_user()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $otherUser = User::factory()->create();

        // Create group
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create invitation for invitee
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending'
        ]);

        // Try to accept as different user
        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/accept");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'This invitation does not belong to you'
        ]);

        // Verify invitation still pending and user not added
        $this->assertEquals('pending', $invitation->fresh()->status);
        $this->assertFalse($group->members()->where('user_id', $otherUser->id)->exists());
    }

    /**
     * @test
     * Edge Case: Declining invitation for different user
     */
    public function cannot_decline_invitation_for_different_user()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $otherUser = User::factory()->create();

        // Create group
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        // Create invitation for invitee
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending'
        ]);

        // Try to decline as different user
        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/decline");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'This invitation does not belong to you'
        ]);

        // Verify invitation still pending
        $this->assertEquals('pending', $invitation->fresh()->status);
    }
}
