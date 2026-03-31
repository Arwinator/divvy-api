<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Protected Endpoint Authentication Requirement
 * 
 * This test validates that all protected endpoints reject requests without valid token (401).
 * Tests the authentication requirement across all API endpoints that require authentication.
 */
class ProtectedEndpointAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Protected Endpoint Authentication Requirement
     * 
     * Test that all protected endpoints reject requests without valid token,
     * returning 401 Unauthorized status.
     */
    public function protected_endpoints_require_authentication()
    {
        // Create test data for endpoints that need existing resources
        $user = User::factory()->create([
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
        ]);
        
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'creator_id' => $user->id,
        ]);
        $group->members()->attach($user->id);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'title' => 'Test Bill',
            'total_amount' => 1000.00,
            'bill_date' => now()->toDateString(),
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 1000.00,
            'status' => 'unpaid',
        ]);

        // Define all protected endpoints with their HTTP methods
        $protectedEndpoints = [
            // Authentication endpoints
            ['method' => 'POST', 'uri' => '/api/logout', 'data' => ['device_id' => 'test_device']],
            ['method' => 'PUT', 'uri' => '/api/profile', 'data' => ['username' => 'newname']],
            
            // Group management endpoints
            ['method' => 'POST', 'uri' => '/api/groups', 'data' => ['name' => 'New Group']],
            ['method' => 'GET', 'uri' => '/api/groups', 'data' => []],
            ['method' => 'POST', 'uri' => "/api/groups/{$group->id}/invitations", 'data' => ['identifier' => 'test@example.com']],
            ['method' => 'DELETE', 'uri' => "/api/groups/{$group->id}/members/{$user->id}", 'data' => []],
            ['method' => 'POST', 'uri' => "/api/groups/{$group->id}/leave", 'data' => []],
            
            // Invitation endpoints
            ['method' => 'GET', 'uri' => '/api/invitations', 'data' => []],
            ['method' => 'POST', 'uri' => '/api/invitations/1/accept', 'data' => []],
            ['method' => 'POST', 'uri' => '/api/invitations/1/decline', 'data' => []],
            
            // Bill management endpoints
            ['method' => 'POST', 'uri' => '/api/bills', 'data' => [
                'group_id' => $group->id,
                'title' => 'Test Bill',
                'total_amount' => 1000.00,
                'bill_date' => now()->toDateString(),
                'split_type' => 'equal',
            ]],
            ['method' => 'GET', 'uri' => '/api/bills', 'data' => []],
            ['method' => 'GET', 'uri' => "/api/bills/{$bill->id}", 'data' => []],
            
            // Payment endpoints
            ['method' => 'POST', 'uri' => "/api/shares/{$share->id}/pay", 'data' => ['payment_method' => 'gcash']],
            
            // Transaction history endpoints
            ['method' => 'GET', 'uri' => '/api/transactions', 'data' => []],
            
            // Sync endpoints
            ['method' => 'POST', 'uri' => '/api/sync', 'data' => ['operations' => []]],
            ['method' => 'GET', 'uri' => '/api/sync/timestamp', 'data' => []],
        ];

        // Test each endpoint without authentication token
        foreach ($protectedEndpoints as $endpoint) {
            $method = strtolower($endpoint['method']);
            $uri = $endpoint['uri'];
            $data = $endpoint['data'];

            // Make request without Authorization header
            $response = $this->json($method, $uri, $data);

            // Assert 401 Unauthorized status
            $this->assertEquals(
                401,
                $response->status(),
                "Endpoint {$endpoint['method']} {$uri} should return 401 without authentication token"
            );

            // Verify error message structure
            $response->assertJsonStructure(['message']);
        }
    }

    /**
     * @test
     * Invalid Token Rejection
     * 
     * Test that protected endpoints reject requests with invalid tokens,
     * returning 401 Unauthorized status.
     */
    public function protected_endpoints_reject_invalid_tokens()
    {
        // Create test data
        $user = User::factory()->create([
            'username' => 'testuser_' . uniqid(),
            'email' => 'test_' . uniqid() . '@example.com',
        ]);
        
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'creator_id' => $user->id,
        ]);

        // Test with various invalid token formats
        $invalidTokens = [
            'invalid_token_123',
            'Bearer invalid_token',
            '12345',
            'expired_token_xyz',
            '',
        ];

        foreach ($invalidTokens as $invalidToken) {
            // Test a sample of endpoints with invalid token
            $response = $this->withHeader('Authorization', 'Bearer ' . $invalidToken)
                ->getJson('/api/groups');

            $this->assertEquals(
                401,
                $response->status(),
                "Endpoint should return 401 with invalid token: {$invalidToken}"
            );
        }
    }

    /**
     * @test
     * Revoked Token Rejection
     * 
     * Test that protected endpoints reject requests with revoked tokens
     * (after logout), returning 401 Unauthorized status.
     */
    public function protected_endpoints_reject_revoked_tokens()
    {
        // Run multiple iterations to verify property holds
        for ($i = 0; $i < 20; $i++) {
            // Create user and login
            $userData = [
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'test_' . uniqid() . '_' . $i . '@example.com',
                'password' => 'SecurePass123!@#',
                'password_confirmation' => 'SecurePass123!@#',
                'fcm_token' => 'fcm_token_' . uniqid() . '_' . $i,
                'device_id' => 'device_' . uniqid() . '_' . $i,
            ];

            $registerResponse = $this->postJson('/api/register', $userData);
            $registerResponse->assertStatus(201);
            $token = $registerResponse->json('token');

            // Logout to revoke token
            $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/logout', ['device_id' => $userData['device_id']]);
            $logoutResponse->assertStatus(200);

            // Clear cached authentication
            $this->app->forgetInstance('auth');

            // Attempt to use revoked token on multiple endpoints
            $endpoints = [
                ['method' => 'GET', 'uri' => '/api/groups'],
                ['method' => 'GET', 'uri' => '/api/bills'],
                ['method' => 'GET', 'uri' => '/api/transactions'],
            ];

            foreach ($endpoints as $endpoint) {
                $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                    ->json(strtolower($endpoint['method']), $endpoint['uri']);

                $this->assertEquals(
                    401,
                    $response->status(),
                    "Endpoint {$endpoint['method']} {$endpoint['uri']} should return 401 with revoked token"
                );
            }
        }
    }
}
