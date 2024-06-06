<?php

use App\Http\Controllers\Api\v1\ChatController;
use App\Http\Controllers\Api\v1\OtpController;
use App\Http\Controllers\Api\v1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['prefix' => 'v1', 'middleware' => ['XssSanitization']], function () {
    // This group will be protected by JWT and have a token TTL of 1 hour
    // This group will manage users
    Route::controller(OtpController::class)->group(function () {
        Route::post('/send-otp', 'sendOtp');
        Route::post('/verify-otp', 'verifyOtp');
        Route::post('/refresh-token', 'refreshToken');
    });
    Route::controller(UserController::class)->group(function () {
        Route::post('/check-mobile-exists', 'checkMobileExists');
        Route::post('/user-registration', 'userRegistration');
    });
    Route::group(['middleware' => ['UserAuthentication']], function () {
        Route::controller(UserController::class)->group(function () {
            Route::post('/logout', 'logout');
            Route::post('/users-mobile-numbers', 'userMobileNumbers');
            Route::post('/user-list', 'userList');
            Route::post('/user-details', 'userDetails');
            Route::post('/edit-profile', 'editProfile');
            Route::post('/delete-chat-user', 'deleteChatUsers');
        });
        Route::controller(ChatController::class)->group(function () {
            Route::post('/text-message', 'textMessage');
            Route::post('/file-upload-message', 'fileUploadMessage');
            Route::post('/message-task', 'messageTask');
            Route::post('/message-task-chat', 'messageTaskChat');
            Route::post('/message-location', 'messageLocation');
            Route::post('/message-meeting', 'messageMeeting');
            Route::post('/file-upload', 'fileUpload');
            Route::post('/read-unread-message', 'changeMessageStatus');
            Route::post('/delete-message', 'deleteMessage');
            Route::post('/clear-message', 'clearMessage');
            Route::post('/export-chat', 'exportChat');
            Route::post('/task-chat', 'taskChat');
        });
    });
});
