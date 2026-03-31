<?php

namespace Tests\Feature\Auth;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: FCM Token Lifecycle Management
 * 
 * This test validates the complete FCM token lifecycle:
 * - FCM token is registered on login
 * - FCM token is replaced for same user-device combination
 * - FCM token is removed (set to inactive) on logout
 */
class FcmTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * FCM Token Lifecycle Management
     * 
     * Test that FCM token is registered on login, replaced for same user-device,
     * and removed on logout.
     */
    public function fcm_token_lifecycle_management_property()
    {
        // Run 50 iterations with different users and devices to verify property holds
        for ($i = 0; $i < 50; $i++) {
            // Generate random user data
            $userData = [
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'test_' . uniqid() . '_' . $i . '@example.com',
                'password' => 'SecurePass123!@#',
                'password_confirmation' => 'SecurePass123!@#',
                'fcm_token' => 'fcm_token_' . uniqid() . '_' . $i,
                'device_id' => 'device_' . uniqid() . '_' . $i,
            ];

            // Step 1: Register user (which also registers FCM token)
            $registerResponse = $this->postJson('/api/register', $userData);
            $registerResponse->assertStatus(201);

            $userId = $registerResponse->json('user.id');
            $token = $registerResponse->json('token');

            // Verify FCM token is registered on registration/login
            $fcmToken = FcmToken::where('user_id', $userId)
                ->where('device_id', $userData['device_id'])
                ->first();

            $this->assertNotNull($fcmToken, 'FCM token should be registered on registration');
            $this->assertEquals($userData['fcm_token'], $fcmToken->token, 'FCM token should match the provided token');
            $this->assertEquals('active', $fcmToken->status, 'FCM token should be active after registration');

            // Step 2: Logout (FCM token inactive verification is tested separately)
            $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/logout', ['device_id' => $userData['device_id']]);
            $logoutResponse->assertStatus(200);

            // Step 3: Login again with SAME device_id but DIFFERENT fcm_token
            $newFcmToken = 'new_fcm_token_' . uniqid() . '_' . $i;
            $loginData = [
                'email' => $userData['email'],
                'password' => $userData['password'],
                'fcm_token' => $newFcmToken,
                'device_id' => $userData['device_id'], // Same device
            ];

            $loginResponse = $this->postJson('/api/login', $loginData);
            $loginResponse->assertStatus(200);

            // Verify FCM token is REPLACED for same user-device combination
            $updatedFcmToken = FcmToken::where('user_id', $userId)
                ->where('device_id', $userData['device_id'])
                ->first();

            $this->assertNotNull($updatedFcmToken, 'FCM token should exist after login');
            $this->assertEquals($newFcmToken, $updatedFcmToken->token, 'FCM token should be replaced with new token');
            $this->assertEquals('active', $updatedFcmToken->status, 'FCM token should be active after login');

            // Verify only ONE FCM token exists for this user-device combination
            $tokenCount = FcmToken::where('user_id', $userId)
                ->where('device_id', $userData['device_id'])
                ->count();
            $this->assertEquals(1, $tokenCount, 'Only one FCM token should exist per user-device combination');

            // Step 4: Test multiple devices for same user
            $device2Id = 'device2_' . uniqid() . '_' . $i;
            $device2FcmToken = 'fcm_token_device2_' . uniqid() . '_' . $i;

            $loginDevice2Data = [
                'email' => $userData['email'],
                'password' => $userData['password'],
                'fcm_token' => $device2FcmToken,
                'device_id' => $device2Id, // Different device
            ];

            $loginDevice2Response = $this->postJson('/api/login', $loginDevice2Data);
            $loginDevice2Response->assertStatus(200);

            // Verify both devices have separate FCM tokens
            $device1Token = FcmToken::where('user_id', $userId)
                ->where('device_id', $userData['device_id'])
                ->first();
            $device2Token = FcmToken::where('user_id', $userId)
                ->where('device_id', $device2Id)
                ->first();

            $this->assertNotNull($device1Token, 'Device 1 FCM token should still exist');
            $this->assertNotNull($device2Token, 'Device 2 FCM token should exist');
            $this->assertEquals('active', $device1Token->status, 'Device 1 FCM token should be active');
            $this->assertEquals('active', $device2Token->status, 'Device 2 FCM token should be active');
            $this->assertNotEquals($device1Token->token, $device2Token->token, 'Different devices should have different FCM tokens');

            // Verify total token count for user
            $totalTokens = FcmToken::where('user_id', $userId)->count();
            $this->assertEquals(2, $totalTokens, 'User should have 2 FCM tokens (one per device)');

            // Step 5: Logout from device 1 only (status verification tested separately)
            $device1AuthToken = $loginResponse->json('token');
            $logoutDevice1Response = $this->withHeader('Authorization', 'Bearer ' . $device1AuthToken)
                ->postJson('/api/logout', ['device_id' => $userData['device_id']]);
            $logoutDevice1Response->assertStatus(200);

            // Step 6: Test multiple users on same device
            $user2Data = [
                'username' => 'testuser2_' . uniqid() . '_' . $i,
                'email' => 'test2_' . uniqid() . '_' . $i . '@example.com',
                'password' => 'SecurePass456!@#',
                'password_confirmation' => 'SecurePass456!@#',
                'fcm_token' => 'fcm_token_user2_' . uniqid() . '_' . $i,
                'device_id' => $userData['device_id'], // Same device as user 1
            ];

            $registerUser2Response = $this->postJson('/api/register', $user2Data);
            $registerUser2Response->assertStatus(201);

            $user2Id = $registerUser2Response->json('user.id');

            // Verify both users have separate FCM tokens on same device
            $user1DeviceToken = FcmToken::where('user_id', $userId)
                ->where('device_id', $userData['device_id'])
                ->first();
            $user2DeviceToken = FcmToken::where('user_id', $user2Id)
                ->where('device_id', $userData['device_id'])
                ->first();

            $this->assertNotNull($user1DeviceToken, 'User 1 FCM token should exist');
            $this->assertNotNull($user2DeviceToken, 'User 2 FCM token should exist');
            $this->assertNotEquals($user1DeviceToken->user_id, $user2DeviceToken->user_id, 'Different users should have different user_ids');
            $this->assertEquals($userData['device_id'], $user1DeviceToken->device_id, 'User 1 should have correct device_id');
            $this->assertEquals($userData['device_id'], $user2DeviceToken->device_id, 'User 2 should have correct device_id');
        }
    }

    /**
     * @test
     * Test FCM token replacement on multiple logins from same device
     */
    public function fcm_token_is_replaced_on_multiple_logins_from_same_device()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $deviceId = 'test_device_123';

        // First login
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
        $this->assertEquals($firstFcmToken, $storedToken->token);
        $this->assertEquals('active', $storedToken->status);

        // Second login with different FCM token
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
    }

    /**
     * @test
     * Test FCM token status is set to inactive on logout
     */
    public function fcm_token_status_is_set_to_inactive_on_logout()
    {
        // Create a user and login
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $deviceId = 'test_device_456';
        $fcmToken = 'fcm_token_test';

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
            'fcm_token' => $fcmToken,
            'device_id' => $deviceId,
        ]);

        $token = $loginResponse->json('token');

        // Verify token is active
        $storedToken = FcmToken::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();
        $this->assertEquals('active', $storedToken->status);

        // Logout
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout', ['device_id' => $deviceId])
            ->assertStatus(200);

        // Verify token status is set to inactive
        $storedToken->refresh();
        $this->assertEquals('inactive', $storedToken->status, 'FCM token should be inactive after logout');
    }
}
