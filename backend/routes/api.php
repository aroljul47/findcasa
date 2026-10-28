<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminPropertyController;
use App\Http\Controllers\Api\CampusController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AdminSourceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RouteDistanceController;
use App\Http\Controllers\Api\PropertyFilterController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\AdminDashboardController;

Broadcast::routes(['middleware' => ['auth:sanctum', 'active']]);
use App\Http\Controllers\Api\EmailVerificationController;

Route::get('/institutions', [InstitutionController::class, 'index']);
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/property-filters', [PropertyFilterController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::get('/properties/{property}/reviews', [ReviewController::class, 'index']);
Route::get('/properties/{property}/route-distance', [RouteDistanceController::class, 'show'])->middleware('throttle:30,1');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:10,1'])->name('verification.verify');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1');
    });
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/statistics', [AdminDashboardController::class, 'statistics']);
    Route::get('/users', [AdminDashboardController::class, 'users']);
    Route::get('/institutions', [AdminDashboardController::class, 'institutions']);
    Route::get('/audit-logs', [AdminDashboardController::class, 'logs']);
    Route::get('/reviews/pending', [ReviewController::class, 'pending']);
    Route::patch('/reviews/{review}/review', [ReviewController::class, 'review']);
    Route::get('/reports/pending', [ReportController::class, 'pending']);
    Route::patch('/reports/{report}/review', [ReportController::class, 'review']);
    Route::get('/sources', [AdminSourceController::class, 'index']);
    Route::get('/collected-offers', [AdminSourceController::class, 'offers']);
    Route::patch('/collected-offers/{offer}/review', [AdminSourceController::class, 'reviewOffer']);
    Route::post('/sources', [AdminSourceController::class, 'store']);
    Route::put('/sources/{source}', [AdminSourceController::class, 'update']);
    Route::get('/media/pending', [MediaController::class, 'pending']);
    Route::patch('/media/{media}/review', [MediaController::class, 'review']);
    Route::get('/owners/pending', [AdminUserController::class, 'pendingOwners']);
    Route::get('/owners/{user}/identity-document', [AdminUserController::class, 'identityDocument']);
    Route::patch('/owners/{user}/verification', [AdminUserController::class, 'verifyOwner']);
    Route::patch('/users/{user}/suspension', [AdminUserController::class, 'suspend']);
    Route::get('/properties/pending', [AdminPropertyController::class, 'pending']);
    Route::get('/properties/{property}', [AdminPropertyController::class, 'show']);
    Route::patch('/properties/{property}/review', [AdminPropertyController::class, 'review']);
    Route::post('/institutions', [InstitutionController::class, 'store']);
    Route::put('/institutions/{institution}', [InstitutionController::class, 'update']);
    Route::delete('/institutions/{institution}', [InstitutionController::class, 'destroy']);
    Route::post('/institutions/{institution}/campuses', [CampusController::class, 'store']);
    Route::put('/campuses/{campus}', [CampusController::class, 'update']);
    Route::delete('/campuses/{campus}', [CampusController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'active', 'verified', 'role:owner'])->prefix('owner')->group(function () {
    Route::get('/properties', [PropertyController::class, 'mine']);
    Route::get('/properties/{property}', [PropertyController::class, 'ownerShow']);
    Route::post('/properties/draft', [PropertyController::class, 'saveDraft']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
    Route::post('/properties/{property}/rooms', [RoomController::class, 'store']);
    Route::patch('/rooms/{room}', [RoomController::class, 'update']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
    Route::post('/properties/{property}/media', [MediaController::class, 'store'])->middleware('throttle:20,1');
    Route::put('/properties/{property}/media/reorder', [MediaController::class, 'reorder']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);
    Route::get('/reservations', [ReservationController::class, 'received']);
    Route::patch('/reservations/{reservation}', [ReservationController::class, 'respond']);
    Route::post('/reservations/{reservation}/rented', [ReservationController::class, 'markRented']);
    Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete']);
});

Route::middleware(['auth:sanctum', 'active', 'verified', 'role:student'])->group(function () {
    Route::get('/reviews/mine', [ReviewController::class, 'mine']);
    Route::post('/reviews', [ReviewController::class, 'store'])->middleware('throttle:5,1');
    Route::post('/properties/{property}/messages', [ConversationController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/properties/{property}/reports', [ReportController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/properties/{property}/favorite', [FavoriteController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/properties/{property}/favorite', [FavoriteController::class, 'destroy'])->middleware('throttle:30,1');
    Route::post('/reservations', [ReservationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/reservations', [ReservationController::class, 'mine']);
    Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
    Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
    Route::get('/external-contact-requests', [ReservationController::class, 'externalMine']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])->middleware('throttle:30,1');
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->get('/admin/external-contact-requests', [ReservationController::class, 'externalPending']);
