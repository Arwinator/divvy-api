<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GroupController;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile'])
        ->middleware('throttle:5,60'); // 5 requests per hour
    
    // Group management routes
    Route::post('/groups', [GroupController::class, 'store']);
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups/{id}/invitations', [GroupController::class, 'sendInvitation']);
    Route::post('/invitations/{id}/accept', [GroupController::class, 'acceptInvitation']);
    Route::post('/invitations/{id}/decline', [GroupController::class, 'declineInvitation']);
    Route::delete('/groups/{id}/members/{userId}', [GroupController::class, 'removeMember']);
    Route::post('/groups/{id}/leave', [GroupController::class, 'leaveGroup']);
});
