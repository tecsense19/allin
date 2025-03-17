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

            $receiverIdsArray = explode(',', $request->users);
            $senderId = auth()->user()->id;
    
            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);
            
            // Create or update the task
            $dailyTask = DailyTask::updateOrCreate(
                ['id' => $request->message_id],
                [
                    'task_day'  => $request->task_day,
                    'task_time' => $request->task_time,
                    'payload'   => [
                        'message_type' => $request->message_type,
                        'task_name'    => $request->task_name,
                        'checkbox'     => $request->checkbox, // No need for explode
                        'users'        => explode(',', $mergedIds), // Convert comma-separated string to array
                        'timezone'     => $request->timezone,
                    ],
                ]
            );

            $message = $dailyTask->wasRecentlyCreated ? 'Daily task created successfully.' : 'Daily task updated successfully.';

            return response()->json([ 'status_code' => 200, 'message' => $message, 'data' => $dailyTask ]);

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