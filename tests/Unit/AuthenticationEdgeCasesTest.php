<?php

namespace Tests\Unit;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Unit Tests: Authentication Edge Cases
 * 
 * This test suite validates edge cases and error conditions in the authentication system:
 * - Registration with duplicate email/username
 * - Login with invalid credentials
 * - Logout without token
 * - FCM token replacement on multiple logins
 */
class AuthenticationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Test registration with duplicate email
     */
    public function registration_fails_with_duplicate_email()
    {
        // Create an existing user
        User::factory()->create([
            'email' => 'existing@example.com',
            'username' => 'existinguser',
        ]);

        // Attempt to register with the same email but different username
        $response = $this->postJson('/api/register', [
            'username' => 'newuser',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJsonFragment([
            'email' => ['This email address is already registered'],
        ]);
    }

    /**
     * @test
     * Test registration with duplicate username
     */
    public function registration_fails_with_duplicate_username()
    {
        // Create an existing user
        User::factory()->create([
            'email' => 'existing@example.com',
            'username' => 'existinguser',
        ]);

        // Attempt to register with the same username but different email
        $response = $this->postJson('/api/register', [
            'username' => 'existinguser',
            'email' => 'newemail@example.com',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
        $response->assertJsonFragment([
            'username' => ['This username is already taken'],
        ]);
    }

    /**
     * @test
     * Test registration with both duplicate email and username
     */
    public function registration_fails_with_duplicate_email_and_username()
    {
        // Create an existing user
        User::factory()->create([
            'email' => 'existing@example.com',
            'username' => 'existinguser',
        ]);

        // Attempt to register with both the same email and username
        $response = $this->postJson('/api/register', [
            'username' => 'existinguser',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'username']);
    }

    /**
     * @test
     * Test login with invalid email
     */
    public function login_fails_with_invalid_email()
    {
        // Create a user
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt to login with non-existent email
        $response = $this->postJson('/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => [
                'email' => ['The provided credentials are incorrect.'],
            ],
        ]);
    }

    /**
     * @test
     * Test login with invalid password
     */
    public function login_fails_with_invalid_password()
    {
        // Create a user
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => Hash::make('correctpassword'),
        ]);

        // Attempt to login with wrong password
        $response = $this->postJson('/api/login', [
            'email' => 'valid@example.com',
            'password' => 'wrongpassword',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => [
                'email' => ['The provided credentials are incorrect.'],
            ],
        ]);
    }

    /**
     * @test
     * Test login with missing credentials
     */
    public function login_fails_with_missing_credentials()
    {
        // Attempt to login without email and password
        $response = $this->postJson('/api/login', [
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * @test
     * Test logout without authentication token
     */
    public function logout_fails_without_authentication_token()
    {
        // Attempt to logout without providing Authorization header
        $response = $this->postJson('/api/logout', [
            'device_id' => 'device_test',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * @test
     * Test logout with invalid authentication token
     */
    public function logout_fails_with_invalid_authentication_token()
    {
        // Attempt to logout with invalid token
        $response = $this->withHeader('Authorization', 'Bearer invalid_token_here')
            ->postJson('/api/logout', [
                'device_id' => 'device_test',
            ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * @test
     * Test logout with expired/revoked token
     */
    public function logout_fails_with_revoked_token()
    {
        // Create a user and login
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => 'fcm_token_test',
            'device_id' => 'device_test',
        ]);

        $token = $loginResponse->json('token');

        // Logout once (revokes token)
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout', ['device_id' => 'device_test'])
            ->assertStatus(200);

        // Clear cached authentication
        $this->app->forgetInstance('auth');

        // Attempt to logout again with the same (now revoked) token
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout', ['device_id' => 'device_test']);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * @test
     * Test FCM token replacement on multiple logins from same device
     */
    public function fcm_token_is_replaced_on_multiple_logins_from_same_device()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $deviceId = 'test_device_123';

        // First login with initial FCM token
        $firstFcmToken = 'fcm_token_first';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $firstFcmToken,
            'device_id' => $deviceId,
        ])->assertStatus(200);

        // Verify first token is stored
        $storedToken = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();
        
        $this->assertNotNull($storedToken);
        $this->assertEquals($firstFcmToken, $storedToken->token);
        $this->assertEquals('active', $storedToken->status);

        // Second login with different FCM token (same device)
        $secondFcmToken = 'fcm_token_second';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $secondFcmToken,
            'device_id' => $deviceId,
        ])->assertStatus(200);

        // Verify token is replaced (not duplicated)
        $tokenCount = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->count();
        
        $this->assertEquals(1, $tokenCount, 'Should only have one token per user-device combination');

        $updatedToken = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();
        
        $this->assertEquals($secondFcmToken, $updatedToken->token, 'Token should be updated to new value');
        $this->assertEquals('active', $updatedToken->status);

        // Third login with yet another FCM token (same device)
        $thirdFcmToken = 'fcm_token_third';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $thirdFcmToken,
            'device_id' => $deviceId,
        ])->assertStatus(200);

        // Verify token is replaced again
        $finalTokenCount = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->count();
        
        $this->assertEquals(1, $finalTokenCount, 'Should still only have one token per user-device combination');

        $finalToken = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();
        
        $this->assertEquals($thirdFcmToken, $finalToken->token, 'Token should be updated to latest value');
        $this->assertEquals('active', $finalToken->status);
    }

    /**
     * @test
     * Test FCM token replacement on multiple logins from different devices
     */
    public function fcm_tokens_are_separate_for_different_devices()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // Login from device 1
        $device1Id = 'device_1';
        $device1FcmToken = 'fcm_token_device_1';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $device1FcmToken,
            'device_id' => $device1Id,
        ])->assertStatus(200);

        // Login from device 2
        $device2Id = 'device_2';
        $device2FcmToken = 'fcm_token_device_2';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $device2FcmToken,
            'device_id' => $device2Id,
        ])->assertStatus(200);

        // Verify both devices have separate FCM tokens
        $device1Token = FcmToken::where('user_id', $user->id)
            ->where('device_id', $device1Id)
            ->first();
        
        $device2Token = FcmToken::where('user_id', $user->id)
            ->where('device_id', $device2Id)
            ->first();

        $this->assertNotNull($device1Token);
        $this->assertNotNull($device2Token);
        $this->assertEquals($device1FcmToken, $device1Token->token);
        $this->assertEquals($device2FcmToken, $device2Token->token);
        $this->assertEquals('active', $device1Token->status);
        $this->assertEquals('active', $device2Token->status);

        // Verify total token count for user
        $totalTokens = FcmToken::where('user_id', $user->id)->count();
        $this->assertEquals(2, $totalTokens, 'User should have 2 FCM tokens (one per device)');

        // Login again from device 1 with new FCM token
        $device1NewFcmToken = 'fcm_token_device_1_new';
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $device1NewFcmToken,
            'device_id' => $device1Id,
        ])->assertStatus(200);

        // Verify device 1 token is updated but device 2 token remains unchanged
        $device1Token->refresh();
        $device2Token->refresh();

        $this->assertEquals($device1NewFcmToken, $device1Token->token);
        $this->assertEquals($device2FcmToken, $device2Token->token);
        
        // Verify still only 2 tokens total
        $totalTokens = FcmToken::where('user_id', $user->id)->count();
        $this->assertEquals(2, $totalTokens, 'User should still have 2 FCM tokens');
    }

    /**
     * @test
     * Test logout without device_id deactivates all FCM tokens
     */
    public function logout_without_device_id_deactivates_all_fcm_tokens()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // Login from multiple devices
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => 'fcm_token_device_1',
            'device_id' => 'device_1',
        ])->assertStatus(200);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => 'fcm_token_device_2',
            'device_id' => 'device_2',
        ]);

        $token = $loginResponse->json('token');

        // Verify both tokens are active
        $this->assertEquals(2, FcmToken::where('user_id', $user->id)->where('status', 'active')->count());

        // Logout without device_id
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Verify all tokens are deactivated
        $this->assertEquals(0, FcmToken::where('user_id', $user->id)->where('status', 'active')->count());
        $this->assertEquals(2, FcmToken::where('user_id', $user->id)->where('status', 'inactive')->count());
    }

    /**
     * @test
     * Test logout with device_id deactivates only that device's FCM token
     */
    public function logout_with_device_id_deactivates_only_that_device_fcm_token()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // Login from device 1
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => 'fcm_token_device_1',
            'device_id' => 'device_1',
        ])->assertStatus(200);

        // Login from device 2
        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => 'fcm_token_device_2',
            'device_id' => 'device_2',
        ]);

        $token = $loginResponse->json('token');

        // Verify both tokens are active
        $this->assertEquals(2, FcmToken::where('user_id', $user->id)->where('status', 'active')->count());

        // Logout from device 2 only
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout', ['device_id' => 'device_2'])
            ->assertStatus(200);

        // Verify device 2 token is inactive but device 1 token is still active
        $device1Token = FcmToken::where('user_id', $user->id)->where('device_id', 'device_1')->first();
        $device2Token = FcmToken::where('user_id', $user->id)->where('device_id', 'device_2')->first();

        $this->assertEquals('active', $device1Token->status);
        $this->assertEquals('inactive', $device2Token->status);
    }
}
