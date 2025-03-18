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

            $senderId = auth()->user()->id;
            
            $todayName = date("l"); // Today's day name
            $currentTime = date("H:i:00"); // Current time (exact hour and minute)
            $currentDateTime = date("Y-m-d H:i:00"); // Current date with exact time

            $days = explode(',', $request->task_day); // Convert to array
            $taskTime = date("H:i:00", strtotime($request->task_time)); // Task time in H:i format
            if (in_array($todayName, $days)) 
            {
                $receiverIdsArray = explode(',', $request->users);
                $createdUser = User::where('id', $senderId)->first();
            
                $receiverIdsArray[] = $senderId;
                $uniqueIdsArray = array_unique($receiverIdsArray);
                $mergedIds = implode(',', $uniqueIdsArray);

                if ($currentTime === $taskTime) {                    
                    $msg = new Message();
                    $msg->message_type = $request->message_type;
                    $msg->status = "Unread";
                    $msg->date = $request->timezone ? Carbon::parse($currentDateTime)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($currentDateTime)->format('Y-m-d\TH:i:s.u\Z');
                    $msg->time = $request->timezone ? Carbon::parse($currentDateTime)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($currentDateTime)->format('Y-m-d\TH:i:s.u\Z');
                    $msg->assign_day = $request->task_day;
                    $msg->assign_time = $request->task_time;
                    $msg->assign_status = 'Pending';
                    $msg->payload = json_encode([
                        'task_name'    => $request->task_name,
                        'checkbox'     => $request->checkbox, // No need for explode
                        'users'        => explode(',', $mergedIds), // Convert comma-separated string to array
                    ]);
                    $msg->save();

                    $task_name_Array = explode(',', implode(',', $request->checkbox));
                    $task_name_UArray = array_unique($task_name_Array);

                    foreach ($task_name_UArray as $index => $taskName) { // Loop through multiple task names
                        $messageTask = new MessageTask();
                        $messageTask->message_id = $msg->id;
                        $messageTask->task_name = $request->task_name;
                        
                        // Use the corresponding task description if available
                        $messageTask->task_description = null;
                        
                        $messageTask->checkbox = $taskName; // Save each task name
                        $messageTask->users = $mergedIds;
                        $messageTask->save();
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
                            'sender' => $senderId,
                            'receiver' => $receiverId,
                            'message_type' => $request->message_type,
                            'task_name' => $request->task_name, // You may want to send all task names here
                            "screen" => "dailytask"
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
                            'title' => $createdUser ? $createdUser->first_name . ' ' . $createdUser->last_name : '',
                            'body' => 'Tasks: ' . $request->task_name, // Multiple task names
                            'image' => "",
                        ];
            
                        if (count($validTokens) > 0) {
                            sendPushNotification($validTokens, $notification, $message);
                        }
                    }

                    return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $msg ]);
                } else {
                    $msg = $this->createNewTask($request, $currentDateTime);
                    return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $msg ]);
                }
            } else {
                $msg = $this->createNewTask($request, $currentDateTime);
                return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $msg ]);
            }
            
            // Create or update the task
            // $dailyTask = DailyTask::updateOrCreate(
            //     ['id' => $request->message_id],
            //     [
            //         'task_day'  => $request->task_day,
            //         'task_time' => $request->task_time,
            //         'payload'   => [
            //             'message_type' => $request->message_type,
            //             'task_name'    => $request->task_name,
            //             'checkbox'     => $request->checkbox, // No need for explode
            //             'users'        => explode(',', $mergedIds), // Convert comma-separated string to array
            //             'timezone'     => $request->timezone,
            //         ],
            //     ]
            // );

            // $message = $dailyTask->wasRecentlyCreated ? 'Daily task created successfully.' : 'Daily task updated successfully.';

            // return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $dailyTask ]);

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

    function createNewTask($request, $currentDateTime) 
    {
        $receiverIdsArray = [];
        $senderId = auth()->user()->id;

        $receiverIdsArray[] = $senderId;
        $uniqueIdsArray = array_unique($receiverIdsArray);
        $mergedIds = implode(',', $uniqueIdsArray);

        $receiverIdsArray2 = explode(',', $request->users);
        $createdUser = User::where('id', $senderId)->first();
    
        $receiverIdsArray2[] = $senderId;
        $uniqueIdsArray = array_unique($receiverIdsArray2);
        $mergedIds2 = implode(',', $uniqueIdsArray);

        $msg = Message::firstOrNew(['id' => $request->message_id]);
        $msg->message_type = $request->message_type;
        $msg->status = "Unread";
        $msg->date = $request->timezone ? Carbon::parse($currentDateTime)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($currentDateTime)->format('Y-m-d\TH:i:s.u\Z');
        $msg->time = $request->timezone ? Carbon::parse($request->task_time)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($request->task_time)->format('Y-m-d\TH:i:s.u\Z');
        $msg->assign_day = $request->task_day;
        $msg->assign_time = $request->task_time;
        $msg->assign_status = 'Pending';
        $msg->payload = json_encode([
            'task_name'    => $request->task_name,
            'checkbox'     => $request->checkbox, // No need for explode
            'users'        => explode(',', $mergedIds2), // Convert comma-separated string to array
        ]);
        $msg->save();

        $task_name_Array = explode(',', implode(',', $request->checkbox));
        $task_name_UArray = array_unique($task_name_Array);

        foreach ($task_name_UArray as $index => $taskName) { // Loop through multiple task names
            $messageTask = new MessageTask();
            $messageTask->message_id = $msg->id;
            $messageTask->task_name = $request->task_name;
            
            // Use the corresponding task description if available
            $messageTask->task_description = null;
            
            $messageTask->checkbox = $taskName; // Save each task name
            $messageTask->users = $mergedIds;
            $messageTask->save();
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
                'sender' => $senderId,
                'receiver' => $receiverId,
                'message_type' => $request->message_type,
                'task_name' => $request->task_name, // You may want to send all task names here
                "screen" => "dailytask"
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
                'title' => $createdUser ? $createdUser->first_name . ' ' . $createdUser->last_name : '',
                'body' => 'Tasks: ' . $request->task_name, // Multiple task names
                'image' => "",
            ];

            if (count($validTokens) > 0) {
                sendPushNotification($validTokens, $notification, $message);
            }
        }

        return response()->json([ 'status_code' => 200, 'message' => 'Daily task created successfully.', 'data' => $msg ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/daily-task-delete",
     *     summary="Delete a daily task",
     *     tags={"Daily Task"},
     *     description="Deletes a daily task by ID.",
     *     operationId="deleteDailyTask",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"id"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer",
     *                     example=1,
     *                     description="ID of the daily task to delete"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task deleted successfully",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(property="status_code", type="integer", example=200),
     *                 @OA\Property(property="message", type="string", example="Task deleted successfully"),
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

    public function deleteDailyTask(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:daily_tasks,id',
        ]);

        $dailyTask = DailyTask::find($request->id);

        if (!$dailyTask) {
            return response()->json([ 'status_code' => 404, 'message' => 'Daily task not found.', 'data' => "" ]);
        }

        $dailyTask->delete();

        return response()->json([ 'status_code' => 200, 'message' => 'Daily task deleted successfully.', 'data' => "" ]);
    }
}