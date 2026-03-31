<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests: Sync Edge Cases
 * 
 * This test suite validates edge cases and error handling in the sync endpoint.
 * Tests cover empty operations, invalid operation types, and failed operations.
 */
class SyncEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Test sync with empty operations array
     * 
     * When no operations are provided, the sync endpoint should return
     * a success response with an empty results array and appropriate message.
     */
    public function sync_with_empty_operations_array()
    {
        $user = User::factory()->create();

        // Send sync request with empty operations array
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => []
            ]);

        // Should return 200 OK
        $response->assertStatus(200);

        // Should have appropriate message
        $response->assertJson([
            'message' => 'No operations to sync',
            'results' => []
        ]);

        // Results should be empty array
        $this->assertIsArray($response->json('results'));
        $this->assertCount(0, $response->json('results'));
    }

    /**
     * @test
     * Test sync with missing operations key
     * 
     * When operations key is not provided at all, the endpoint should
     * handle it gracefully and return empty results.
     */
    public function sync_with_missing_operations_key()
    {
        $user = User::factory()->create();

        // Send sync request without operations key
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', []);

        // Should return 200 OK
        $response->assertStatus(200);

        // Should have appropriate message
        $response->assertJson([
            'message' => 'No operations to sync',
            'results' => []
        ]);
    }

    /**
     * @test
     * Test sync with invalid operation type
     * 
     * When an unknown operation type is provided, the sync endpoint should
     * return an error for that operation but continue processing other operations.
     */
    public function sync_with_invalid_operation_type()
    {
        $user = User::factory()->create();

        // Send sync request with invalid operation type
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'invalid_operation_type',
                        'data' => [
                            'some_field' => 'some_value'
                        ]
                    ]
                ]
            ]);

        // Should return 200 OK (sync completed, but with errors)
        $response->assertStatus(200);

        // Should have sync completed message
        $response->assertJson([
            'message' => 'Sync completed'
        ]);

        // Should have one result with error status
        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertEquals(0, $results[0]['index']);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('Unknown operation type', $results[0]['message']);
    }

    /**
     * @test
     * Test sync with multiple invalid operation types
     * 
     * All invalid operations should fail with appropriate error messages.
     */
    public function sync_with_multiple_invalid_operation_types()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    ['type' => 'delete_group', 'data' => []],
                    ['type' => 'update_bill', 'data' => []],
                    ['type' => 'unknown_type', 'data' => []],
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        // All three operations should fail
        $this->assertCount(3, $results);
        
        foreach ($results as $result) {
            $this->assertEquals('error', $result['status']);
            $this->assertStringContainsString('Unknown operation type', $result['message']);
        }
    }

    /**
     * @test
     * Test sync with missing operation type
     * 
     * When operation type is missing, should return error for that operation.
     */
    public function sync_with_missing_operation_type()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'data' => [
                            'name' => 'Test Group'
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('Unknown operation type', $results[0]['message']);
    }

    /**
     * @test
     * Test sync with failed operation - missing required field
     * 
     * When required fields are missing, the operation should fail with
     * an appropriate error message.
     */
    public function sync_with_failed_operation_missing_required_field()
    {
        $user = User::factory()->create();

        // Try to create group without name (required field)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_123'
                            // Missing 'name' field
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('Group name is required', $results[0]['message']);
    }

    /**
     * @test
     * Test sync with failed operation - invalid data
     * 
     * When data validation fails, the operation should fail with
     * an appropriate error message.
     */
    public function sync_with_failed_operation_invalid_data()
    {
        $user = User::factory()->create();

        // Try to create bill with invalid amount (zero or negative)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => 'local_bill_123',
                            'group_id' => 999, // Non-existent group
                            'title' => 'Test Bill',
                            'total_amount' => 0, // Invalid amount
                            'bill_date' => now()->format('Y-m-d'),
                            'shares' => []
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('Valid total amount is required', $results[0]['message']);
    }

    /**
     * @test
     * Test sync with failed operation - authorization failure
     * 
     * When user is not authorized to perform an operation (e.g., create bill
     * in a group they're not a member of), the operation should fail.
     */
    public function sync_with_failed_operation_authorization_failure()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        
        // Create a group that the user is NOT a member of
        $group = Group::factory()->create(['creator_id' => $otherUser->id]);
        $group->members()->attach($otherUser->id, ['joined_at' => now()]);

        // Try to create bill in group user is not a member of
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => 'local_bill_123',
                            'group_id' => $group->id,
                            'title' => 'Unauthorized Bill',
                            'total_amount' => 100.00,
                            'bill_date' => now()->format('Y-m-d'),
                            'shares' => [
                                [
                                    'user_id' => $user->id,
                                    'amount' => 100.00
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('User is not a member of this group', $results[0]['message']);
    }

    /**
     * @test
     * Test sync with mix of successful and failed operations
     * 
     * When some operations succeed and others fail, the sync endpoint should
     * process all operations and return appropriate status for each.
     */
    public function sync_with_mix_of_successful_and_failed_operations()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    // Operation 0: Success - valid group creation
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_group_1',
                            'name' => 'Valid Group'
                        ]
                    ],
                    // Operation 1: Failure - missing group name
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_group_2'
                            // Missing name
                        ]
                    ],
                    // Operation 2: Success - valid bill creation
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => 'local_bill_1',
                            'group_id' => $group->id,
                            'title' => 'Valid Bill',
                            'total_amount' => 100.00,
                            'bill_date' => now()->format('Y-m-d'),
                            'shares' => [
                                [
                                    'user_id' => $user->id,
                                    'amount' => 100.00
                                ]
                            ]
                        ]
                    ],
                    // Operation 3: Failure - invalid operation type
                    [
                        'type' => 'delete_bill',
                        'data' => []
                    ],
                    // Operation 4: Failure - invalid bill amount
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'local_id' => 'local_bill_2',
                            'group_id' => $group->id,
                            'title' => 'Invalid Bill',
                            'total_amount' => -50.00, // Negative amount
                            'bill_date' => now()->format('Y-m-d'),
                            'shares' => []
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        // Should have 5 results
        $this->assertCount(5, $results);

        // Operation 0: Success
        $this->assertEquals(0, $results[0]['index']);
        $this->assertEquals('success', $results[0]['status']);
        $this->assertArrayHasKey('data', $results[0]);
        $this->assertArrayHasKey('server_id', $results[0]['data']);

        // Operation 1: Failure
        $this->assertEquals(1, $results[1]['index']);
        $this->assertEquals('error', $results[1]['status']);
        $this->assertStringContainsString('Group name is required', $results[1]['message']);

        // Operation 2: Success
        $this->assertEquals(2, $results[2]['index']);
        $this->assertEquals('success', $results[2]['status']);
        $this->assertArrayHasKey('data', $results[2]);

        // Operation 3: Failure
        $this->assertEquals(3, $results[3]['index']);
        $this->assertEquals('error', $results[3]['status']);
        $this->assertStringContainsString('Unknown operation type', $results[3]['message']);

        // Operation 4: Failure
        $this->assertEquals(4, $results[4]['index']);
        $this->assertEquals('error', $results[4]['status']);
        $this->assertStringContainsString('Valid total amount is required', $results[4]['message']);

        // Verify successful operations created resources
        $this->assertDatabaseHas('groups', ['name' => 'Valid Group']);
        $this->assertDatabaseHas('bills', ['title' => 'Valid Bill']);

        // Verify failed operations did not create resources
        $this->assertDatabaseMissing('bills', ['title' => 'Invalid Bill']);
    }

    /**
     * @test
     * Test sync with failed operation does not affect other operations
     * 
     * Failed operations should be isolated - they should not cause
     * other operations to fail or be rolled back.
     */
    public function sync_failed_operation_does_not_affect_other_operations()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    // Valid operation before failure
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_group_before',
                            'name' => 'Group Before Failure'
                        ]
                    ],
                    // Failed operation
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_group_fail'
                            // Missing name - will fail
                        ]
                    ],
                    // Valid operation after failure
                    [
                        'type' => 'create_group',
                        'data' => [
                            'local_id' => 'local_group_after',
                            'name' => 'Group After Failure'
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $results = $response->json('results');

        // All three operations should be processed
        $this->assertCount(3, $results);

        // First operation: Success
        $this->assertEquals('success', $results[0]['status']);

        // Second operation: Failure
        $this->assertEquals('error', $results[1]['status']);

        // Third operation: Success (not affected by previous failure)
        $this->assertEquals('success', $results[2]['status']);

        // Verify both successful operations created groups
        $this->assertDatabaseHas('groups', ['name' => 'Group Before Failure']);
        $this->assertDatabaseHas('groups', ['name' => 'Group After Failure']);

        // Verify we have exactly 2 groups created
        $this->assertEquals(2, Group::count());
    }

    /**
     * @test
     * Test sync with all operations failing
     * 
     * When all operations fail, the sync should still return 200 OK
     * with error status for each operation.
     */
    public function sync_with_all_operations_failing()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/sync', [
                'operations' => [
                    [
                        'type' => 'create_group',
                        'data' => [] // Missing name
                    ],
                    [
                        'type' => 'invalid_type',
                        'data' => []
                    ],
                    [
                        'type' => 'create_bill',
                        'data' => [
                            'group_id' => 999, // Non-existent group
                            'title' => 'Test',
                            'total_amount' => 0, // Invalid amount
                            'bill_date' => now()->format('Y-m-d')
                        ]
                    ]
                ]
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Sync completed']);

        $results = $response->json('results');
        $this->assertCount(3, $results);

        // All operations should have error status
        foreach ($results as $result) {
            $this->assertEquals('error', $result['status']);
            $this->assertArrayHasKey('message', $result);
        }

        // No resources should be created
        $this->assertEquals(0, Group::count());
        $this->assertEquals(0, \App\Models\Bill::count());
    }
}

