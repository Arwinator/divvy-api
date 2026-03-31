<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Server Wins Conflict Resolution
 * 
 * This test validates that when synchronization conflicts occur,
 * the server-side data is always kept as the source of truth.
 * Local conflicting data is discarded and server creates new resources.
 */
class ServerWinsConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Server Wins Conflict Resolution
     * 
     * Test that server data is kept when conflicts occur.
     * When the same local_id is synced multiple times with different data,
     * the server creates separate resources each time (doesn't update existing).
     */
    public function server_wins_conflict_resolution_property()
    {
        // Run 50 iterations with different conflict scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create a test user with unique credentials
            $user = User::factory()->create([
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => 'user_' . $i . '_' . uniqid() . '@test.com',
            ]);

            // Scenario 1: Same local_id synced multiple times for groups
            $localGroupId = 'local_group_' . $i . '_' . uniqid();
            
            // First sync: Create group with name "Group A"
            $response1 = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sync', [
                    'operations' => [
                        [
                            'type' => 'create_group',
                            'data' => [
                                'local_id' => $localGroupId,
                                'name' => 'Group A - Iteration ' . $i,
                            ],
                        ],
                    ],
                ]);

            $response1->assertStatus(200);
            $result1 = $response1->json('results.0');
            $this->assertEquals('success', $result1['status']);
            $serverId1 = $result1['data']['server_id'];

            // Second sync: Same local_id but different name "Group B"
            // Server should create a NEW group, not update the existing one
            $response2 = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sync', [
                    'operations' => [
                        [
                            'type' => 'create_group',
                            'data' => [
                                'local_id' => $localGroupId, // Same local_id
                                'name' => 'Group B - Iteration ' . $i, // Different name
                            ],
                        ],
                    ],
                ]);

            $response2->assertStatus(200);
            $result2 = $response2->json('results.0');
            $this->assertEquals('success', $result2['status']);
            $serverId2 = $result2['data']['server_id'];

            // Server creates separate resources (different server IDs)
            $this->assertNotEquals(
                $serverId1,
                $serverId2,
                "Iteration $i: Server should create separate groups for same local_id, not update existing"
            );

            // Both groups exist on server with their original data
            $group1 = Group::find($serverId1);
            $group2 = Group::find($serverId2);

            $this->assertNotNull($group1, "Iteration $i: First group should still exist");
            $this->assertNotNull($group2, "Iteration $i: Second group should exist as new resource");

            $this->assertStringContainsString(
                'Group A',
                $group1->name,
                "Iteration $i: First group should retain original name"
            );
            $this->assertStringContainsString(
                'Group B',
                $group2->name,
                "Iteration $i: Second group should have new name"
            );

            // Scenario 2: Same local_id synced multiple times for bills
            $localBillId = 'local_bill_' . $i . '_' . uniqid();
            $group = Group::factory()->create(['creator_id' => $user->id]);
            $group->members()->attach($user->id, ['joined_at' => now()]);

            // Generate random amounts for conflict testing
            $amount1 = round(mt_rand(100, 10000) / 100, 2);
            $amount2 = round(mt_rand(100, 10000) / 100, 2);

            // First sync: Create bill with amount1
            $response3 = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sync', [
                    'operations' => [
                        [
                            'type' => 'create_bill',
                            'data' => [
                                'local_id' => $localBillId,
                                'group_id' => $group->id,
                                'title' => 'Bill A - Iteration ' . $i,
                                'total_amount' => $amount1,
                                'bill_date' => now()->format('Y-m-d'),
                                'shares' => [
                                    [
                                        'user_id' => $user->id,
                                        'amount' => $amount1,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            $response3->assertStatus(200);
            $result3 = $response3->json('results.0');
            $this->assertEquals('success', $result3['status']);
            $billServerId1 = $result3['data']['server_id'];

            // Second sync: Same local_id but different amount
            $response4 = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sync', [
                    'operations' => [
                        [
                            'type' => 'create_bill',
                            'data' => [
                                'local_id' => $localBillId, // Same local_id
                                'group_id' => $group->id,
                                'title' => 'Bill B - Iteration ' . $i, // Different title
                                'total_amount' => $amount2, // Different amount
                                'bill_date' => now()->format('Y-m-d'),
                                'shares' => [
                                    [
                                        'user_id' => $user->id,
                                        'amount' => $amount2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            $response4->assertStatus(200);
            $result4 = $response4->json('results.0');
            $this->assertEquals('success', $result4['status']);
            $billServerId2 = $result4['data']['server_id'];

            // Server creates separate bills (different server IDs)
            $this->assertNotEquals(
                $billServerId1,
                $billServerId2,
                "Iteration $i: Server should create separate bills for same local_id, not update existing"
            );

            // Both bills exist with their original data
            $bill1 = Bill::find($billServerId1);
            $bill2 = Bill::find($billServerId2);

            $this->assertNotNull($bill1, "Iteration $i: First bill should still exist");
            $this->assertNotNull($bill2, "Iteration $i: Second bill should exist as new resource");

            $this->assertStringContainsString(
                'Bill A',
                $bill1->title,
                "Iteration $i: First bill should retain original title"
            );
            $this->assertStringContainsString(
                'Bill B',
                $bill2->title,
                "Iteration $i: Second bill should have new title"
            );

            $this->assertEquals(
                $amount1,
                $bill1->total_amount,
                "Iteration $i: First bill should retain original amount"
            );
            $this->assertEquals(
                $amount2,
                $bill2->total_amount,
                "Iteration $i: Second bill should have new amount"
            );
        }
    }

    /**
     * @test
     * Edge case: Multiple operations with same local_id in single sync request
     */
    public function multiple_operations_with_same_local_id_in_single_sync()
    {
        $user = User::factory()->create();
        $localGroupId = 'local_group_duplicate';

        // Send multiple operations with same local_id in one sync request
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => $localGroupId,
                            'name' => 'First Group',
                        ],
                    ],
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => $localGroupId, // Same local_id
                            'name' => 'Second Group',
                        ],
                    ],
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => $localGroupId, // Same local_id again
                            'name' => 'Third Group',
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        // All operations should succeed
        $this->assertCount(3, $results);
        $this->assertEquals('success', $results[0]['status']);
        $this->assertEquals('success', $results[1]['status']);
        $this->assertEquals('success', $results[2]['status']);

        // All should have different server IDs
        $serverId1 = $results[0]['data']['server_id'];
        $serverId2 = $results[1]['data']['server_id'];
        $serverId3 = $results[2]['data']['server_id'];

        $this->assertNotEquals($serverId1, $serverId2);
        $this->assertNotEquals($serverId2, $serverId3);
        $this->assertNotEquals($serverId1, $serverId3);

        // All groups should exist with their original names
        $group1 = Group::find($serverId1);
        $group2 = Group::find($serverId2);
        $group3 = Group::find($serverId3);

        $this->assertEquals('First Group', $group1->name);
        $this->assertEquals('Second Group', $group2->name);
        $this->assertEquals('Third Group', $group3->name);
    }

    /**
     * @test
     * Edge case: Conflict with different operation types
     */
    public function conflict_with_mixed_operation_types()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $localId = 'local_resource_123';

        // First sync: Create group with this local_id
        $response1 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => $localId,
                            'name' => 'Test Group',
                        ],
                    ],
                ],
            ]);

        $response1->assertStatus(200);
        $groupServerId = $response1->json('results.0.data.server_id');

        // Second sync: Create bill with same local_id (different operation type)
        $response2 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => $localId, // Same local_id
                            'group_id' => $group->id,
                            'title' => 'Test Bill',
                            'total_amount' => 100.00,
                            'bill_date' => now()->format('Y-m-d'),
                            'shares' => [
                                [
                                    'user_id' => $user->id,
                                    'amount' => 100.00,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $response2->assertStatus(200);
        $billServerId = $response2->json('results.0.data.server_id');

        // Both resources should exist independently
        $this->assertNotNull(Group::find($groupServerId));
        $this->assertNotNull(Bill::find($billServerId));

        // Server IDs should be different (different resource types)
        $this->assertNotEquals($groupServerId, $billServerId);
    }

    /**
     * @test
     * Edge case: Rapid successive syncs with same local_id
     */
    public function rapid_successive_syncs_with_same_local_id()
    {
        $user = User::factory()->create();
        $localGroupId = 'local_group_rapid';
        $serverIds = [];

        // Simulate rapid successive syncs (5 times)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/sync', [
                    'operations' => [
                        [
                            'type' => 'create_group',
                            'data' => [
                                'local_id' => $localGroupId,
                                'name' => 'Group Version ' . ($i + 1),
                            ],
                        ],
                    ],
                ]);

            $response->assertStatus(200);
            $result = $response->json('results.0');
            $this->assertEquals('success', $result['status']);
            $serverIds[] = $result['data']['server_id'];
        }

        // All server IDs should be unique
        $uniqueIds = array_unique($serverIds);
        $this->assertCount(5, $uniqueIds, 'All syncs should create separate resources');

        // All groups should exist
        foreach ($serverIds as $index => $serverId) {
            $group = Group::find($serverId);
            $this->assertNotNull($group, "Group $index should exist");
            $this->assertEquals('Group Version ' . ($index + 1), $group->name);
        }
    }

    /**
     * @test
     * Edge case: Server wins even when local data seems more recent
     */
    public function server_wins_regardless_of_timestamps()
    {
        $user = User::factory()->create();
        $localBillId = 'local_bill_timestamp';
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // First sync: Create bill with "old" date
        $oldDate = now()->subDays(7)->format('Y-m-d');
        $response1 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => $localBillId,
                            'group_id' => $group->id,
                            'title' => 'Old Bill',
                            'total_amount' => 100.00,
                            'bill_date' => $oldDate,
                            'shares' => [
                                ['user_id' => $user->id, 'amount' => 100.00],
                            ],
                        ],
                    ],
                ],
            ]);

        $response1->assertStatus(200);
        $oldBillId = $response1->json('results.0.data.server_id');

        // Second sync: Same local_id but with "newer" date
        $newDate = now()->format('Y-m-d');
        $response2 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => $localBillId, // Same local_id
                            'group_id' => $group->id,
                            'title' => 'New Bill',
                            'total_amount' => 200.00,
                            'bill_date' => $newDate, // More recent date
                            'shares' => [
                                ['user_id' => $user->id, 'amount' => 200.00],
                            ],
                        ],
                    ],
                ],
            ]);

        $response2->assertStatus(200);
        $newBillId = $response2->json('results.0.data.server_id');

        // Server creates new resource, doesn't update old one
        $this->assertNotEquals($oldBillId, $newBillId);

        // Old bill still exists with original data
        $oldBill = Bill::find($oldBillId);
        $this->assertNotNull($oldBill);
        $this->assertEquals('Old Bill', $oldBill->title);
        $this->assertEquals(100.00, $oldBill->total_amount);
        $this->assertEquals($oldDate, $oldBill->bill_date->format('Y-m-d'));

        // New bill exists as separate resource
        $newBill = Bill::find($newBillId);
        $this->assertNotNull($newBill);
        $this->assertEquals('New Bill', $newBill->title);
        $this->assertEquals(200.00, $newBill->total_amount);
        $this->assertEquals($newDate, $newBill->bill_date->format('Y-m-d'));
    }
}

