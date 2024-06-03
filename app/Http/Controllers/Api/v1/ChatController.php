<?php

namespace App\Http\Controllers\Api\v1;

use App\Exports\chatExport;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageLocation;
use App\Models\MessageMeeting;
use App\Models\MessageSenderReceiver;
use App\Models\MessageTask;
use App\Models\MessageTaskChat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/text-message",
     *     summary="Add a new message",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="textMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","receiver_id","message"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     example="This is a test message.",
     *                     description="Content of the message",
     *                     nullable=true
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="2",
     *                     description="ID of the receiver"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function textMessage(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'message' => 'required|string',
                'receiver_id' => 'required|integer',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'message.required' => 'The message content is required.',
                'message.string' => 'The message content must be a string.',
                'receiver_id.required' => 'The receiver ID is required.',
                'receiver_id.integer' => 'The receiver ID must be an integer.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->message = $request->message;
            $msg->status = "Unread";
            $msg->save();

            $messageSenderReceiver = new MessageSenderReceiver();
            $messageSenderReceiver->message_id = $msg->id;
            $messageSenderReceiver->sender_id = auth()->user()->id;
            $messageSenderReceiver->receiver_id = $request->receiver_id;
            $messageSenderReceiver->save();

            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/file-upload-message",
     *     summary="Add a new message",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="fileUploadMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","receiver_id","attachment","attachment_type"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="2",
     *                     description="ID of the receiver"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment_type",
     *                     type="string",
     *                     example="Image",
     *                     description="Like(Image, Audio, Video, Docs, Excel..)"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment",
     *                     type="string",
     *                     example="test.png",
     *                     description="Uploaded Attachment name",
     *                     nullable=true
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function fileUploadMessage(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'attachment' => 'required|string',
                'attachment_type' => 'required|string',
                'receiver_id' => 'required|integer',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'attachment_type.required' => 'The Attachment type is required.',
                'attachment_type.string' => 'The Attachment type must be a string.',
                'attachment.required' => 'The Attachment is required.',
                'attachment.string' => 'The Attachment must be a string.',
                'receiver_id.required' => 'The receiver ID is required.',
                'receiver_id.integer' => 'The receiver ID must be an integer.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->attachment_type = $request->attachment_type;
            $msg->status = "Unread";
            $msg->save();

            $messageSenderReceiver = new MessageSenderReceiver();
            $messageSenderReceiver->message_id = $msg->id;
            $messageSenderReceiver->sender_id = auth()->user()->id;
            $messageSenderReceiver->receiver_id = $request->receiver_id;
            $messageSenderReceiver->save();

            $messageAttachment = new MessageAttachment();
            $messageAttachment->message_id = $msg->id;
            $messageAttachment->attachment_name = $request->attachment;
            $messageAttachment->attachment_path = URL::to('public/chat-file/' . $request->attachment);
            $messageAttachment->save();

            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/message-task",
     *     summary="Add a new Task message",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="messageTask",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","receiver_id","task_name"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="1,2,3,4",
     *                     description="Comma-separated IDs of the receiver"
     *                 ),
     *                 @OA\Property(
     *                     property="task_name",
     *                     type="string",
     *                     example="CRUD Module",
     *                     description="Task Name"
     *                 ),
     *                 @OA\Property(
     *                     property="task_description",
     *                     type="string",
     *                     example="",
     *                     description="Task Description"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function messageTask(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'receiver_id' => 'required|string',
                'task_name' => 'required|string',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'receiver_id.required' => 'The receiver ID is required.',
                'receiver_id.string' => 'The receiver ID must be an string.',
                'task_name.required' => 'The Task Name is required.',
                'task_name.string' => 'The Task Name must be an string.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->save();

            $receiverIdsArray = explode(',', $request->receiver_id);
            $senderId = auth()->user()->id;

            foreach ($receiverIdsArray as $receiverId) {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = $senderId;
                $messageSenderReceiver->receiver_id = $receiverId;
                $messageSenderReceiver->save();
            }

            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $messageTask = new MessageTask();
            $messageTask->message_id = $msg->id;
            $messageTask->task_name = $request->task_name;
            $messageTask->task_description = @$request->task_description ? $request->task_description : NULL;
            $messageTask->users = $mergedIds;
            $messageTask->save();

            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/message-task-chat",
     *     summary="Add a new Task Chat message",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="messageTaskChat",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","task_id"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="task_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter taskId"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     example="",
     *                     description="Task Message"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function messageTaskChat(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'task_id' => 'required|string',
                'message' => 'required|string',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'task_id.required' => 'The receiver ID is required.',
                'task_id.string' => 'The receiver ID must be an string.',
                'message.required' => 'The receiver ID is required.',
                'message.string' => 'The receiver ID must be an string.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->save();

            $task_user = MessageTask::where('id', $request->task_id)->first()->users;
            $exloded_task_user = explode(",", $task_user);
            $userId = [auth()->user()->id];
            $notInArray = array_diff($exloded_task_user, $userId);
            if (count($notInArray) > 0) {
                foreach ($notInArray as $singleUser) {
                    MessageSenderReceiver::create([
                        'message_id' => $msg->id,
                        'sender_id' => auth()->user()->id,
                        'receiver_id' => $singleUser,
                    ]);
                }
            }
            $messageTaskChat = new MessageTaskChat();
            $messageTaskChat->message_id = $msg->id;
            $messageTaskChat->task_id = $request->task_id;
            $messageTaskChat->save();
            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/message-location",
     *     summary="Add a new message to send location",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="messageLocation",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","receiver_id","latitude","longitude"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter ReceiverId"
     *                 ),
     *                 @OA\Property(
     *                     property="latitude",
     *                     type="string",
     *                     example="123.123.1",
     *                     description="Enter latitude"
     *                 ),
     *                 @OA\Property(
     *                     property="longitude",
     *                     type="string",
     *                     example="445.123.0",
     *                     description="Enter longitude"
     *                 ),
     *                 @OA\Property(
     *                     property="location_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter location url"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function messageLocation(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'receiver_id' => 'required|string',
                'latitude' => 'required|string',
                'longitude' => 'required|string',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'receiver_id.required' => 'The Receiver Id is required.',
                'receiver_id.string' => 'The Receiver Id must be a string.',
                'latitude.required' => 'The latitude is required.',
                'latitude.string' => 'The latitude must be an string.',
                'longitude.required' => 'The longitude is required.',
                'longitude.string' => 'The longitude must be an string.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->save();

            $messageSenderReceiver = new MessageSenderReceiver();
            $messageSenderReceiver->message_id = $msg->id;
            $messageSenderReceiver->sender_id = auth()->user()->id;
            $messageSenderReceiver->receiver_id = $request->receiver_id;
            $messageSenderReceiver->save();

            $messageLocation = new MessageLocation();
            $messageLocation->message_id = $msg->id;
            $messageLocation->latitude = $request->latitude;
            $messageLocation->longitude = $request->longitude;
            $messageLocation->location_url = $request->location_url;
            $messageLocation->save();

            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/message-meeting",
     *     summary="Add a new message for meeting",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="messageMeeting",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","receiver_id","mode","title","date","start_time","end_time"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="1,2,3,4",
     *                     description="Comma-separated IDs of the receiver"
     *                 ),
     *                 @OA\Property(
     *                     property="mode",
     *                     type="string",
     *                     example="Online",
     *                     description="Enter Mode of the meeting. (Online  / Offline)"
     *                 ),
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     example="",
     *                     description="Meeting Title"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     example="",
     *                     description="meeting Description"
     *                 ),
     *                 @OA\Property(
     *                     property="date",
     *                     type="string",
     *                     example="",
     *                     description="Meeting date"
     *                 ),
     *                 @OA\Property(
     *                     property="start_time",
     *                     type="string",
     *                     example="",
     *                     description="Meeting Start Time"
     *                 ),
     *                 @OA\Property(
     *                     property="end_time",
     *                     type="string",
     *                     example="",
     *                     description="Meeting End Time"
     *                 ),
     *                 @OA\Property(
     *                     property="meeting_url",
     *                     type="string",
     *                     example="",
     *                     description="Meeting URL"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function messageMeeting(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'receiver_id' => 'required|string|regex:/^(\d+,)*\d+$/',
                'mode' => 'required|string|in:Online,Offline',
                'title' => 'required|string',
                'description' => 'nullable|string',
                'date' => 'required|date',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
                'meeting_url' => 'required_if:mode,Online|nullable|url',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'receiver_id.required' => 'The receiver ID is required.',
                'receiver_id.string' => 'The receiver ID must be a string.',
                'receiver_id.regex' => 'The receiver ID must be a comma-separated string of integers.',
                'mode.required' => 'The mode is required.',
                'mode.string' => 'The mode must be a string.',
                'mode.in' => 'The mode must be either Online or Offline.',
                'title.required' => 'The title is required.',
                'title.string' => 'The title must be a string.',
                'description.string' => 'The description must be a string.',
                'date.required' => 'The date is required.',
                'date.date' => 'The date must be a valid date.',
                'start_time.required' => 'The start time is required.',
                'start_time.date_format' => 'The start time must be in the format H:i:s.',
                'end_time.required' => 'The end time is required.',
                'end_time.date_format' => 'The end time must be in the format H:i:s.',
                'meeting_url.required_if' => 'The meeting URL is required when the mode is Online.',
                'meeting_url.url' => 'The meeting URL must be a valid URL.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->status = "Unread";
            $msg->save();

            $receiverIdsArray = explode(',', $request->receiver_id);
            $senderId = auth()->user()->id;

            foreach ($receiverIdsArray as $receiverId) {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = $senderId;
                $messageSenderReceiver->receiver_id = $receiverId;
                $messageSenderReceiver->save();
            }

            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $messageMeeting = new MessageMeeting();
            $messageMeeting->message_id = $msg->id;
            $messageMeeting->mode = $request->mode;
            $messageMeeting->title = $request->title;
            $messageMeeting->description = @$request->description ? $request->description : NULL;
            $messageMeeting->date = @$request->date ? Carbon::parse($request->date)->format('Y-m-d') : NULL;
            $messageMeeting->start_time = @$request->start_time ? Carbon::parse($request->start_time)->format('H:i:s') : NULL;
            $messageMeeting->end_time = @$request->end_time ? Carbon::parse($request->end_time)->format('H:i:s') : NULL;
            $messageMeeting->meeting_url = @$request->meeting_url ? $request->meeting_url : NULL;
            $messageMeeting->users = $mergedIds;
            $messageMeeting->save();

            $data = [
                'status_code' => 200,
                'message' => 'Message Sent Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/file-upload",
     *     summary="Upload a file",
     *     tags={"Files"},
     *     description="Upload a file and store its information.",
     *     operationId="uploadFile",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Upload File Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="File to upload"
     *                 )
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function fileUpload(Request $request)
    {
        try {
            $rules = [
                'file' => 'required|file',
            ];

            $message = [
                'file.required' => 'File is required.',
                'file.file' => 'file must be a valid file.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $extension = strtolower($file->getClientOriginalExtension());
                $originalNameWithExt = $file->getClientOriginalName();
                $originalName = pathinfo($originalNameWithExt, PATHINFO_FILENAME);
                $imageName = $originalName . '_' . time() . '.' . $extension;
                $destinationPath = public_path('chat-file/');
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0777, true);
                }
                $file->move($destinationPath, $imageName);

                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $optimizerChain = OptimizerChainFactory::create();
                    $optimizerChain->optimize($destinationPath . $imageName);
                }

                $data = [
                    'status_code' => 200,
                    'message' => 'File Uploaded Successfully!',
                    'data' => [
                        'image_name' => $imageName,
                        'image_path' => URL::to('public/chat-file/' . $imageName)
                    ]
                ];
                return $this->sendJsonResponse($data);
            } else {
                $data = [
                    'status_code' => 400,
                    'message' => 'File is required.',
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
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
     *     path="/api/v1/read-unread-message",
     *     summary="Add a new message for meeting",
     *     tags={"Messages"},
     *     description="Change Message status",
     *     operationId="changeMessageStatus",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"messageIds","status"},
     *                 @OA\Property(
     *                     property="messageIds",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Comma-Separated messageIds"
     *                 ),
     *                 @OA\Property(
     *                     property="status",
     *                     type="string",
     *                     example="Read",
     *                     description="Enter Status (Read / Unread)"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function changeMessageStatus(Request $request)
    {
        try {
            $rules = [
                'messageIds' => 'required|string|regex:/^\d+(,\d+)*$/',
                'status' => 'required|string|in:Read,Unread',
            ];

            $message = [
                'messageIds.required' => 'The message IDs are required.',
                'messageIds.string' => 'The message IDs must be a string.',
                'messageIds.regex' => 'The message IDs must be a comma-separated list of numeric values.',
                'status.required' => 'The status is required.',
                'status.string' => 'The status must be a string.',
                'status.in' => 'The status must be either "Read" or "Unread".',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $messageIds = explode(',', $request->messageIds);
            Message::whereIn('id', $messageIds)->update(['status' => $request->status]);

            $data = [
                'status_code' => 200,
                'message' => 'Change Status Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/delete-message",
     *     summary="Delete Message",
     *     tags={"Messages"},
     *     description="Delete Message",
     *     operationId="deleteMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter MessageId"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function deleteMessage(Request $request)
    {
        try {
            $rules = [
                'message_id' => 'required|string|regex:/^\d+(,\d+)*$/',
            ];

            $message = [
                'message_id.required' => 'The message ID is required.',
                'message_id.string' => 'The message ID must be a string.',
                'message_id.regex' => 'The message ID must only contain digits.',
                'message_id.exists' => 'The message ID does not exist in the message table.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $explodedMessage = explode(',', $request->message_id);
            $data = Message::whereIn('id', $explodedMessage);
            $messages = $data->get(['id', 'message_type']);
            foreach ($messages as $message) {
                $type = $message->message_type;
                MessageSenderReceiver::where('message_id', $message->id)->delete();
                if ($type == 'Attachment') {
                    MessageAttachment::where('message_id', $message->id)->delete();
                } elseif ($type == 'Location') {
                    MessageLocation::where('message_id', $message->id)->delete();
                } elseif ($type == 'Meeting') {
                    MessageMeeting::where('message_id', $message->id)->delete();
                } elseif ($type == 'Task') {
                    MessageTask::where('message_id', $message->id)->delete();
                } elseif ($type == 'Task Chat') {
                    MessageTaskChat::where('message_id', $message->id)->delete();
                }
            }
            $data->delete();

            $data = [
                'status_code' => 200,
                'message' => 'Delete message Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/clear-message",
     *     summary="Clear Message",
     *     tags={"Messages"},
     *     description="Clear Message",
     *     operationId="clearMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"user_id"},
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter userId"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function clearMessage(Request $request)
    {
        try {
            $rules = [
                'user_id' => 'required|string',
            ];

            $message = [
                'user_id.required' => 'The message ID is required.',
                'user_id.string' => 'The message ID must be a string.'
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $loginUser = auth()->user()->id;
            $userId = $request->user_id;
            $messages = MessageSenderReceiver::where(function ($query) use ($loginUser, $userId) {
                $query->where('sender_id', $loginUser)->where('receiver_id', $userId);
            })->orWhere(function ($query) use ($loginUser, $userId) {
                $query->where('sender_id', $userId)->where('receiver_id', $loginUser);
            });
            $messages->delete();

            $data = [
                'status_code' => 200,
                'message' => 'Clear message Successfully!',
                'data' => ""
            ];
            return $this->sendJsonResponse($data);
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
     *     path="/api/v1/export-chat",
     *     summary="Export Chat",
     *     tags={"Messages"},
     *     description="Export Chat",
     *     operationId="exportChat",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"user_id"},
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter userId"
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="",
     *                     description="Enter Timezone"
     *                 ),
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

    public function exportChat(Request $request)
    {
        try {
            $rules = [
                'user_id' => 'required|string',
            ];

            $message = [
                'user_id.required' => 'The message ID is required.',
                'user_id.string' => 'The message ID must be a string.'
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $loginUser = auth()->user()->id;
            $userId = $request->user_id;
            $timezone = @$request->timezone ? $request->timezone : '';
            $uniqueName = auth()->user()->account_id;
            $timestamp = Carbon::now()->timestamp;
            $fileName = "chat_messages_{$uniqueName}_{$timestamp}.csv";
            Excel::store(new chatExport($loginUser, $userId, $timezone), $fileName, 'export');

            // Generate the file URL using the asset() helper function
            $fileUrl = URL::to('public/exported-chat/' . $fileName);

            $data = [
                'status_code' => 200,
                'message' => 'Message Exported Successfully!',
                'data' => [
                    'fileUrl' => $fileUrl
                ]
            ];
            return $this->sendJsonResponse($data);
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
