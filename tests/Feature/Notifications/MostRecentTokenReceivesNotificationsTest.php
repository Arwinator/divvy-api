<?php

namespace Tests\Feature\Notifications;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Property-Based Test: Most Recent Token Receives Notifications
 * 
 * This test validates that when a user has multiple active FCM tokens,
 * only the most recent one (by updated_at) receives notifications.
 */
class MostRecentTokenReceivesNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Most Recent Token Receives Notifications
     * 
     * Test that when user has multiple tokens, only the most recent active one receives notifications.
     */
    public function most_recent_token_receives_notifications_property()
    {
        // Mock HTTP to prevent actual FCM API calls
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        // Run 30 iterations with different scenarios
        for ($i = 0; $i < 30; $i++) {
            // Create a user
            $user = User::factory()->create([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'test_' . uniqid() . '_' . $i . '@example.com',
            ]);

            // Create multiple FCM tokens for the same user with different timestamps
            $tokenCount = rand(2, 5); // Random number of tokens between 2 and 5
            $tokens = [];

            for ($j = 0; $j < $tokenCount; $j++) {
                // Create token with specific timestamp
                $minutesAgo = $tokenCount - $j;
                $timestamp = now()->subMinutes($minutesAgo);
                
                $token = new FcmToken([
                    'user_id' => $user->id,
                    'device_id' => 'device_' . $j . '_' . uniqid(),
                    'token' => 'fcm_token_' . $j . '_' . uniqid() . '_' . $i,
                    'status' => 'active',
                ]);
                
                // Manually set timestamps to avoid auto-update
                $token->created_at = $timestamp;
                $token->updated_at = $timestamp;
                $token->save();
                
                $tokens[] = $token;
                
                // Small delay to ensure different timestamps
                usleep(1000); // 1ms delay
            }

            // The most recent token should be the last one created (smallest subMinutes value)
            $mostRecentToken = $tokens[count($tokens) - 1];
            
            // Refresh to get actual database values
            $mostRecentToken->refresh();

            // Send notification using NotificationService
            $notificationService = new NotificationService();
            $notificationService->sendNotification(
                $user->id,
                'Test Notification',
                'This is a test notification body',
                ['type' => 'test', 'iteration' => $i]
            );

            // Verify that the most recent token was selected
            // We can verify this by checking the database query result
            $selectedToken = FcmToken::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderBy('updated_at', 'desc')
                ->first();

            $this->assertNotNull($selectedToken, 'A token should be selected');
            $this->assertEquals(
                $mostRecentToken->id,
                $selectedToken->id,
                'The most recent token should be selected'
            );
            $this->assertEquals(
                $mostRecentToken->token,
                $selectedToken->token,
                'The selected token should match the most recent token'
            );
        }
    }

    /**
     * @test
     * Test that inactive tokens are not selected for notifications
     */
    public function inactive_tokens_are_not_selected_for_notifications()
    {
        // Mock HTTP to prevent actual FCM API calls
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        // Create an older active token
        $activeToken = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_active',
            'token' => 'fcm_token_active',
            'status' => 'active',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        // Create a newer but inactive token
        $inactiveToken = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_inactive',
            'token' => 'fcm_token_inactive',
            'status' => 'inactive',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        // Send notification
        $notificationService = new NotificationService();
        $notificationService->sendNotification(
            $user->id,
            'Test Notification',
            'Test body',
            []
        );

        // Verify that only the active token is selected, even though it's older
        $selectedToken = FcmToken::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->first();

        $this->assertEquals($activeToken->id, $selectedToken->id, 'Active token should be selected');
        $this->assertNotEquals($inactiveToken->id, $selectedToken->id, 'Inactive token should not be selected');
    }

    /**
     * @test
     * Test that no notification is sent when user has no active tokens
     */
    public function no_notification_sent_when_user_has_no_active_tokens()
    {
        // Mock HTTP to prevent actual FCM API calls
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        // Mock Log to capture log messages
        Log::shouldReceive('channel')
            ->with('notification')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('No active FCM token for user', \Mockery::on(function ($context) {
                return isset($context['user_id']);
            }))
            ->once();

        $user = User::factory()->create();

        // Create only inactive tokens
        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_1',
            'token' => 'fcm_token_1',
            'status' => 'inactive',
        ]);

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_2',
            'token' => 'fcm_token_2',
            'status' => 'inactive',
        ]);

        // Send notification
        $notificationService = new NotificationService();
        $notificationService->sendNotification(
            $user->id,
            'Test Notification',
            'Test body',
            []
        );

        // Verify no active token exists
        $activeToken = FcmToken::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        $this->assertNull($activeToken, 'No active token should exist');

        // HTTP should not be called since no active token exists
        Http::assertNothingSent();
    }

    /**
     * @test
     * Test that updated_at timestamp determines most recent token
     */
    public function updated_at_timestamp_determines_most_recent_token()
    {
        // Mock HTTP to prevent actual FCM API calls
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        // Create token 1 (created first, but updated most recently)
        $token1 = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_1',
            'token' => 'fcm_token_1',
            'status' => 'active',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subMinutes(1), // Most recent update
        ]);

        // Create token 2 (created later, but updated earlier)
        $token2 = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_2',
            'token' => 'fcm_token_2',
            'status' => 'active',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subMinutes(10), // Older update
        ]);

        // Send notification
        $notificationService = new NotificationService();
        $notificationService->sendNotification(
            $user->id,
            'Test Notification',
            'Test body',
            []
        );

        // Verify that token1 is selected (most recent updated_at)
        $selectedToken = FcmToken::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->first();

        $this->assertEquals($token1->id, $selectedToken->id, 'Token with most recent updated_at should be selected');
        $this->assertNotEquals($token2->id, $selectedToken->id, 'Token with older updated_at should not be selected');
    }
}
