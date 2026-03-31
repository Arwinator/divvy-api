<?php

namespace Tests\Unit;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = new NotificationService();
    }

    /**
     * @test
     * Test notification sent to correct FCM token
     */
    public function notification_sent_to_correct_fcm_token()
    {
        // Mock HTTP to capture the request
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();
        $expectedToken = 'fcm_token_correct_' . uniqid();

        // Create the correct FCM token
        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_correct',
            'token' => $expectedToken,
            'status' => 'active',
        ]);

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            ['type' => 'test']
        );

        // Verify HTTP request was made with correct token
        Http::assertSent(function ($request) use ($expectedToken) {
            $body = json_decode($request->body(), true);
            return isset($body['message']['token']) 
                && $body['message']['token'] === $expectedToken
                && $body['message']['notification']['title'] === 'Test Title'
                && $body['message']['notification']['body'] === 'Test Body'
                && $body['message']['data']['type'] === 'test';
        });
    }

    /**
     * @test
     * Test notification with invalid token
     */
    public function notification_with_invalid_token_logs_error()
    {
        // Mock HTTP to return error for invalid token
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'Requested entity was not found.',
                    'status' => 'NOT_FOUND',
                ]
            ], 404),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        // Mock Log to capture error and info
        Log::shouldReceive('channel')
            ->with('notification')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('FCM notification sent successfully', \Mockery::any())
            ->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->with('FCM notification failed', \Mockery::on(function ($context) {
                return isset($context['status']) 
                    && $context['status'] === 404
                    && isset($context['token']);
            }))
            ->once();
        Log::shouldReceive('info')
            ->with('Notification sent', \Mockery::on(function ($context) {
                return isset($context['user_id']) && isset($context['title']);
            }))
            ->once();

        $user = User::factory()->create();
        $invalidToken = 'fcm_token_invalid_' . uniqid();

        // Create FCM token with invalid token
        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_invalid',
            'token' => $invalidToken,
            'status' => 'active',
        ]);

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Verify HTTP request was made
        Http::assertSent(function ($request) use ($invalidToken) {
            $body = json_decode($request->body(), true);
            return isset($body['message']['token']) 
                && $body['message']['token'] === $invalidToken;
        });
    }

    /**
     * @test
     * Test notification when user has no active tokens
     */
    public function notification_when_user_has_no_active_tokens()
    {
        // Mock HTTP to ensure no requests are made
        Http::fake();

        // Mock Log to capture info message
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

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Verify no HTTP requests were made
        Http::assertNothingSent();
    }

    /**
     * @test
     * Test notification payload structure
     */
    public function notification_payload_structure_is_correct()
    {
        // Mock HTTP to capture the request
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();
        $fcmToken = 'fcm_token_' . uniqid();

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_test',
            'token' => $fcmToken,
            'status' => 'active',
        ]);

        $title = 'Test Notification Title';
        $body = 'Test notification body content';
        $data = [
            'type' => 'test_type',
            'id' => 123,
            'action' => 'view',
        ];

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            $title,
            $body,
            $data
        );

        // Verify payload structure
        Http::assertSent(function ($request) use ($fcmToken, $title, $body, $data) {
            $payload = json_decode($request->body(), true);
            
            // Verify message structure
            $this->assertArrayHasKey('message', $payload);
            $message = $payload['message'];
            
            // Verify token
            $this->assertArrayHasKey('token', $message);
            $this->assertEquals($fcmToken, $message['token']);
            
            // Verify notification
            $this->assertArrayHasKey('notification', $message);
            $this->assertEquals($title, $message['notification']['title']);
            $this->assertEquals($body, $message['notification']['body']);
            
            // Verify data payload
            $this->assertArrayHasKey('data', $message);
            $this->assertEquals($data['type'], $message['data']['type']);
            $this->assertEquals($data['id'], $message['data']['id']);
            $this->assertEquals($data['action'], $message['data']['action']);
            
            // Verify platform-specific settings
            $this->assertArrayHasKey('android', $message);
            $this->assertEquals('high', $message['android']['priority']);
            
            $this->assertArrayHasKey('apns', $message);
            $this->assertEquals('10', $message['apns']['headers']['apns-priority']);
            
            return true;
        });
    }

    /**
     * @test
     * Test notification with empty data payload
     */
    public function notification_with_empty_data_payload()
    {
        // Mock HTTP
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_test',
            'token' => 'fcm_token_test',
            'status' => 'active',
        ]);

        // Send notification with empty data
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Verify request was sent with empty data object
        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $this->assertArrayHasKey('data', $payload['message']);
            $this->assertIsArray($payload['message']['data']);
            $this->assertEmpty($payload['message']['data']);
            return true;
        });
    }

    /**
     * @test
     * Test notification selects most recent active token
     */
    public function notification_selects_most_recent_active_token()
    {
        // Mock HTTP
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        // Create older token
        $olderToken = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_old',
            'token' => 'fcm_token_old',
            'status' => 'active',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        // Create newer token
        $newerToken = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_new',
            'token' => 'fcm_token_new',
            'status' => 'active',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Verify newer token was used
        Http::assertSent(function ($request) use ($newerToken) {
            $payload = json_decode($request->body(), true);
            return $payload['message']['token'] === $newerToken->token;
        });
    }

    /**
     * @test
     * Test notification logs success
     */
    public function notification_logs_success()
    {
        // Mock HTTP
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        // Mock Log
        Log::shouldReceive('channel')
            ->with('notification')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('FCM notification sent successfully', \Mockery::on(function ($context) {
                return isset($context['token']) && isset($context['title']);
            }))
            ->once();
        Log::shouldReceive('info')
            ->with('Notification sent', \Mockery::on(function ($context) {
                return isset($context['user_id']) && isset($context['title']);
            }))
            ->once();

        $user = User::factory()->create();

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_test',
            'token' => 'fcm_token_test',
            'status' => 'active',
        ]);

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Assertions are in the Log mock expectations
    }

    /**
     * @test
     * Test profile update notification to all active devices
     */
    public function profile_update_notification_sent_to_all_active_devices()
    {
        // Mock HTTP
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        // Create multiple active tokens
        $token1 = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_1',
            'token' => 'fcm_token_1',
            'status' => 'active',
        ]);

        $token2 = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_2',
            'token' => 'fcm_token_2',
            'status' => 'active',
        ]);

        $token3 = FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_3',
            'token' => 'fcm_token_3',
            'status' => 'inactive', // Should not receive notification
        ]);

        // Send profile update notification
        $this->notificationService->sendProfileUpdateNotification($user, ['username', 'email']);

        // Verify 2 requests were sent (only to active tokens)
        Http::assertSentCount(2);

        // Verify both active tokens received notification
        Http::assertSent(function ($request) use ($token1) {
            $payload = json_decode($request->body(), true);
            return isset($payload['message']['token']) && $payload['message']['token'] === $token1->token;
        });

        Http::assertSent(function ($request) use ($token2) {
            $payload = json_decode($request->body(), true);
            return isset($payload['message']['token']) && $payload['message']['token'] === $token2->token;
        });
    }

    /**
     * @test
     * Test profile update notification body formatting
     */
    public function profile_update_notification_body_formatting()
    {
        // Mock HTTP
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $user = User::factory()->create();

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_test',
            'token' => 'fcm_token_test',
            'status' => 'active',
        ]);

        // Test single field change
        $this->notificationService->sendProfileUpdateNotification($user, ['username']);
        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            return $payload['message']['notification']['body'] === 'Your username has been updated successfully.';
        });

        // Test two field changes
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);
        $this->notificationService->sendProfileUpdateNotification($user, ['username', 'email']);
        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            return $payload['message']['notification']['body'] === 'Your username and email have been updated successfully.';
        });

        // Test three field changes
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/test'], 200),
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);
        $this->notificationService->sendProfileUpdateNotification($user, ['username', 'email', 'password']);
        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            return $payload['message']['notification']['body'] === 'Your username, email, and password have been updated successfully.';
        });
    }

    /**
     * @test
     * Test notification when Firebase credentials are missing
     */
    public function notification_when_firebase_credentials_missing()
    {
        // Temporarily set invalid credentials path
        config(['firebase.credentials' => 'invalid/path/to/credentials.json']);

        // Mock Log
        Log::shouldReceive('channel')
            ->with('notification')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Firebase credentials file not found', \Mockery::on(function ($context) {
                return isset($context['path']);
            }))
            ->once();
        Log::shouldReceive('info')
            ->with('Notification sent', \Mockery::on(function ($context) {
                return isset($context['user_id']) && isset($context['title']);
            }))
            ->once();

        $user = User::factory()->create();

        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_test',
            'token' => 'fcm_token_test',
            'status' => 'active',
        ]);

        // Send notification
        $this->notificationService->sendNotification(
            $user->id,
            'Test Title',
            'Test Body',
            []
        );

        // Assertions are in the Log mock expectations
    }
}
