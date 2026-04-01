<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Auth\Credentials\ServiceAccountCredentials;

class NotificationService
{
    /**
     * Send a generic notification to a user.
     *
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array $data
     * @return void
     */
    public function sendNotification(int $userId, string $title, string $body, array $data = [])
    {
        // Get the most recent active FCM token for this user
        $fcmToken = \App\Models\FcmToken::where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$fcmToken) {
            Log::channel('notification')->info('No active FCM token for user', [
                'user_id' => $userId,
            ]);
            return;
        }

        // Send notification
        $this->sendFcmNotification(
            $fcmToken->token,
            $title,
            $body,
            $data
        );

        Log::channel('notification')->info('Notification sent', [
            'user_id' => $userId,
            'title' => $title,
        ]);
    }

    /**
     * Send profile update notification to all user's active devices.
     *
     * @param User $user
     * @param array $changes - Array of changed fields (e.g., ['username', 'email', 'password'])
     * @return void
     */
    public function sendProfileUpdateNotification(User $user, array $changes)
    {
        // Get all active FCM tokens for this user
        $fcmTokens = $user->fcmTokens()->where('status', 'active')->get();

        if ($fcmTokens->isEmpty()) {
            Log::channel('notification')->info('No active FCM tokens for user', [
                'user_id' => $user->id,
            ]);
            return;
        }

        // Build notification message
        $title = 'Profile Updated';
        $body = $this->buildNotificationBody($changes);

        // Send notification to each device
        foreach ($fcmTokens as $fcmToken) {
            $this->sendFcmNotification(
                $fcmToken->token,
                $title,
                $body,
                [
                    'type' => 'profile_update',
                    'user_id' => $user->id,
                    'changes' => implode(', ', $changes),
                ]
            );
        }
    }

    /**
     * Build notification body based on changed fields.
     *
     * @param array $changes
     * @return string
     */
    private function buildNotificationBody(array $changes): string
    {
        $changeCount = count($changes);

        if ($changeCount === 1) {
            $field = $changes[0];
            return "Your {$field} has been updated successfully.";
        }

        if ($changeCount === 2) {
            return "Your {$changes[0]} and {$changes[1]} have been updated successfully.";
        }

        // 3 or more changes
        $lastChange = array_pop($changes);
        $changesList = implode(', ', $changes);
        return "Your {$changesList}, and {$lastChange} have been updated successfully.";
    }

    /**
     * Send FCM notification using V1 API with service account.
     *
     * @param string $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return void
     */
    private function sendFcmNotification(string $fcmToken, string $title, string $body, array $data = [])
    {
        $credentialsPath = config('firebase.credentials');

        if (!$credentialsPath || (!app()->environment('testing') && !file_exists(base_path($credentialsPath)))) {
            Log::channel('notification')->warning('Firebase credentials file not found', [
                'path' => $credentialsPath,
            ]);
            return;
        }

        try {
            // Get OAuth2 access token
            $accessToken = $this->getAccessToken($credentialsPath);
            
            if (!$accessToken) {
                Log::channel('notification')->error('Failed to get Firebase access token');
                return;
            }

            // Get project ID from credentials or use test project ID
            $projectId = 'test-project';
            if (!app()->environment('testing')) {
                $credentials = json_decode(file_get_contents(base_path($credentialsPath)), true);
                $projectId = $credentials['project_id'] ?? null;

                if (!$projectId) {
                    Log::channel('notification')->error('Project ID not found in credentials');
                    return;
                }
            }

            // Send notification using V1 API
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                    ],
                ],
            ]);

            if ($response->successful()) {
                Log::channel('notification')->info('FCM notification sent successfully', [
                    'token' => substr($fcmToken, 0, 20) . '...',
                    'title' => $title,
                ]);
            } else {
                Log::channel('notification')->error('FCM notification failed', [
                    'token' => substr($fcmToken, 0, 20) . '...',
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('notification')->error('FCM notification exception', [
                'token' => substr($fcmToken, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get OAuth2 access token from service account credentials.
     *
     * @param string $credentialsPath
     * @return string|null
     */
    private function getAccessToken(string $credentialsPath): ?string
    {
        // In testing environment, return a mock token
        if (app()->environment('testing')) {
            return 'test_access_token';
        }
        
        try {
            $credentials = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                json_decode(file_get_contents(base_path($credentialsPath)), true)
            );

            $token = $credentials->fetchAuthToken();
            return $token['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to get access token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
