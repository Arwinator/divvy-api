<?php

namespace Tests\Feature\Groups;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Group Membership Display Completeness
 * 
 * This test validates that the GET /api/groups endpoint returns exactly
 * the groups a user belongs to - no more, no less.
 */
class GroupMembershipDisplayCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Group Membership Display Completeness
     * 
     * Test that all groups user belongs to are returned, no more, no less.
     */
    public function group_membership_display_completeness_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create a test user for this iteration
            $user = User::factory()->create();

            // Generate random number of groups (between 0 and 8)
            $numGroupsUserBelongsTo = rand(0, 8);
            
            // Generate random number of groups user does NOT belong to (between 0 and 8)
            $numGroupsUserDoesNotBelongTo = rand(0, 8);

            // Track groups the user should see
            $expectedGroupIds = [];

            // Create groups where user is a member
            for ($j = 0; $j < $numGroupsUserBelongsTo; $j++) {
                $group = Group::factory()->create(['creator_id' => $user->id]);
                $group->members()->attach($user->id, ['joined_at' => now()]);
                $expectedGroupIds[] = $group->id;
            }

            // Create groups where user is NOT a member (other users' groups)
            for ($k = 0; $k < $numGroupsUserDoesNotBelongTo; $k++) {
                $otherUser = User::factory()->create();
                $otherGroup = Group::factory()->create(['creator_id' => $otherUser->id]);
                $otherGroup->members()->attach($otherUser->id, ['joined_at' => now()]);
            }

            // Authenticate as the test user
            $token = $user->createToken('test-token')->plainTextToken;

            // Clear any cached authentication to ensure fresh auth check
            $this->app->forgetInstance('auth');

            // Call GET /api/groups
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/groups');

            $response->assertStatus(200);
            $response->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'creator_id', 'members', 'created_at']
                ]
            ]);

            // Extract returned group IDs
            $returnedGroups = $response->json('data');
            $returnedGroupIds = array_map(fn($group) => $group['id'], $returnedGroups);

            // Sort both arrays for comparison
            sort($expectedGroupIds);
            sort($returnedGroupIds);

            // Property: Returned groups should match exactly the groups user belongs to
            $this->assertEquals(
                $expectedGroupIds,
                $returnedGroupIds,
                "Iteration $i: User should see exactly the groups they belong to. " .
                "Expected: " . json_encode($expectedGroupIds) . ", " .
                "Got: " . json_encode($returnedGroupIds)
            );

            // Additional assertions
            $this->assertCount(
                $numGroupsUserBelongsTo,
                $returnedGroups,
                "Iteration $i: Should return exactly $numGroupsUserBelongsTo groups"
            );

            // Verify no groups from other users are included
            foreach ($returnedGroups as $group) {
                $this->assertContains(
                    $group['id'],
                    $expectedGroupIds,
                    "Iteration $i: Group {$group['id']} should be in user's groups"
                );
            }

            // Verify all expected groups are included
            foreach ($expectedGroupIds as $expectedId) {
                $this->assertContains(
                    $expectedId,
                    $returnedGroupIds,
                    "Iteration $i: Expected group $expectedId should be in response"
                );
            }
        }
    }

    /**
     * @test
     * Edge case: User with no groups should see empty list
     */
    public function user_with_no_groups_sees_empty_list()
    {
        // Create user with no group memberships
        $user = User::factory()->create();

        // Create some groups that user is NOT a member of
        for ($i = 0; $i < 5; $i++) {
            $otherUser = User::factory()->create();
            $group = Group::factory()->create(['creator_id' => $otherUser->id]);
            $group->members()->attach($otherUser->id, ['joined_at' => now()]);
        }

        // Authenticate as the test user
        $token = $user->createToken('test-token')->plainTextToken;

        // Call GET /api/groups
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/groups');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * @test
     * Edge case: User belongs to all groups in system
     */
    public function user_belongs_to_all_groups_in_system()
    {
        // Create user
        $user = User::factory()->create();

        // Create multiple groups and add user to all of them
        $expectedGroupIds = [];
        for ($i = 0; $i < 10; $i++) {
            $group = Group::factory()->create(['creator_id' => $user->id]);
            $group->members()->attach($user->id, ['joined_at' => now()]);
            $expectedGroupIds[] = $group->id;
        }

        // Authenticate as the test user
        $token = $user->createToken('test-token')->plainTextToken;

        // Call GET /api/groups
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/groups');

        $response->assertStatus(200);
        
        $returnedGroupIds = array_map(
            fn($group) => $group['id'],
            $response->json('data')
        );

        sort($expectedGroupIds);
        sort($returnedGroupIds);

        $this->assertEquals($expectedGroupIds, $returnedGroupIds);
        $this->assertCount(10, $response->json('data'));
    }
}
