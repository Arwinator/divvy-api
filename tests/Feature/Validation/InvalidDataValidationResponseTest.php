<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Invalid Data Validation Response
 * 
 * This test validates that invalid data returns 422 Unprocessable Entity
 * with field-specific error messages across various endpoints.
 */
class InvalidDataValidationResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Property: Invalid data returns 422 with field-specific errors
     * 
     * Test that all endpoints return proper validation errors when
     * provided with invalid data across various scenarios.
     */
    public function invalid_data_validation_response_property()
    {
        // Run 50 iterations with different invalid data scenarios
        for ($i = 0; $i < 50; $i++) {
            // Test different endpoints with invalid data
            $endpoint = $i % 5;
            
            switch ($endpoint) {
                case 0:
                    $this->testInvalidRegistration($i);
                    break;
                case 1:
                    $this->testInvalidGroupCreation($i);
                    break;
                case 2:
                    $this->testInvalidBillCreation($i);
                    break;
                case 3:
                    $this->testInvalidPaymentInitiation($i);
                    break;
                case 4:
                    $this->testInvalidInvitation($i);
                    break;
            }
        }
    }

    /**
     * Test invalid registration data
     */
    private function testInvalidRegistration($iteration)
    {
        // Generate various invalid registration scenarios
        $scenarios = [
            // Missing required fields
            [
                'data' => [],
                'expectedErrors' => ['username', 'email', 'password', 'fcm_token', 'device_id'],
            ],
            // Invalid email format
            [
                'data' => [
                    'username' => 'testuser' . $iteration,
                    'email' => 'invalid-email',
                    'password' => 'Password123!',
                    'password_confirmation' => 'Password123!',
                    'fcm_token' => 'test_token',
                    'device_id' => 'test_device',
                ],
                'expectedErrors' => ['email'],
            ],
            // Password too short
            [
                'data' => [
                    'username' => 'testuser' . $iteration,
                    'email' => 'test' . $iteration . '@example.com',
                    'password' => 'short',
                    'password_confirmation' => 'short',
                    'fcm_token' => 'test_token',
                    'device_id' => 'test_device',
                ],
                'expectedErrors' => ['password'],
            ],
            // Password confirmation mismatch
            [
                'data' => [
                    'username' => 'testuser' . $iteration,
                    'email' => 'test' . $iteration . '@example.com',
                    'password' => 'Password123!',
                    'password_confirmation' => 'DifferentPassword123!',
                    'fcm_token' => 'test_token',
                    'device_id' => 'test_device',
                ],
                'expectedErrors' => ['password'],
            ],
        ];

        $scenario = $scenarios[$iteration % count($scenarios)];
        
        $response = $this->postJson('/api/register', $scenario['data']);

        // Property: Should return 422 status
        $response->assertStatus(422);
        
        // Property: Should have validation errors for expected fields
        $response->assertJsonValidationErrors($scenario['expectedErrors']);
        
        // Property: Response should have 'message' and 'errors' keys
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
    }

    /**
     * Test invalid group creation data
     */
    private function testInvalidGroupCreation($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_' . $iteration . '_' . uniqid(),
            'email' => 'user_' . $iteration . '_' . uniqid() . '@test.com',
        ]);

        // Generate various invalid group scenarios
        $scenarios = [
            // Missing name
            [
                'data' => [],
                'expectedErrors' => ['name'],
            ],
            // Empty name
            [
                'data' => ['name' => ''],
                'expectedErrors' => ['name'],
            ],
            // Name too long (over 255 characters)
            [
                'data' => ['name' => str_repeat('a', 256)],
                'expectedErrors' => ['name'],
            ],
        ];

        $scenario = $scenarios[$iteration % count($scenarios)];
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/groups', $scenario['data']);

        // Property: Should return 422 status
        $response->assertStatus(422);
        
        // Property: Should have validation errors for expected fields
        $response->assertJsonValidationErrors($scenario['expectedErrors']);
        
        // Property: Response should have 'message' and 'errors' keys
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
    }

    /**
     * Test invalid bill creation data
     */
    private function testInvalidBillCreation($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_' . $iteration . '_' . uniqid(),
            'email' => 'user_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Generate various invalid bill scenarios
        $scenarios = [
            // Missing required fields
            [
                'data' => [],
                'expectedErrors' => ['title', 'total_amount', 'bill_date', 'group_id', 'split_type'],
            ],
            // Negative amount
            [
                'data' => [
                    'title' => 'Test Bill',
                    'total_amount' => -100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'group_id' => $group->id,
                    'split_type' => 'equal',
                ],
                'expectedErrors' => ['total_amount'],
            ],
            // Zero amount
            [
                'data' => [
                    'title' => 'Test Bill',
                    'total_amount' => 0,
                    'bill_date' => now()->format('Y-m-d'),
                    'group_id' => $group->id,
                    'split_type' => 'equal',
                ],
                'expectedErrors' => ['total_amount'],
            ],
            // Invalid date format
            [
                'data' => [
                    'title' => 'Test Bill',
                    'total_amount' => 100.00,
                    'bill_date' => 'invalid-date',
                    'group_id' => $group->id,
                    'split_type' => 'equal',
                ],
                'expectedErrors' => ['bill_date'],
            ],
            // Invalid split type
            [
                'data' => [
                    'title' => 'Test Bill',
                    'total_amount' => 100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'group_id' => $group->id,
                    'split_type' => 'invalid',
                ],
                'expectedErrors' => ['split_type'],
            ],
        ];

        $scenario = $scenarios[$iteration % count($scenarios)];
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', $scenario['data']);

        // Property: Should return 422 status
        $response->assertStatus(422);
        
        // Property: Should have validation errors for expected fields
        $response->assertJsonValidationErrors($scenario['expectedErrors']);
        
        // Property: Response should have 'message' and 'errors' keys
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
    }

    /**
     * Test invalid payment initiation data
     */
    private function testInvalidPaymentInitiation($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_' . $iteration . '_' . uniqid(),
            'email' => 'user_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);
        
        $bill = $group->bills()->create([
            'creator_id' => $user->id,
            'title' => 'Test Bill',
            'total_amount' => 100.00,
            'bill_date' => now(),
        ]);
        
        $share = $bill->shares()->create([
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);

        // Generate various invalid payment scenarios
        $scenarios = [
            // Missing payment method
            [
                'data' => [],
                'expectedErrors' => ['payment_method'],
            ],
            // Invalid payment method
            [
                'data' => ['payment_method' => 'invalid'],
                'expectedErrors' => ['payment_method'],
            ],
            // Empty payment method
            [
                'data' => ['payment_method' => ''],
                'expectedErrors' => ['payment_method'],
            ],
        ];

        $scenario = $scenarios[$iteration % count($scenarios)];
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", $scenario['data']);

        // Property: Should return 422 status
        $response->assertStatus(422);
        
        // Property: Should have validation errors for expected fields
        $response->assertJsonValidationErrors($scenario['expectedErrors']);
        
        // Property: Response should have 'message' and 'errors' keys
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
    }

    /**
     * Test invalid invitation data
     */
    private function testInvalidInvitation($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_' . $iteration . '_' . uniqid(),
            'email' => 'user_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Generate various invalid invitation scenarios
        $scenarios = [
            // Missing identifier
            [
                'data' => [],
                'expectedErrors' => ['identifier'],
            ],
            // Empty identifier
            [
                'data' => ['identifier' => ''],
                'expectedErrors' => ['identifier'],
            ],
        ];

        $scenario = $scenarios[$iteration % count($scenarios)];
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", $scenario['data']);

        // Property: Should return 422 status
        $response->assertStatus(422);
        
        // Property: Should have validation errors for expected fields
        $response->assertJsonValidationErrors($scenario['expectedErrors']);
        
        // Property: Response should have 'message' and 'errors' keys
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
    }

    /**
     * @test
     * Edge case: Multiple validation errors should all be returned
     */
    public function multiple_validation_errors_returned()
    {
        // Test registration with multiple invalid fields
        $response = $this->postJson('/api/register', [
            'username' => '', // Missing
            'email' => 'invalid-email', // Invalid format
            'password' => 'short', // Too short
            'password_confirmation' => 'different', // Mismatch
            // Missing fcm_token and device_id
        ]);

        $response->assertStatus(422);
        
        // Should have errors for all invalid fields
        $response->assertJsonValidationErrors([
            'username',
            'email',
            'password',
            'fcm_token',
            'device_id',
        ]);
    }

    /**
     * @test
     * Edge case: Validation error messages should be descriptive
     */
    public function validation_error_messages_are_descriptive()
    {
        $response = $this->postJson('/api/register', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        
        // Error message should be descriptive
        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        
        // Each error should have at least one message
        foreach ($errors as $messages) {
            $this->assertIsArray($messages);
            $this->assertNotEmpty($messages);
            $this->assertIsString($messages[0]);
            $this->assertNotEmpty($messages[0]);
        }
    }

    /**
     * @test
     * Edge case: Numeric validation for amounts
     */
    public function numeric_validation_for_amounts()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Test with non-numeric amount
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Test Bill',
                'total_amount' => 'not-a-number',
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_amount']);
    }

    /**
     * @test
     * Edge case: Email format validation
     */
    public function email_format_validation()
    {
        // Test clearly invalid email: no @ symbol
        $response = $this->postJson('/api/register', [
            'username' => 'testuser_' . uniqid(),
            'email' => 'notanemail',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'fcm_token' => 'test_token_' . uniqid(),
            'device_id' => 'test_device_' . uniqid(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * @test
     * Edge case: Date validation
     */
    public function date_validation()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $invalidDates = [
            'not-a-date',
            '2024-13-01', // Invalid month
            '2024-02-30', // Invalid day
        ];

        foreach ($invalidDates as $index => $date) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'title' => 'Test Bill ' . $index,
                    'total_amount' => 100.00,
                    'bill_date' => $date,
                    'group_id' => $group->id,
                    'split_type' => 'equal',
                ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['bill_date']);
        }
    }

    /**
     * @test
     * Positive case: Valid data should not return validation errors
     */
    public function valid_data_accepted()
    {
        // Test valid registration
        $response = $this->postJson('/api/register', [
            'username' => 'validuser',
            'email' => 'valid@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'fcm_token' => 'test_token',
            'device_id' => 'test_device',
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissingValidationErrors();
    }
}
