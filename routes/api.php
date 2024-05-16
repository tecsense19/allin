<?php

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
    });
    Route::controller(UserController::class)->group(function () {
        Route::post('/check-mobile-exists', 'checkMobileExists');
        Route::post('/user-rigstration', 'userRegistration');
    });
    Route::group(['middleware' => ['UserAuthentication']], function () {
        //
    });
});
