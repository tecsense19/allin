<?php

namespace App\Http\Controllers\Api\v1;

use App\Events\MessageSent;
use App\Exports\chatExport;
use App\Http\Controllers\Controller;
use App\Mail\taskMail;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageLocation;
use App\Models\MessageMeeting;
use App\Models\MessageReminder;
use App\Models\MessageSenderReceiver;
use App\Models\MessageTask;
use App\Models\MessageTaskChat;
use App\Models\Reminder;
use App\Models\User;
use App\Models\userDeviceToken;
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

            $message = [
                'id' => $msg->id,
                'sender' => auth()->user()->id,
                'receiver' => $request->receiver_id,
                'message_type' => $request->message_type,
                'message' => $request->message,
                "screen" => "chatinner"
            ];

            //Pusher
            broadcast(new MessageSent($message))->toOthers();

            //Push Notification
            $validationResults = validateToken($request->receiver_id);
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
                'body' => $request->message,
                'image' => '',
            ];

            if (count($validTokens) > 0) {
                sendPushNotification($validTokens, $notification, $message);
            }

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
                // 'receiver_id' => 'required|integer',
                'receiver_id' => 'nullable|string',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'attachment_type.required' => 'The Attachment type is required.',
                'attachment_type.string' => 'The Attachment type must be a string.',
                'attachment.required' => 'The Attachment is required.',
                'attachment.string' => 'The Attachment must be a string.',
                // 'receiver_id.required' => 'The receiver ID is required.',
                // 'receiver_id.integer' => 'The receiver ID must be an integer.',
                'receiver_id.string' => 'users must be an Comma Separated String.',
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

            $receiverIdsArray = $request->receiver_id ? explode(',', $request->receiver_id) : [];
            $senderId = auth()->user()->id;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            foreach ($receiverIdsArray as $receiverId) 
            {
                $msg = new Message();
                $msg->message_type = $request->message_type;
                $msg->attachment_type = $request->attachment_type;
                $msg->status = "Unread";
                $msg->save();

                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = auth()->user()->id;
                $messageSenderReceiver->receiver_id = $receiverId;
                $messageSenderReceiver->save();

                $messageAttachment = new MessageAttachment();
                $messageAttachment->message_id = $msg->id;
                $messageAttachment->attachment_name = $request->attachment;
                $messageAttachment->attachment_path = setAssetPath('chat-file/' . $request->attachment);
                $messageAttachment->save();

                $message = [
                    'id' => $msg->id,
                    'sender' => auth()->user()->id,
                    'receiver' => $receiverId,
                    'message_type' => $request->message_type,
                    'attachment_type' => $request->attachment_type,
                    'attachment_name' => $request->attachment,
                    'attachment_path' => setAssetPath('chat-file/' . $request->attachment),
                    "screen" => "chatinner"
                ];

                broadcast(new MessageSent($message))->toOthers();

                //Push Notification
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
                    'body' => $request->message_type,
                    'image' => setAssetPath('chat-file/' . $request->attachment),
                ];

                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $message);
                }
            }

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

            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $messageTask = new MessageTask();
            $messageTask->message_id = $msg->id;
            $messageTask->task_name = $request->task_name;
            $messageTask->task_description = @$request->task_description ? $request->task_description : NULL;
            $messageTask->users = $mergedIds;
            $messageTask->save();

            foreach ($receiverIdsArray as $receiverId) {
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
                    'task_name' => $request->task_name,
                    'task_description' => @$request->task_description ? $request->task_description : NULL,
                    "screen" => "chatinner"
                ];

                broadcast(new MessageSent($message))->toOthers();


                //Push Notification
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
                    'body' => 'Task : ' . $request->task_name,
                    'image' => "",
                ];

                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $message);
                }
            }

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
     *                 required={"message_type","task_id","chat_type"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="Task Chat",
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
     *                 @OA\Property(
     *                     property="chat_type",
     *                     type="string",
     *                     example="Text",
     *                     description="Chat Type (Text / Attachment)"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment_type",
     *                     type="string",
     *                     example="jpg",
     *                     description="Attachment Type"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment",
     *                     type="string",
     *                     example="abc.jpg",
     *                     description="Attachment Name"
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
                'chat_type' => 'required|string',
            ];

            $message = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'task_id.required' => 'The task ID is required.',
                'task_id.string' => 'The task ID must be an string.',
                'chat_type.required' => 'The Chat type is required.',
                'chat_type.string' => 'The Chat type must be an string.',
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
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            if ($request->chat_type == 'Text') {
                $msg = new Message();
                $msg->message_type = $request->message_type;
                $msg->message = $request->message;
                $msg->status = "Unread";
                $msg->save();
            } elseif ($request->chat_type == 'Attachment') {
                $msg = new Message();
                $msg->message_type = $request->message_type;
                $msg->attachment_type = $request->attachment_type;
                $msg->status = "Unread";
                $msg->save();

                $messageAttachment = new MessageAttachment();
                $messageAttachment->message_id = $msg->id;
                $messageAttachment->attachment_name = $request->attachment;
                $messageAttachment->attachment_path = setAssetPath('chat-file/' . $request->attachment);
                $messageAttachment->save();
            }

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


                    $message = [
                        'id' => $msg->id,
                        'sender' => auth()->user()->id,
                        'receiver' => $singleUser,
                        'message_type' => $request->message_type,
                        'message' => $request->message,
                        'attachment' => @$request->attachment ? setAssetPath('chat-file/' . $request->attachment) : '',
                        'task_id' => $request->task_id,
                        "screen" => "chatinner"
                    ];

                    broadcast(new MessageSent($message))->toOthers();

                    //Push Notification
                    $validationResults = validateToken($singleUser);
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
                        'body' => 'Task Chat: ' . @$request->message ? $request->message : '',
                        'image' => @$request->attachment ? setAssetPath('chat-file/' . $request->attachment) : '',
                    ];

                    if (count($validTokens) > 0) {
                        sendPushNotification($validTokens, $notification, $message);
                    }
                }
            }
            $messageTaskChat = new MessageTaskChat();
            $messageTaskChat->message_id = $msg->id;
            $messageTaskChat->task_id = $request->task_id;
            $messageTaskChat->save();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
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
            $messageLocation->location_url = @$request->location_url ? $request->location_url : NULL;
            $messageLocation->save();

            $message = [
                'id' => $msg->id,
                'sender' => auth()->user()->id,
                'receiver' => $request->receiver_id,
                'message_type' => $request->message_type,
                'latitude' => $request->latitude,
                'latitude' => $request->latitude,
                'location_url' => @$request->location_url ? $request->location_url : NULL
            ];

            broadcast(new MessageSent($message))->toOthers();

            //Push Notification
            $validationResults = validateToken($request->receiver_id);
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
                'body' => $request->message_type,
                'image' => "",
            ];

            if (count($validTokens) > 0) {
                sendPushNotification($validTokens, $notification, $message);
            }

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
     *                 @OA\Property(
     *                     property="latitude",
     *                     type="string",
     *                     example="",
     *                     description="Meeting latitude"
     *                 ),
     *                 @OA\Property(
     *                     property="longitude",
     *                     type="string",
     *                     example="",
     *                     description="Meeting longitude"
     *                 ),
     *                 @OA\Property(
     *                     property="location_url",
     *                     type="string",
     *                     example="",
     *                     description="Meeting location URL"
     *                 ),
     *                 @OA\Property(
     *                     property="location",
     *                     type="string",
     *                     example="",
     *                     description="Meeting location"
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

            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $messageMeeting = new MessageMeeting();
            $messageMeeting->message_id = $msg->id;
            $messageMeeting->mode = $request->mode;
            $messageMeeting->title = $request->title;
            $messageMeeting->description = @$request->description ? $request->description : NULL;
            $messageMeeting->date = @$request->date ? Carbon::parse($request->date)->format('Y-m-d') : NULL;
            $messageMeeting->start_time = @$request->start_time ? $request->start_time : NULL;
            $messageMeeting->end_time = @$request->end_time ? $request->end_time : NULL;
            $messageMeeting->meeting_url = @$request->meeting_url ? $request->meeting_url : NULL;
            $messageMeeting->users = $mergedIds;
            $messageMeeting->latitude = @$request->latitude ? $request->latitude : NULL;
            $messageMeeting->longitude = @$request->longitude ? $request->longitude : NULL;
            $messageMeeting->location_url = @$request->location_url ? $request->location_url : NULL;
            $messageMeeting->location = @$request->location ? $request->location : NULL;
            $messageMeeting->save();

            foreach ($receiverIdsArray as $receiverId) {
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
                    'mode' => $request->mode,
                    'title' => $request->title,
                    'description' => @$request->description ? $request->description : NULL,
                    'date' => @$request->date ? Carbon::parse($request->date)->format('Y-m-d') : NULL,
                    'start_time' => @$request->start_time ? $request->start_time : NULL,
                    'end_time' => @$request->end_time ? $request->end_time : NULL,
                    'meeting_url' => @$request->meeting_url ? $request->meeting_url : NULL,
                    'users' => $mergedIds,
                    'latitude' => @$request->latitude ? $request->latitude : NULL,
                    'longitude' => @$request->longitude ? $request->longitude : NULL,
                    'location_url' => @$request->location_url ? $request->location_url : NULL,
                    'location' => @$request->location ? $request->location : NULL,
                ];

                broadcast(new MessageSent($message))->toOthers();

                //Push Notification
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
                    'body' => 'Meeting: ' . @$request->title ? $request->title : '',
                    'image' => "",
                ];

                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $message);
                }
            }

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
                $originalName = create_slug($originalName);
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
                        'file_type' => $extension,
                        'image_path' => setAssetPath('chat-file/' . $imageName)
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
                'user_id.required' => 'The User ID is required.',
                'user_id.string' => 'The User ID must be a string.'
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
            $fileUrl = setAssetPath('exported-chat/' . $fileName);

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
    /**
     * @OA\Post(
     *     path="/api/v1/task-chat",
     *     summary="Task Chat",
     *     tags={"Messages"},
     *     description="Task Chat",
     *     operationId="taskChat",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"task_id"},
     *                 @OA\Property(
     *                     property="task_id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter taskId"
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="",
     *                     description="Enter Timezone"
     *                 ),
     *                 @OA\Property(
     *                     property="start",
     *                     type="number",
     *                     example="0",
     *                     description="Enter start"
     *                 ),
     *                 @OA\Property(
     *                     property="limit",
     *                     type="number",
     *                     example="",
     *                     description="Enter limit"
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
    public function taskChat(Request $request)
    {
        try {
            $rules = [
                'task_id' => 'required|integer|exists:message_task,id',
            ];

            $message = [
                'task_id.required' => 'Task ID is required.',
                'task_id.integer' => 'Task ID must be an integer.',
                'task_id.exists' => 'The specified task does not exist.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            $taskId = $request->task_id;
            $loginUserId = auth()->user()->id;

            // Fetch task details
            $task = MessageTask::findOrFail($taskId);
            $userIds = explode(',', $task->users);
            $userList = User::whereIn('id', $userIds)
                ->select('id', 'first_name', 'last_name', 'profile')
                ->get()
                ->map(function ($user) {
                    return [
                        'userId' => $user->id,
                        'name' => "{$user->first_name} {$user->last_name}",
                        'profilePic' => @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png'),
                    ];
                });

            // Fetch messages related to the task
            $messages = MessageTaskChat::with(['message.senderReceiverOne.sender', 'message.attachments:id,message_id,attachment_name,attachment_path'])
                ->where('task_id', $taskId)
                ->get();


            $mappedMessages = $messages->map(function ($taskChat) use ($loginUserId, $request) {
                $message = $taskChat->message;
                $senderReceiver = $message->senderReceiverOne;
                $sender = $senderReceiver->sender;

                $messageDetails = $message->message;
                if ($message->attachment_type !== null) {
                    $messageDetails = $message->attachments[0];
                }

                return [
                    'messageId' => $message->id,
                    'messageType' => $message->message_type,
                    'attachmentType' => $message->attachment_type,
                    'date' => @$request->timezone ? Carbon::parse($message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->created_at)->format('Y-m-d H:i:s'),
                    'time' => @$request->timezone ? Carbon::parse($message->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->created_at)->format('h:i a'),
                    'sentBy' => $sender->id == $loginUserId ? 'loginUser' : 'User',
                    'messageDetails' => $messageDetails,
                    'senderId' => $sender->id,
                    'name' => "{$sender->first_name} {$sender->last_name}",
                    'profilePic' => @$sender->profile ? setAssetPath('user-profile/' . $sender->profile) : setAssetPath('assets/media/avatars/blank.png'),
                ];
            });

            $groupedChat = $mappedMessages->groupBy(function ($message) {
                $carbonDate = Carbon::parse($message['date']);
                if ($carbonDate->isToday()) {
                    return 'Today';
                } elseif ($carbonDate->isYesterday()) {
                    return 'Yesterday';
                } else {
                    return $carbonDate->format('d M Y');
                }
            })->map(function ($messages, $date) {
                $sortedMessages = $messages->sort(function ($a, $b) {
                    $timeA = strtotime($a['time']);
                    $timeB = strtotime($b['time']);

                    if ($timeA == $timeB) {
                        return $a['messageId'] <=> $b['messageId'];
                    }

                    return $timeA <=> $timeB;
                })->values();

                return [$date => $sortedMessages];
            });

            //$reversedGroupedChat = array_reverse($groupedChat->toArray());

            $chat = [];
            foreach ($groupedChat as $item) {
                foreach ($item as $date => $messages) {
                    $chat[$date] = $messages;
                }
            }
            $taskDetails = $task->toArray();
            $taskDetails['userList'] = $userList;
            $data = [
                'status_code' => 200,
                'message' => "Get Data Successfully!",
                'data' => [
                    'task' => $taskDetails,
                    'chat' => $chat,
                ]
            ];

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => date("Y-m-d H:i:s")
            ]);
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/add-reminder",
     *     summary="Add a new Reminder",
     *     tags={"Messages"},
     *     description="Create a new message along with its sender/receiver, attachments, tasks, locations, and meetings.",
     *     operationId="addReminder",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"title","date","time"},
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     example="text",
     *                     description="Enter Reminder Title"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     example="",
     *                     description="Enter Reminder Description"
     *                 ),
     *                 @OA\Property(
     *                     property="date",
     *                     type="string",
     *                     example="2024-06-15",
     *                     description="Enter Reminder Date"
     *                 ),
     *                 @OA\Property(
     *                     property="time",
     *                     type="string",
     *                     example="18:30:00",
     *                     description="Enter Reminder Time"
     *                 ),
     *                 @OA\Property(
     *                     property="users",
     *                     type="string",
     *                     example="1,2,3,4",
     *                     description="Comma-separated IDs of the user"
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

    public function addReminder(Request $request)
    {
        try {
            $rules = [
                'title' => 'required|string',
                'description' => 'nullable|string',
                'date' => 'required|string',
                'time' => 'required|string',
                'users' => 'nullable|string',
            ];

            $message = [
                'title.required' => 'Title is required.',
                'title.string' => 'Title must be an String.',
                'description.string' => 'Description must be an String.',
                'date.required' => 'Date is required.',
                'date.string' => 'Date must be an String.',
                'time.required' => 'Time is required.',
                'time.string' => 'Time must be an String.',
                'users.string' => 'users must be an Comma Separated String.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }
            $receiverIdsArray = $request->users ? explode(',', $request->users) : [];
            $senderId = auth()->user()->id;
            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $reminder = new Reminder();
            $reminder->title = $request->title;
            $reminder->description = $request->description;
            $reminder->date = $request->date;
            $reminder->time = Carbon::parse($request->time)->setTimezone('UTC')->format('H:i:s');
            $reminder->users = $mergedIds;
            $reminder->save();

            $message = new Message();
            $message->message_type = 'Reminder';
            $message->status = 'Unread';
            $message->save();

            $receiverIdsArray = explode(',', $reminder->users);
            $senderId = NULL;
            if (in_array($reminder->created_by, $receiverIdsArray)) {
                $senderId = $reminder->created_by;
            }

            $messageReminder = new MessageReminder();
            $messageReminder->message_id = $message->id;
            $messageReminder->title = $reminder->title;
            $messageReminder->description = $reminder->description;
            $messageReminder->date = $reminder->date;
            $messageReminder->time = $reminder->time;
            $messageReminder->users = $reminder->users;
            $messageReminder->created_by = $reminder->created_by;
            $messageReminder->save();

            foreach ($receiverIdsArray as $receiverId) {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $message->id;
                $messageSenderReceiver->sender_id = $senderId;
                $messageSenderReceiver->receiver_id = $receiverId;
                $messageSenderReceiver->save();


                $messageForNotification = [
                    'id' => $message->id,
                    'sender' => $senderId,
                    'receiver' => $receiverId,
                    'message_type' => "Reminder",
                    'title' => $request->title,
                    'description' => @$request->description ? $request->description : NULL,
                    'date' => @$request->date ? Carbon::parse($request->date)->format('Y-m-d') : NULL,
                    'time' => @$request->time ? Carbon::parse($request->time)->format('H:i:s') : NULL,
                    'users' => $mergedIds,
                ];

                broadcast(new MessageSent($messageForNotification))->toOthers();

                //Push Notification
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
                    'body' => 'Reminder: ' . @$request->title ? $request->title : '',
                    'image' => "",
                ];

                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $messageForNotification);
                }
            }

            $data = [
                'status_code' => 200,
                'message' => "Add Reminder Successfully!",
                'data' => []
            ];

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => date("Y-m-d H:i:s")
            ]);
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }


    /**
     * @OA\Post(
     *     path="/api/v1/forward-message",
     *     summary="Forward Message",
     *     tags={"Messages"},
     *     description="Forward Messages",
     *     operationId="forwardMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id","user_id"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter message id"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter UserId"
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

    public function forwardMessage(Request $request)
    {
        try {
            $rule = [
                'message_id' => 'required|string',
                'user_id' => 'required|string',
            ];
            $message = [
                'message_id.required' => 'Message Id is required.',
                'message_id.string' => 'Message Id must be an string.',
                'user_id.required' => 'User Id is required.',
                'user_id.string' => 'User Id must be an Comma Separated String.',
            ];

            $validator = Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }
            $mergedIds = explode(',', $request->message_id);
            $users = explode(',', $request->user_id);
            foreach ($mergedIds as $singleMessage) {
                $message = new Message();
                $messageDetails = $message->find($singleMessage);
                if (!empty($messageDetails)) {
                    $message->message_type = $messageDetails->message_type;
                    $message->attachment_type = $messageDetails->attachment_type;
                    $message->message = $messageDetails->message;
                    $message->status = 'Unread';
                    $message->save();
                    if ($messageDetails->message_type == 'Text') {
                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => $sender_id,
                                'receiver' => $receiver_id,
                                'message_type' => $messageDetails->message_type,
                                'message' => $messageDetails->message,
                            ];

                            //Pusher
                            broadcast(new MessageSent($message))->toOthers();

                            //Push Notification
                            $validationResults = validateToken($request->receiver_id);
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
                                'body' => $messageDetails->message,
                                'image' => '',
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    } elseif ($messageDetails->message_type == 'Attachment') {
                        $messageAttachment = new MessageAttachment();
                        $AttachmentDetails = $messageAttachment->where('message_id', $singleMessage)->first();
                        if (!empty($AttachmentDetails)) {
                            $messageAttachment->attachment_name = $AttachmentDetails->attachment_name;
                            $messageAttachment->attachment_path = $AttachmentDetails->attachment_path;
                            $messageAttachment->message_id = $message->id;
                            $messageAttachment->save();
                        }

                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => auth()->user()->id,
                                'receiver' => $receiver_id,
                                'message_type' => $messageDetails->message_type,
                                'attachment_type' => $messageDetails->attachment_type,
                                'attachment_name' => $AttachmentDetails->attachment_name,
                                'attachment_path' => $AttachmentDetails->attachment_path,
                            ];

                            broadcast(new MessageSent($message))->toOthers();

                            //Push Notification
                            $validationResults = validateToken($request->receiver_id);
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
                                'body' => $messageDetails->message_type,
                                'image' => $AttachmentDetails->attachment_path,
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    } elseif ($messageDetails->message_type == 'Location') {
                        $messageLocation = new MessageLocation();
                        $locationDetails = $messageLocation->where('message_id', $singleMessage)->first();
                        if (!empty($locationDetails)) {
                            $messageLocation->latitude = $locationDetails->latitude;
                            $messageLocation->longitude = $locationDetails->longitude;
                            $messageLocation->location_url = $locationDetails->location_url;
                            $messageLocation->message_id = $message->id;
                            $messageLocation->save();
                        }

                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => auth()->user()->id,
                                'receiver' => $receiver_id,
                                'message_type' => $messageDetails->message_type,
                                'latitude' => $locationDetails->latitude,
                                'latitude' => $locationDetails->latitude,
                                'location_url' => @$locationDetails->location_url ? $locationDetails->location_url : NULL
                            ];

                            broadcast(new MessageSent($message))->toOthers();

                            //Push Notification
                            $validationResults = validateToken($request->receiver_id);
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
                                'body' => $messageDetails->message_type,
                                'image' => "",
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    } elseif ($messageDetails->message_type == 'Meeting') {
                        $messageMeeting = new MessageMeeting();
                        $meetingDetails = $messageMeeting->where('message_id', $singleMessage)->first();
                        if (!empty($meetingDetails)) {
                            $messageMeeting->mode = $meetingDetails->mode;
                            $messageMeeting->title = $meetingDetails->title;
                            $messageMeeting->description = $meetingDetails->description;
                            $messageMeeting->date = $meetingDetails->date;
                            $messageMeeting->start_time = $meetingDetails->start_time;
                            $messageMeeting->end_time = $meetingDetails->end_time;
                            $messageMeeting->meeting_url = $meetingDetails->meeting_url;
                            $messageMeeting->users = $meetingDetails->users;
                            $messageMeeting->latitude = $meetingDetails->latitude;
                            $messageMeeting->longitude = $meetingDetails->longitude;
                            $messageMeeting->location_url = $meetingDetails->location_url;
                            $messageMeeting->location = $meetingDetails->location;
                            $messageMeeting->message_id = $message->id;
                            $messageMeeting->save();
                        }

                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => auth()->user()->id,
                                'receiver' => $receiver_id,
                                'message_type' => $messageDetails->message_type,
                                'mode' => $meetingDetails->mode,
                                'title' => $meetingDetails->title,
                                'description' => @$meetingDetails->description ? $meetingDetails->description : NULL,
                                'date' => @$meetingDetails->date ? Carbon::parse($meetingDetails->date)->format('Y-m-d') : NULL,
                                'start_time' => @$meetingDetails->start_time ? $meetingDetails->start_time : NULL,
                                'end_time' => @$meetingDetails->end_time ? $meetingDetails->end_time : NULL,
                                'meeting_url' => @$meetingDetails->meeting_url ? $meetingDetails->meeting_url : NULL,
                                'latitude' => @$meetingDetails->latitude ? $meetingDetails->latitude : NULL,
                                'longitude' => @$meetingDetails->longitude ? $meetingDetails->longitude : NULL,
                                'location_url' => @$meetingDetails->location_url ? $meetingDetails->location_url : NULL,
                                'location' => @$meetingDetails->location ? $meetingDetails->location : NULL,
                            ];

                            broadcast(new MessageSent($message))->toOthers();

                            //Push Notification
                            $validationResults = validateToken($receiver_id);
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
                                'body' => 'Meeting: ' . @$meetingDetails->title ? $meetingDetails->title : '',
                                'image' => "",
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    } elseif ($messageDetails->message_type == 'Task') {
                        $messageTask = new MessageTask();
                        $taskDetails = $messageTask->where('message_id', $singleMessage)->first();
                        if (!empty($taskDetails)) {
                            $messageTask->message_id = $message->id;
                            $messageTask->task_name = $taskDetails->task_name;
                            $messageTask->task_description = $taskDetails->task_description;
                            $messageTask->users = $taskDetails->users;
                            $messageLocation->save();
                        }

                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => auth()->user()->id,
                                'receiver' => $receiver_id,
                                'message_type' => $messageDetails->message_type,
                                'task_name' => $taskDetails->task_name,
                                'task_description' => @$taskDetails->task_description ? $taskDetails->task_description : NULL,
                            ];

                            broadcast(new MessageSent($message))->toOthers();


                            //Push Notification
                            $validationResults = validateToken($receiver_id);
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
                                'body' => 'Task : ' . $taskDetails->task_name,
                                'image' => "",
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    } elseif ($messageDetails->message_type == 'Reminder') {
                        $messageReminder = new MessageReminder();
                        $reminderDetails = $messageReminder->where('message_id', $singleMessage)->first();
                        if (!empty($reminderDetails)) {
                            $messageReminder->message_id = $message->id;
                            $messageReminder->title = $reminderDetails->title;
                            $messageReminder->description = $reminderDetails->description;
                            $messageReminder->date = $reminderDetails->date;
                            $messageReminder->time = $reminderDetails->time;
                            $messageReminder->users = $reminderDetails->users;
                            $messageReminder->save();
                        }

                        foreach ($users as $singleUser) {
                            $sender_id = auth()->user()->id;
                            $receiver_id = $singleUser;

                            $message = [
                                'id' => $message->id,
                                'sender' => $sender_id,
                                'receiver' => $receiver_id,
                                'message_type' => "Reminder",
                                'title' => $reminderDetails->title,
                                'description' => @$reminderDetails->description ? $reminderDetails->description : NULL,
                                'date' => @$reminderDetails->date ? Carbon::parse($reminderDetails->date)->format('Y-m-d') : NULL,
                                'time' => @$reminderDetails->time ? Carbon::parse($reminderDetails->time)->format('H:i:s') : NULL
                            ];

                            broadcast(new MessageSent($message))->toOthers();

                            //Push Notification
                            $validationResults = validateToken($receiver_id);
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
                                'body' => 'Reminder: ' . @$reminderDetails->title ? $reminderDetails->title : '',
                                'image' => "",
                            ];

                            if (count($validTokens) > 0) {
                                sendPushNotification($validTokens, $notification, $message);
                            }
                        }
                    }
                }
                foreach ($users as $singleUser) {
                    $messageSenderReceiver = new MessageSenderReceiver();
                    $messageSenderReceiver->message_id = $message->id;
                    $messageSenderReceiver->sender_id = auth()->user()->id;
                    $messageSenderReceiver->receiver_id = $singleUser;
                    $messageSenderReceiver->save();
                }
            }

            $data = [
                'status_code' => 200,
                'message' => "Forward Message Successfully!",
                'data' => []
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
                'created_at' => date("Y-m-d H:i:s")
            ]);
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/message-contact",
     *     summary="Message Contact",
     *     tags={"Messages"},
     *     description="Message Contact",
     *     operationId="messageContact",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"contactDetails"},
     *                 @OA\Property(
     *                     property="contact_details",
     *                     type="string",
     *                     example="",
     *                     description="Enter Contact Json"
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="",
     *                     description="Enter Receiver Id"
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

    public function contactDetails(Request $request)
    {
        try {

            $rules = [
                'contact_details' => 'required|json',
            ];
            $message = [
                'contact_details.required' => 'Enter Contact Details',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }

            $contactDetails = $request->contact_details;
            $message = new Message();
            $message->message = $contactDetails;
            $message->message_type = 'Contact';
            $message->attachment_type = NULL;
            $message->status = 'Unread';
            $message->save();

            $messageSenderReceiver = new MessageSenderReceiver();
            $messageSenderReceiver->message_id = $message->id;
            $messageSenderReceiver->sender_id = auth()->user()->id;
            $messageSenderReceiver->receiver_id = $request->receiver_id;
            $messageSenderReceiver->save();

            $message = [
                'id' => $message->id,
                'sender' => auth()->user()->id,
                'receiver' => $request->receiver_id,
                'message_type' => "Contact",
                'message' => $contactDetails,
            ];

            //Pusher
            broadcast(new MessageSent($message))->toOthers();

            //Push Notification
            $validationResults = validateToken($request->receiver_id);
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
                'body' => "Contact",
                'image' => '',
            ];

            if (count($validTokens) > 0) {
                sendPushNotification($validTokens, $notification, $message);
            }
            $data = [
                'status_code' => 200,
                'message' => "Contact Shared Successfully!",
                'data' => [
                    'contactDetails' => $message
                ]
            ];
            return $this->sendJsonResponse($data);
        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/task-users-list",
     *     summary="Task Users List",
     *     tags={"Messages"},
     *     description="Task Users List",
     *     operationId="taskUserList",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Task user List Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"type"},
     *                 @OA\Property(
     *                     property="type",
     *                     type="string",
     *                     example="Receive",
     *                     description="Enter Type (Receive,Given,All Task)"
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


    public function taskUserList(Request $request)
    {
        try {

            $rules = [
                'type' => 'required|string|in:Receive,Given,All Task',
            ];

            $message = [
                'type.required' => 'The type field is required.',
                'type.string' => 'The type field must be a string.',
                'type.in' => 'The selected type is invalid. Valid options are: Receive, Given, All Task.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }

            $type = $request->type;
            $loginUser = auth()->user()->id;

            $baseQuery = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
                ->whereHas('message', function ($query) {
                    $query->where('message_type', 'Task');
                });
            if ($type == 'Receive') {
                $userList = (clone $baseQuery)
                    ->where('receiver_id', $loginUser)
                    ->get();
            } elseif ($type == 'Given') {
                $userList = (clone $baseQuery)
                    ->where('sender_id', $loginUser)
                    ->get();
            } elseif ($type == 'All Task') {
                $userList = (clone $baseQuery)
                    ->where(function ($query) use ($loginUser) {
                        $query->where('sender_id', $loginUser)
                            ->orWhere('receiver_id', $loginUser);
                    })
                    ->get();
            }
            $result = $userList->map(function ($messageSenderReceiver) use ($loginUser) {
                $sender = $messageSenderReceiver->sender;
                $receiver = $messageSenderReceiver->receiver;

                if ($sender && $sender->id != $loginUser) {
                    $profileUrl = $sender->profile ? setAssetPath('user-profile/' . $sender->profile) : setAssetPath('assets/media/avatars/blank.png');
                    if ($messageSenderReceiver->updated_by == 1) {
                        $response['taskStatus'] = true;
                    }else 
                    {
                        $response['taskStatus'] = false;
                    }
                    return [
                        'id' => $sender->id,
                        'name' => $sender->first_name . ' ' . $sender->last_name,
                        'profile' => $profileUrl,
                        'taskStatus' => $response['taskStatus'],
                    ];
                }

                if ($receiver && $receiver->id != $loginUser) {
                    $profileUrl = $receiver->profile ? setAssetPath('user-profile/' . $receiver->profile) : setAssetPath('assets/media/avatars/blank.png');

                    if ($messageSenderReceiver->updated_by == 1) {
                        $response['taskStatus'] = true;
                    }else{
                        $response['taskStatus'] = false;
                    }

                    return [
                        'id' => $receiver->id,
                        'name' => $receiver->first_name . ' ' . $receiver->last_name,
                        'profile' => $profileUrl,
                        'taskStatus' => $response['taskStatus'],
                    ];

                }

                return null;
            });
            $uniqueResult = $result->filter()->unique('id')->values();


            $data = [
                'status_code' => 200,
                'message' => "Task User List Get Successfully!",
                'data' => [
                    'userList' => $uniqueResult
                ]
            ];
            return $this->sendJsonResponse($data);
        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sent-task-summary-email",
     *     summary="Task Summary Email",
     *     tags={"Messages"},
     *     description="Task Summary Email",
     *     operationId="taskSummaryEmail",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Task Summary Email Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"type","user_id","summary"},
     *                 @OA\Property(
     *                     property="type",
     *                     type="string",
     *                     example="Receive",
     *                     description="Enter Type (Receive,Given,All Task)"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
     *                 ),
     *                 @OA\Property(
     *                     property="summary",
     *                     type="string",
     *                     example="Lorem Ipsum is simply dummy text of the printing and typesetting industry.",
     *                     description="Enter Summary"
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

     public function sentTaskSummaryEmail(Request $request)
    {
        try {

            $rules = [
                'type' => 'required|string|in:Receive,Given,All Task',
                'user_id' => 'required|string|regex:/^(\d+)(,\d+)*$/',
                'summary' => 'required|string',
            ];

            $message = [
                'type.required' => 'The type field is required.',
                'type.string' => 'The type field must be a string.',
                'type.in' => 'The selected type is invalid. Valid options are: Receive, Given, All Task.',
                'user_id.required' => 'The user_id field is required.',
                'user_id.string' => 'The user_id field must be a string.',
                'user_id.regex' => 'The user_id field must be a comma-separated list of integers.',
                'summary.required' => 'The summary field is required.',
                'summary.string' => 'The summary field must be a string.',
            ];


            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }

            $recipient = explode(',', $request->user_id);
            $email = [];
            foreach ($recipient as $single) {
                $user = User::where('id', $single)->first()->email;
                if (!empty($user) && $user !== 'null') {
                    $email[] = $user;
                }
            }
            $summary = $request->summary;
            if(count($email) > 0){
                foreach ($email as $singleEmail) {
                    if (!empty($singleEmail)) {
                        Mail::to($singleEmail)->send(new taskMail($summary));
                    }
                }
            }else{
                $data = [
                    'status_code' => 400,
                    'message' => "Selected User Email Address Not Added!",
                    'data' => []
                ];
                return $this->sendJsonResponse($data);
            }

            $data = [
                'status_code' => 200,
                'message' => "Email Sent Successfully!",
                'data' => []
            ];
            return $this->sendJsonResponse($data);
        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
    }


        /**
     * @OA\Post(
     *     path="/api/v1/sent-task-done",
     *     summary="sent task done",
     *     tags={"Messages"},
     *     description="Task done",
     *     operationId="taskDone",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Task Done Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"type","user_id","summary"},
     *                 @OA\Property(
     *                     property="type",
     *                     type="string",
     *                     example="Receive",
     *                     description="Enter Type (Receive)"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
     *                 ),
     *                 @OA\Property(
     *                     property="summary",
     *                     type="string",
     *                     example="Lorem Ipsum is simply dummy text of the printing and typesetting industry.",
     *                     description="Enter Summary"
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

     public function sentTaskDone(Request $request)
    {
        try {

            $rules = [
                'type' => 'required|string|in:Receive,Given,All Task',
                'user_id' => 'required|string|regex:/^(\d+)(,\d+)*$/',
                'summary' => 'required|string',
            ];

            $message = [
                'type.required' => 'The type field is required.',
                'type.string' => 'The type field must be a string.',
                'type.in' => 'The selected type is invalid. Valid options are: Receive, Given, All Task.',
                'user_id.required' => 'The user_id field is required.',
                'user_id.string' => 'The user_id field must be a string.',
                'user_id.regex' => 'The user_id field must be a comma-separated list of integers.',
                'summary.required' => 'The summary field is required.',
                'summary.string' => 'The summary field must be a string.',
            ];


            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }

            $recipient = explode(',', $request->user_id);
            $loginUser = auth()->user()->id;

            foreach ($recipient as $single) {
                $user = User::where('id', $single)->first();
                if (!empty($user) && $user !== 'null') {
                    $type = $request->type;    
                  
                    $baseQuery = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
                        ->whereHas('message', function ($query) {
                            $query->where('message_type', 'Task');
                        });

                    if ($type == 'Receive') {
                        $userList = (clone $baseQuery)
                            ->where('receiver_id', $loginUser)->where('sender_id', $single)
                            ->update(['updated_by' => 1]);
                    }
                }
            }
        
            $data = [
                'status_code' => 200,
                'message' => "Task mark as done!",
                'data' => []
            ];
            return $this->sendJsonResponse($data);
        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
    }

}



