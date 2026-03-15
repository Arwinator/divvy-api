<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)],
            'fcm_token' => 'required|string',
            'device_id' => 'required|string',
        ], [
            'username.required' => 'Username is required',
            'username.unique' => 'This username is already taken',
            'username.max' => 'Username cannot exceed 255 characters',
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'email.unique' => 'This email address is already registered',
            'email.max' => 'Email address cannot exceed 255 characters',
            'password.required' => 'Password is required',
            'password.confirmed' => 'Password confirmation does not match',
            'password.min' => 'Password must be at least 8 characters',
            'fcm_token.required' => 'FCM token is required',
            'device_id.required' => 'Device ID is required',
        ]);

        // Create user
        $user = User::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Create FCM token record
        FcmToken::create([
            'user_id' => $user->id,
            'device_id' => $validated['device_id'],
            'token' => $validated['fcm_token'],
            'status' => 'active',
        ]);

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'token' => $token,
        ], 201);
    }

    /**
     * Login a user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'fcm_token' => 'required|string',
            'device_id' => 'required|string',
        ], [
            'email.required' => 'Email address is required',
            'email.email' => 'Please provide a valid email address',
            'password.required' => 'Password is required',
            'fcm_token.required' => 'FCM token is required',
            'device_id.required' => 'Device ID is required',
        ]);

        // Check credentials
        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Upsert FCM token (user_id + device_id composite key)
        FcmToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => $validated['device_id'],
            ],
            [
                'token' => $validated['fcm_token'],
                'status' => 'active',
            ]
        );

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'token' => $token,
        ], 200);
    }

    /**
     * Logout a user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Get device_id from request (optional, for specific device logout)
        $deviceId = $request->input('device_id');

        // Revoke current Sanctum token
        $request->user()->currentAccessToken()->delete();

        // Set FCM token status to 'inactive' for current user-device
        if ($deviceId) {
            FcmToken::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->update(['status' => 'inactive']);
        } else {
            // If no device_id provided, deactivate all tokens for this user
            FcmToken::where('user_id', $user->id)
                ->update(['status' => 'inactive']);
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Update user profile (username, email, and/or password).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Validate that at least one field is provided
        if (!$request->hasAny(['username', 'email', 'password'])) {
            return response()->json([
                'message' => 'At least one field must be provided for update.',
                'errors' => [
                    'profile' => ['Please provide at least one field to update (username, email, or password).']
                ]
            ], 422);
        }

        // Validate the request - only validate fields that are present
        $validated = $request->validate([
            'username' => 'sometimes|string|max:255|unique:users,username,' . $user->id,
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'current_password' => 'required_with:password|string',
            'password' => ['sometimes', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ], [
            'username.unique' => 'This username is already taken',
            'username.max' => 'Username cannot exceed 255 characters',
            'email.email' => 'Please provide a valid email address',
            'email.unique' => 'This email address is already registered',
            'email.max' => 'Email address cannot exceed 255 characters',
            'current_password.required_with' => 'Current password is required when changing password',
            'password.confirmed' => 'Password confirmation does not match',
            'password.min' => 'Password must be at least 8 characters',
        ]);

        $passwordChanged = false;
        $changedFields = [];

        // If password is being updated, verify current password first
        if (isset($validated['password'])) {
            if (!isset($validated['current_password']) || !Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'The current password is incorrect.',
                    'errors' => [
                        'current_password' => ['The current password is incorrect.']
                    ]
                ], 422);
            }
            $user->password = Hash::make($validated['password']);
            $passwordChanged = true;
            $changedFields[] = 'password';
        }

        // Update only the fields that were provided
        if (isset($validated['username'])) {
            $user->username = $validated['username'];
            $changedFields[] = 'username';
        }

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
            $changedFields[] = 'email';
        }

        $user->save();

        // If password was changed, revoke all other tokens (logout other devices)
        if ($passwordChanged) {
            // Get current token ID
            $currentTokenId = $request->user()->currentAccessToken()->id;
            
            // Revoke all tokens except the current one
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        // Send notification to all user's devices about profile update
        $notificationService = new NotificationService();
        $notificationService->sendProfileUpdateNotification($user, $changedFields);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'created_at' => $user->created_at->toIso8601String(),
                ],
                'password_changed' => $passwordChanged,
                'tokens_revoked' => $passwordChanged ? 'All other devices have been logged out for security.' : null,
                'notification_sent' => true,
            ]
        ], 200);
    }
}
