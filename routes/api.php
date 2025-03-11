<?php

use App\Http\Controllers\Api\v1\ChatController;
use App\Http\Controllers\Api\v1\GroupController;
use App\Http\Controllers\Api\v1\OtpController;
use App\Http\Controllers\Api\v1\ProjectManagementController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\SimpleTasksController;
use App\Http\Controllers\Api\v1\DailyTaskController;
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
        Route::controller(OtpController::class)->group(function () {
            Route::post('/refresh-token', 'refreshToken');
        });
        Route::controller(UserController::class)->group(function () {
            Route::post('/logout', 'logout');
            Route::post('/users-mobile-numbers', 'userMobileNumbers');
            Route::post('/user-list', 'userList');
            Route::post('/user-details', 'userDetails');
            Route::post('/user-group-details', 'userGroupDetails');
            Route::post('/edit-profile', 'editProfile');
            Route::post('/delete-chat-user', 'deleteChatUsers');
            Route::post('/deleted-user-list', 'deletedUserList');
            Route::post('/deleted-user-account', 'deletedUserAccount');
            Route::post('/tasks/update', 'updateTask');    
            Route::post('/update-task-details', 'updateTaskDetails');  
        });
        Route::controller(ChatController::class)->group(function () {
            Route::post('/text-message', 'textMessage');
            Route::post('/update-text-message', 'updatetextMessage');            
            Route::post('/group-text-message', 'groupTextMessage');
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
            Route::post('/get-single-message', 'getSingleMessage');
            Route::post('/add-reminder', 'addReminder');
            Route::post('/forward-message', 'forwardMessage');
            Route::post('/message-contact', 'contactDetails');
            Route::post('/task-users-list', 'taskUserList');
            Route::get('task-complete-incomplete', 'taskCompleteIncomplete');
            Route::post('/sent-task-summary-email', 'sentTaskSummaryEmail');
            Route::post('/sent-task-done', 'sentTaskDone'); 
            Route::post('/sent-event-done', 'sentEventDone');
            Route::post('/sent-meeting-done', 'sentMeetingDone'); 
            Route::get('/meetings', 'getMeetingDetails');    
            Route::post('/file-scan-upload', 'fileScanUpload'); 
            Route::get('/user-documents', 'getUserDocuments'); 
            Route::post('/message-task-notification', 'message_task_notification');   
            Route::post('/unread-message-count', 'getUnreadMessageCount');
            Route::post('/read-unread-manage', 'manageReadStatus'); 
            Route::post('/tasks/comments', 'addComment'); 
            Route::post('getTasks/comments', 'getComments'); 
            Route::post('group/question-with-options', 'questionWithOptions'); 
            Route::post('group/select-option', 'selectOption'); 
            Route::get('group/votes/fetch', 'fetchVotes');     
            Route::get('/meetings/{id}', 'getMeetingById');    
            Route::post('/image-to-pdf',  'imageToPdf');
             
        });
        Route::controller(ProjectManagementController::class)->group(function () {
            Route::post('/add-work-hours', 'addWorkHours');
            Route::post('/work-hours', 'workHours');
            Route::post('/edit-work-hours-summary', 'editWorkHoursSummary');
            Route::post('/add-note', 'addNote');
            Route::post('/note', 'notes');
            Route::post('/note-details', 'noteDetails');
            Route::post('/edit-note', 'editNotes');
            Route::post('/delete-note', 'deleteNote');
            Route::post('/send-work-hours-email', 'sendWorkHoursEmail');
            Route::post('/events-create-update', 'eventsCreateUpdate');
            Route::post('/events-list', 'eventsList');
            Route::post('/events-delete', 'eventsDelete');
            Route::get('/event/{id}', 'getEventById');
        });
        Route::controller(GroupController::class)->group(function () {
            Route::Post('/group-list', 'groupList');
            Route::Post('/group-member-search', 'groupMemberSearch');
            Route::post('/create-group', 'createGroup');
            Route::post('/edit-group', 'editGroup');
            Route::post('/user-list-for-group', 'userListForGroup');
            Route::post('/add-group-user', 'addGroupUser');
            Route::post('/remove-group-user', 'removeGroupUser');
            Route::delete('/group-delete', 'deleteGroup');            
        });
        Route::controller(SimpleTasksController::class)->group(function () {
            Route::post('/simple-task-create-or-update', 'simpleTaskCreateOrUpdate');
        });
        Route::controller(DailyTaskController::class)->group(function () {
            Route::post('/daily-task-create-or-update', 'dailyTaskCreateOrUpdate');
        });
    });
});