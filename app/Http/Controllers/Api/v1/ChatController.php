<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageSenderReceiver;
use App\Models\MessageTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Spatie\ImageOptimizer\OptimizerChainFactory;

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
}
