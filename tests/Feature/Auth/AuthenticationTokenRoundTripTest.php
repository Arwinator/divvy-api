<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Authentication Token Round Trip
 * 
 * This test validates the complete authentication token lifecycle:
 * - Token is returned on login
 * - Token can be used for authenticated requests
 * - Token is cleared on logout
 */
class AuthenticationTokenRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Authentication Token Round Trip
     * 
     * Test that token is returned on login, can be used for authenticated requests,
     * and is cleared on logout.
     */
    public function authentication_token_round_trip_property()
    {
        // Run 100 iterations with different users to verify property holds
        for ($i = 0; $i < 100; $i++) {
            // Generate random user data
            $userData = [
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'test_' . uniqid() . '_' . $i . '@example.com',
                'password' => 'SecurePass123!@#',
                'password_confirmation' => 'SecurePass123!@#',
                'fcm_token' => 'fcm_token_' . uniqid() . '_' . $i,
                'device_id' => 'device_' . uniqid() . '_' . $i,
            ];

            // Step 1: Register user
            $registerResponse = $this->postJson('/api/register', $userData);
            $registerResponse->assertStatus(201);
            $registerResponse->assertJsonStructure([
                'user' => ['id', 'username', 'email', 'created_at'],
                'token',
            ]);

            // Extract token from registration
            $token = $registerResponse->json('token');

            // Verify token is returned on registration/login
            $this->assertNotEmpty($token, 'Token should be returned on registration');
            $this->assertIsString($token, 'Token should be a string');

            // Step 2: Logout to test login flow
            $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/logout', ['device_id' => $userData['device_id']]);
            $logoutResponse->assertStatus(200);

            // Clear cached authentication to ensure token revocation is checked
            $this->app->forgetInstance('auth');

            // Verify first token is cleared/revoked on logout
            $unauthenticatedResponse1 = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/groups');
            $unauthenticatedResponse1->assertStatus(401);

            // Step 3: Login with same credentials
            $loginData = [
                'email' => $userData['email'],
                'password' => $userData['password'],
                'fcm_token' => $userData['fcm_token'],
                'device_id' => $userData['device_id'],
            ];

            $loginResponse = $this->postJson('/api/login', $loginData);
            $loginResponse->assertStatus(200);
            $loginResponse->assertJsonStructure([
                'user' => ['id', 'username', 'email', 'created_at'],
                'token',
            ]);

            // Verify token is returned on login
            $newToken = $loginResponse->json('token');
            $this->assertNotEmpty($newToken, 'Token should be returned on login');
            $this->assertIsString($newToken, 'Token should be a string');
            $this->assertNotEquals($token, $newToken, 'New token should be different from revoked token');

            // Step 4: Use token to access protected endpoint (GET /api/groups)
            $authenticatedResponse = $this->withHeader('Authorization', 'Bearer ' . $newToken)
                ->getJson('/api/groups');
            
            $authenticatedResponse->assertStatus(200);
            $this->assertTrue(
                $authenticatedResponse->status() === 200,
                'Token should allow access to protected endpoints'
            );

            // Step 5: Logout to revoke token
            $finalLogoutResponse = $this->withHeader('Authorization', 'Bearer ' . $newToken)
                ->postJson('/api/logout', ['device_id' => $userData['device_id']]);
            $finalLogoutResponse->assertStatus(200);

            // Clear cached authentication to ensure token revocation is checked
            $this->app->forgetInstance('auth');

            // Verify token is cleared/revoked on logout
            // Attempt to use the same token after logout should fail with 401
            $unauthenticatedResponse2 = $this->withHeader('Authorization', 'Bearer ' . $newToken)
                ->getJson('/api/groups');
            
            $unauthenticatedResponse2->assertStatus(401);
            $this->assertTrue(
                $unauthenticatedResponse2->status() === 401,
                'Revoked token should not allow access to protected endpoints'
            );
        }
    }
}
