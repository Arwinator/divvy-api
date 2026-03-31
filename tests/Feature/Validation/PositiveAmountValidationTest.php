<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Positive Amount Validation
 * 
 * This test validates that zero, negative, or non-numeric amounts
 * are rejected with 422 validation errors for bills and shares.
 */
class PositiveAmountValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Positive Amount Validation
     * 
     * Test that bill amounts and share amounts reject zero, negative,
     * or non-numeric values with proper validation errors.
     */
    public function positive_amount_validation_property()
    {
        // Run 100 iterations with different invalid amount scenarios
        for ($i = 0; $i < 100; $i++) {
            // Alternate between bill total_amount and share amount testing
            if ($i % 2 === 0) {
                $this->testInvalidBillAmount($i);
            } else {
                $this->testInvalidShareAmount($i);
            }
        }
    }

    /**
     * Test invalid bill total_amount values
     */
    private function testInvalidBillAmount($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_bill_' . $iteration . '_' . uniqid(),
            'email' => 'user_bill_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Generate various invalid amount scenarios
        $invalidAmounts = [
            0,                      // Zero
            0.00,                   // Zero decimal
            -1,                     // Negative integer
            -100.50,                // Negative decimal
            -0.01,                  // Small negative
            'not-a-number',         // Non-numeric string
            'abc123',               // Alphanumeric
            '',                     // Empty string
            null,                   // Null value
        ];

        $amount = $invalidAmounts[$iteration % count($invalidAmounts)];
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Test Bill ' . $iteration,
                'total_amount' => $amount,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        // Property: Should return 422 status for invalid amounts
        $response->assertStatus(422);
        
        // Property: Should have validation error for total_amount field
        $response->assertJsonValidationErrors(['total_amount']);
        
        // Property: Response should have proper structure
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'total_amount',
            ],
        ]);
        
        // Property: Error message should be descriptive
        $errors = $response->json('errors.total_amount');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertIsString($errors[0]);
    }

    /**
     * Test invalid share amount values in custom split
     */
    private function testInvalidShareAmount($iteration)
    {
        $user = User::factory()->create([
            'username' => 'user_share_' . $iteration . '_' . uniqid(),
            'email' => 'user_share_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $user2 = User::factory()->create([
            'username' => 'user2_share_' . $iteration . '_' . uniqid(),
            'email' => 'user2_share_' . $iteration . '_' . uniqid() . '@test.com',
        ]);
        
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        // Generate various invalid share amount scenarios
        $invalidAmounts = [
            0,                      // Zero
            0.00,                   // Zero decimal
            -1,                     // Negative integer
            -50.25,                 // Negative decimal
            -0.01,                  // Small negative
            'invalid',              // Non-numeric string
            'xyz',                  // Alphabetic
            '',                     // Empty string
            null,                   // Null value
        ];

        $invalidAmount = $invalidAmounts[$iteration % count($invalidAmounts)];
        
        // Create custom split with one invalid share amount
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Test Bill ' . $iteration,
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    [
                        'user_id' => $user->id,
                        'amount' => $invalidAmount,  // Invalid amount
                    ],
                    [
                        'user_id' => $user2->id,
                        'amount' => 100.00,
                    ],
                ],
            ]);

        // Property: Should return 422 status for invalid share amounts
        $response->assertStatus(422);
        
        // Property: Should have validation error for shares.0.amount field
        $response->assertJsonValidationErrors(['shares.0.amount']);
        
        // Property: Response should have proper structure
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);
        
        // Property: Error message should be descriptive
        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
    }

    /**
     * @test
     * Edge case: Zero amount should be rejected for bills
     */
    public function zero_bill_amount_rejected()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Zero Amount Bill',
                'total_amount' => 0,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_amount']);
    }

    /**
     * @test
     * Edge case: Negative amount should be rejected for bills
     */
    public function negative_bill_amount_rejected()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
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
     * @test
     * Edge case: Non-numeric string should be rejected for bills
     */
    public function non_numeric_bill_amount_rejected()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Non-numeric Amount Bill',
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
     * Edge case: Zero share amount should be rejected
     */
    public function zero_share_amount_rejected()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Zero Share Amount',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $user->id, 'amount' => 0],
                    ['user_id' => $user2->id, 'amount' => 100.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.0.amount']);
    }

    /**
     * @test
     * Edge case: Negative share amount should be rejected
     */
    public function negative_share_amount_rejected()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Negative Share Amount',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $user->id, 'amount' => -50.00],
                    ['user_id' => $user2->id, 'amount' => 150.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.0.amount']);
    }

    /**
     * @test
     * Edge case: Non-numeric share amount should be rejected
     */
    public function non_numeric_share_amount_rejected()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Non-numeric Share Amount',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $user->id, 'amount' => 'invalid'],
                    ['user_id' => $user2->id, 'amount' => 100.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shares.0.amount']);
    }

    /**
     * @test
     * Edge case: Multiple invalid share amounts should all be reported
     */
    public function multiple_invalid_share_amounts_reported()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id, $user3->id], ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Multiple Invalid Shares',
                'total_amount' => 300.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $user->id, 'amount' => 0],        // Invalid: zero
                    ['user_id' => $user2->id, 'amount' => -50.00],  // Invalid: negative
                    ['user_id' => $user3->id, 'amount' => 'abc'],   // Invalid: non-numeric
                ],
            ]);

        $response->assertStatus(422);
        
        // All three invalid shares should have errors
        $response->assertJsonValidationErrors([
            'shares.0.amount',
            'shares.1.amount',
            'shares.2.amount',
        ]);
    }

    /**
     * @test
     * Positive case: Valid positive amounts should be accepted
     */
    public function valid_positive_amounts_accepted()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        // Test valid bill with positive amount
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Valid Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $response->assertJsonMissingValidationErrors();
    }

    /**
     * @test
     * Positive case: Valid custom split with positive share amounts
     */
    public function valid_custom_split_with_positive_amounts_accepted()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach([$user->id, $user2->id], ['joined_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Valid Custom Split',
                'total_amount' => 150.00,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'custom',
                'shares' => [
                    ['user_id' => $user->id, 'amount' => 60.00],
                    ['user_id' => $user2->id, 'amount' => 90.00],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonMissingValidationErrors();
    }

    /**
     * @test
     * Edge case: Very small positive amounts should be accepted
     */
    public function very_small_positive_amounts_accepted()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Test minimum valid amount (0.01)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Small Amount Bill',
                'total_amount' => 0.01,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $response->assertJsonMissingValidationErrors();
    }

    /**
     * @test
     * Edge case: Large positive amounts should be accepted
     */
    public function large_positive_amounts_accepted()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);

        // Test large amount
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Large Amount Bill',
                'total_amount' => 99999999.99,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);
        $response->assertJsonMissingValidationErrors();
    }
}
