<?php

namespace App\Http\Controllers\Api\v1;

use App\Events\MessageSent;
use App\Exports\chatExport;
use App\Http\Controllers\Controller;
use App\Mail\taskMail;
use App\Models\{
    Message,
    MessageAttachment,
    MessageLocation,
    MessageMeeting,
    MessageReminder,
    MessageSenderReceiver,
    MessageTask,
    MessageTaskChat,
    MessageTaskChatComment,
    GroupMembers,
    Reminder,
    User,
    deleteChatUsers,
    ProjectEvent,
    userDeviceToken,
    UserDocument,
    Option,
    DailyTask
};

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class DailyTaskController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/daily-task-create-or-update",
     *     summary="Create or update a daily task",
     *     tags={"Daily Task"},
     *     description="Creates a new daily task or updates an existing one based on task ID.",
     *     operationId="dailyTaskCreateOrUpdate",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload for creating or updating a daily task",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type", "task_name", "checkbox", "task_day", "task_time", "users", "timezone"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="integer",
     *                     example=1,
     *                     description="ID of the message associated with the task"
     *                 ),
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="DailyTask",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="task_name",
     *                     type="string",
     *                     example="Task Name",
     *                     description="Name of the task"
     *                 ),
     *                 @OA\Property(
     *                     property="checkbox",
     *                     type="array",
     *                     @OA\Items(
     *                         type="string",
     *                         example="CRUD Module"
     *                     ),
     *                     description="Array of checkbox",
     *                     nullable=false
     *                 ),
     *                 @OA\Property(
     *                     property="task_day",
     *                     type="string",
     *                     example="",
     *                     description="Task day name"
     *                 ),
     *                 @OA\Property(
     *                     property="task_time",
     *                     type="string",
     *                     example="",
     *                     description="Task Time"
     *                 ),
     *                 @OA\Property(
     *                     property="users",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Comma-separated list of user IDs assigned to the task"
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="America/New_York",
     *                     description="Timezone of the user creating the task",
     *                     nullable=true
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task created successfully",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(property="status_code", type="integer", example=200),
     *                 @OA\Property(property="message", type="string", example="Task created successfully"),
     *                 @OA\Property(property="data", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized access"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Message not found"
     *     )
     * )
     */

    public function dailyTaskCreateOrUpdate(Request $request)
    {
        try {
            $request->merge([
                'checkbox' => is_array($request->checkbox) ? $request->checkbox : explode(',', $request->checkbox),
            ]);
            // Validation
            $validator = Validator::make($request->all(), [
                'message_type' => 'required|string',
                'task_name'    => 'required|string',
                'checkbox'     => ['required','array','min:1'], // Fix here
                'task_day'     => 'required|string',
                'task_time'    => 'required|string',
                'users'        => 'required|string',
                'timezone'     => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            // Convert task_time with timezone handling
            $taskTime = $request->task_time ? Carbon::parse($request->task_time, $request->timezone)->format('H:i:s') : null;

            // Create or update the task
            $dailyTask = DailyTask::updateOrCreate(
                ['id' => $request->message_id],
                [
                    'task_day'  => $request->task_day,
                    'task_time' => $taskTime,
                    'payload'   => [
                        'message_type' => $request->message_type,
                        'task_name'    => $request->task_name,
                        'checkbox'     => $request->checkbox, // No need for explode
                        'users'        => explode(',', $request->users), // Convert comma-separated string to array
                        'timezone'     => $request->timezone,
                    ],
                ]
            );

            return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $dailyTask ]);

            $msg = Message::firstOrNew(['id' => $request->message_id]);
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->date = $request->task_date ? Carbon::parse($request->task_date)->setTimezone($request->timezone) : ""; // Save date
            $msg->time = $request->task_time ? Carbon::parse($request->task_time)->setTimezone($request->timezone) : ""; // Save time
            $msg->save();
    
            $receiverIdsArray = explode(',', $request->users);
            $senderId = auth()->user()->id;
    
            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $task_name_Array = explode(',', $request->checkbox);
            $task_name_UArray = array_unique($task_name_Array);

            foreach ($task_name_UArray as $index => $taskName) { // Loop through multiple task names
                $messageTask = new MessageTask();
                $messageTask->message_id = $msg->id;
                $messageTask->task_name = $request->task_name;
                
                // Use the corresponding task description if available
                $messageTask->task_description = $taskDescriptions ? $taskDescriptions[$index] : null;
                
                $messageTask->checkbox = $taskName; // Save each task name
                $messageTask->users = $mergedIds;
                $messageTask->save();
            }

            if(isset($request->message_id)) {
                MessageSenderReceiver::where('message_id', $msg->id)->delete();
            }

            foreach ($receiverIdsArray as $receiverId) 
            {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = $senderId;
                $messageSenderReceiver->receiver_id = $receiverId;
                $messageSenderReceiver->save();
    
                $message = [
                    'id' => $msg->id,
                    'sender' => auth()->user()->id,
                    'receiver' => $receiverId,
                    'message_type' => $request->message_type,
                    'task_name' => $request->task_name, // You may want to send all task names here
                    "screen" => "simpletask"
                ];
    
                broadcast(new MessageSent($message))->toOthers();
    
                // Push Notification
                $validationResults = validateToken($receiverId);
                $validTokens = [];
                $invalidTokens = [];
                foreach ($validationResults as $result) {
                    $validTokens = array_merge($validTokens, $result['valid']);
                    $invalidTokens = array_merge($invalidTokens, $result['invalid']);
                }
                if (count($invalidTokens) > 0) {
                    foreach ($invalidTokens as $singleInvalidToken) {
                        userDeviceToken::where('token', $singleInvalidToken)->forceDelete();
                    }
                }
    
                $notification = [
                    'title' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
                    'body' => 'Tasks: ' . $request->task_name, // Multiple task names
                    'image' => "",
                ];
    
                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $message);
                }
            }

            return response()->json([ 'status_code' => 200, 'message' => 'Simple task created successfully.', 'data' => $msg ]);

        } catch (\Exception $e) {
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);

            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }
}