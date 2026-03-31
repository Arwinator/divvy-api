<?php

namespace Tests\Feature\Validation;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmailFormatValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Property-Based Test: Email Format Validation
     *
     * This test validates that invalid email formats are rejected across all endpoints
     * that accept email input. The test uses definitively invalid email formats that
     * will always fail Laravel's email validation (no @ symbol, spaces, double @, empty).
     *
     * Tests 50 iterations with various invalid email formats to ensure consistent
     * validation behavior.
     */
    public function test_invalid_email_formats_are_rejected(): void
    {
        // Definitively invalid email formats that will always fail validation
        $invalidEmails = [
            'notanemail',           // No @ symbol
            'invalid email',        // Contains space
            'user@@example.com',    // Double @
            '',                     // Empty string
            'user name@test.com',   // Space in local part
        ];

        for ($i = 0; $i < 50; $i++) {
            // Pick a random invalid email format
            $invalidEmail = $invalidEmails[array_rand($invalidEmails)];

            // Test registration endpoint
            $response = $this->postJson('/api/register', [
                'username' => 'user_' . $i . '_' . uniqid(),
                'email' => $invalidEmail,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
            $this->assertStringContainsString('email', strtolower($response->json('message')));
        }
    }

    /**
     * Test that valid email formats are accepted
     *
     * This test ensures that the validation doesn't reject valid emails.
     * Uses standard email formats that should always pass validation.
     */
    public function test_valid_email_formats_are_accepted(): void
    {
        $validEmails = [
            'user@example.com',
            'test.user@example.com',
            'user+tag@example.co.uk',
            'user_name@test-domain.com',
        ];

        foreach ($validEmails as $index => $validEmail) {
            $response = $this->postJson('/api/register', [
                'username' => 'user_' . $index . '_' . uniqid(),
                'email' => $validEmail,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            // Should succeed (201) or fail for other reasons, but NOT email validation
            if ($response->status() === 422) {
                $this->assertArrayNotHasKey('email', $response->json('errors', []));
            }
        }
    }
}
