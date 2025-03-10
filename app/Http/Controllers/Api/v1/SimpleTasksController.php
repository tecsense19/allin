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

class SimpleTasksController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/simple-task-create-or-update",
     *     summary="Create or update a simple task",
     *     tags={"Simple Task"},
     *     description="Creates a new simple task or updates an existing one based on task ID.",
     *     operationId="simpleTaskCreateOrUpdate",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload for creating or updating a simple task",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type", "title", "timezone"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="integer",
     *                     example=1,
     *                     description="ID of the message associated with the task"
     *                 ),
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="SimpleTask",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     example="Task Title",
     *                     description="Title of the task"
     *                 ),
     *                 @OA\Property(
     *                     property="task_date",
     *                     type="string",
     *                     example="",
     *                     description="Task date"
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
     *                     property="priority_task",
     *                     type="boolean",
     *                     example=true,
     *                     description="Indicates if the task is a priority (true or false)"
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

    public function simpleTaskCreateOrUpdate(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'message_type' => 'required|string',
                'title' => 'required|string|max:255',
                'users' => 'nullable|string',
                'priority_task' => 'nullable',
                'timezone' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            $msg = Message::firstOrNew(['id' => $request->message_id]);
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->date = $request->task_date; // Save date
            $msg->time = $request->task_time; // Save time
            $msg->save();
    
            $receiverIdsArray = explode(',', $request->users);
            $senderId = auth()->user()->id;
    
            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $messageTask = MessageTask::firstOrNew(['message_id' => $request->message_id]);
            $messageTask->message_id = $msg->id;
            $messageTask->task_name = $request->title; // or a default value
            $messageTask->task_description = null; // or a default value
            $messageTask->priority_task = filter_var($request->priority_task, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; // or a default value
            $messageTask->checkbox = $request->title; // or a default value
            $messageTask->users = $mergedIds;
            $messageTask->save();

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

    private function initializeUserTaskStatus($users)
    {
        if (!$users) return null; // Return null if no users are assigned

        $userIds = explode(',', $users); // Convert CSV to array
        $status = [];
        foreach ($userIds as $userId) {
            $status[$userId] = false; // Default status is false
        }
        return json_encode($status);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/simple-task/{id}",
     *     summary="Get Simple Task by ID with Relations",
     *     tags={"Simple Task"},
     *     description="Fetches a specific simple task along with related data (message, assigned users, created by, updated by).",
     *     operationId="getSimpleTaskById",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the simple task",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Task retrieved successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found"
     *     )
     * )
     */
}