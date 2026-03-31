<?php

namespace Tests\Feature\Validation;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests: Authorization Edge Cases
 * 
 * This test suite covers edge cases for authorization and validation:
 * - Middleware with missing group_id
 * - Middleware with non-existent group
 * - Validation with boundary values
 * - Validation with special characters in email
 */
class AuthorizationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Edge case: Middleware with missing group_id in request
     */
    public function middleware_returns_400_when_group_id_is_missing()
    {
        $user = User::factory()->create();
        
        // Attempt to create bill without group_id
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
                // group_id is missing
            ]);
        
        // Middleware returns 400 when group_id is missing
        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Group ID is required',
            'error_code' => 'GROUP_ID_MISSING'
        ]);
    }

    /**
     * @test
     * Edge case: Middleware with non-existent group
     */
    public function middleware_returns_403_when_group_does_not_exist()
    {
        $user = User::factory()->create();
        $nonExistentGroupId = 99999;
        
        // Attempt to create bill in non-existent group
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $nonExistentGroupId,
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        // Should return 403 Forbidden (user is not member of non-existent group)
        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Forbidden',
            'error_code' => 'FORBIDDEN'
        ]);
    }

    /**
     * @test
     * Edge case: Middleware with null group_id
     */
    public function middleware_handles_null_group_id()
    {
        $user = User::factory()->create();
        
        // Attempt to create bill with null group_id
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => null,
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        // Middleware returns 400 when group_id is null
        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Group ID is required',
            'error_code' => 'GROUP_ID_MISSING'
        ]);
    }

    /**
     * @test
     * Edge case: Middleware with string group_id instead of integer
     */
    public function middleware_handles_invalid_group_id_type()
    {
        $user = User::factory()->create();
        
        // Attempt to create bill with string group_id
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => 'not_a_number',
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        // Should return 422 validation error or 403 (depending on validation order)
        $this->assertContains($response->status(), [422, 403]);
    }

    /**
     * @test
     * Edge case: Validation with boundary values - minimum amount
     */
    public function validation_rejects_amount_below_minimum()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test amounts below minimum (0.01)
        $invalidAmounts = [0, 0.001, -0.01, -1, -100];
        
        foreach ($invalidAmounts as $amount) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Test Bill',
                    'total_amount' => $amount,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['total_amount']);
        }
    }

    /**
     * @test
     * Edge case: Validation with boundary values - exactly minimum amount
     */
    public function validation_accepts_minimum_valid_amount()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test minimum valid amount (0.01)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Test Bill',
                'total_amount' => 0.01,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(201);
    }

    /**
     * @test
     * Edge case: Validation with boundary values - maximum amount
     */
    public function validation_accepts_large_amounts()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test large valid amounts
        $largeAmounts = [999999.99, 100000.00, 50000.50];
        
        foreach ($largeAmounts as $amount) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Test Bill',
                    'total_amount' => $amount,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(201);
        }
    }

    /**
     * @test
     * Edge case: Validation with special characters in email
     */
    public function validation_rejects_emails_with_special_characters()
    {
        // Test various invalid email formats with special characters
        $invalidEmails = [
            'user name@example.com',  // Space
            'user@exam ple.com',      // Space in domain
            'user<>@example.com',     // Angle brackets
            'user"test"@example.com', // Quotes
            'user,test@example.com',  // Comma
            'user;test@example.com',  // Semicolon
            'user[test]@example.com', // Brackets
            'user\\test@example.com', // Backslash
        ];
        
        foreach ($invalidEmails as $email) {
            $response = $this->postJson('/api/register', [
                'username' => 'testuser_' . uniqid(),
                'email' => $email,
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
                'fcm_token' => 'test_token',
                'device_id' => 'test_device',
            ]);
            
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
        }
    }

    /**
     * @test
     * Edge case: Validation accepts valid emails with allowed special characters
     */
    public function validation_accepts_valid_emails_with_allowed_characters()
    {
        // Test valid email formats with allowed special characters
        $validEmails = [
            'user.name@example.com',      // Dot
            'user+tag@example.com',       // Plus
            'user_name@example.com',      // Underscore
            'user-name@example.com',      // Hyphen
            'user123@example.co.uk',      // Multiple TLDs
            'user@sub.example.com',       // Subdomain
        ];
        
        foreach ($validEmails as $email) {
            $response = $this->postJson('/api/register', [
                'username' => 'testuser_' . uniqid(),
                'email' => $email,
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
                'fcm_token' => 'test_token_' . uniqid(),
                'device_id' => 'test_device_' . uniqid(),
            ]);
            
            $response->assertStatus(201);
        }
    }

    /**
     * @test
     * Edge case: Validation with empty string email
     */
    public function validation_rejects_empty_email()
    {
        $response = $this->postJson('/api/register', [
            'username' => 'testuser',
            'email' => '',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'fcm_token' => 'test_token',
            'device_id' => 'test_device',
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * @test
     * Edge case: Validation with missing email field
     */
    public function validation_rejects_missing_email()
    {
        $response = $this->postJson('/api/register', [
            'username' => 'testuser',
            // email is missing
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'fcm_token' => 'test_token',
            'device_id' => 'test_device',
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * @test
     * Edge case: Validation with extremely long email
     */
    public function validation_rejects_extremely_long_email()
    {
        // Create email longer than 255 characters
        $longEmail = str_repeat('a', 250) . '@example.com';
        
        $response = $this->postJson('/api/register', [
            'username' => 'testuser',
            'email' => $longEmail,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'fcm_token' => 'test_token',
            'device_id' => 'test_device',
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * @test
     * Edge case: Validation with non-string amount
     */
    public function validation_handles_non_numeric_amount()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test non-numeric amounts
        $invalidAmounts = ['abc', 'one hundred', '100abc', 'null', 'undefined'];
        
        foreach ($invalidAmounts as $amount) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Test Bill',
                    'total_amount' => $amount,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['total_amount']);
        }
    }

    /**
     * @test
     * Edge case: Validation with array instead of scalar value
     */
    public function validation_rejects_array_for_scalar_fields()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test array for amount field
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $group->id,
                'title' => 'Test Bill',
                'total_amount' => [100, 200],  // Array instead of number
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total_amount']);
    }

    /**
     * @test
     * Edge case: Middleware with deleted group
     */
    public function middleware_handles_soft_deleted_group()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Note: Groups don't have soft delete in current schema,
        // but we test the behavior if group is deleted
        $groupId = $group->id;
        $group->delete();
        
        // Attempt to create bill in deleted group
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/bills', [
                'group_id' => $groupId,
                'title' => 'Test Bill',
                'total_amount' => 100.00,
                'bill_date' => now()->format('Y-m-d'),
                'split_type' => 'equal',
            ]);
        
        // Should return 403 Forbidden (user is no longer member)
        $response->assertStatus(403);
    }

    /**
     * @test
     * Edge case: Validation with SQL injection attempt in email
     */
    public function validation_prevents_sql_injection_in_email()
    {
        $sqlInjectionAttempts = [
            "admin'--",
            "admin' OR '1'='1",
            "'; DROP TABLE users; --",
            "admin'; DELETE FROM users WHERE '1'='1",
        ];
        
        foreach ($sqlInjectionAttempts as $email) {
            $response = $this->postJson('/api/register', [
                'username' => 'testuser_' . uniqid(),
                'email' => $email,
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
                'fcm_token' => 'test_token',
                'device_id' => 'test_device',
            ]);
            
            // Should reject as invalid email format
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
        }
        
        // Verify users table is intact
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @test
     * Edge case: Validation with XSS attempt in title
     */
    public function validation_handles_xss_attempt_in_title()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Test XSS attempts in title
        $xssAttempts = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
        ];
        
        foreach ($xssAttempts as $title) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => $title,
                    'total_amount' => 100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            // Should accept (Laravel escapes output automatically)
            // Verify the data is stored as-is and will be escaped on output
            $response->assertStatus(201);
            
            // Get the bill ID from response
            $billId = $response->json('id');
            $bill = Bill::find($billId);
            $this->assertEquals($title, $bill->title);
        }
    }

    /**
     * @test
     * Edge case: Concurrent requests with same group_id
     */
    public function middleware_handles_concurrent_requests()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id);
        
        // Simulate multiple concurrent requests
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/bills', [
                    'group_id' => $group->id,
                    'title' => 'Concurrent Bill ' . $i,
                    'total_amount' => 100.00,
                    'bill_date' => now()->format('Y-m-d'),
                    'split_type' => 'equal',
                ]);
            
            $response->assertStatus(201);
        }
        
        // Verify all bills were created
        $this->assertDatabaseCount('bills', 5);
    }
}
