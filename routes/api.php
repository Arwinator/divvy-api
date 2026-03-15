<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public webhook routes (no auth required for PayMongo callbacks)
Route::post('/webhooks/paymongo', [WebhookController::class, 'handlePayMongo']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile'])
        ->middleware('throttle:5,60'); // 5 requests per hour
    
    // Group management routes
    Route::post('/groups', [GroupController::class, 'store']);
    Route::get('/groups', [GroupController::class, 'index']);
    
    // Group routes requiring membership verification
    Route::middleware('ensure.group.membership')->group(function () {
        Route::post('/groups/{id}/invitations', [GroupController::class, 'sendInvitation']);
        Route::delete('/groups/{id}/members/{userId}', [GroupController::class, 'removeMember']);
        Route::post('/groups/{id}/leave', [GroupController::class, 'leaveGroup']);
    });
    
    // Invitation routes (no group membership check needed - checked in controller)
    Route::post('/invitations/{id}/accept', [GroupController::class, 'acceptInvitation']);
    Route::post('/invitations/{id}/decline', [GroupController::class, 'declineInvitation']);
    
    // Bill management routes (group membership checked via middleware)
    Route::post('/bills', [BillController::class, 'store'])
        ->middleware('ensure.group.membership');
    Route::get('/bills', [BillController::class, 'index']);
    Route::get('/bills/{id}', [BillController::class, 'show']);
    
    // Payment routes
    Route::post('/shares/{id}/pay', [PaymentController::class, 'initiatePayment']);
    
    // Transaction history routes
    Route::get('/transactions', [\App\Http\Controllers\TransactionController::class, 'index']);
    
    // Sync routes
    Route::post('/sync', [SyncController::class, 'sync']);
    Route::get('/sync/timestamp', [SyncController::class, 'timestamp']);
});
