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
    Option
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
     *     path="/api/v1/update-text-message",
     *     summary="Update an existing message",
     *     tags={"Messages"},
     *     description="Update the content of an existing message by its message ID.",
     *     operationId="updateMessage",
     *     security={{"bearerAuth":{}}}, 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Update Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id", "message", "timezone"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="integer",
     *                     example=1,
     *                     description="ID of the message to be updated"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     example="This is an updated test message.",
     *                     description="Updated content of the message",
     *                     nullable=true
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="America/New_York",
     *                     description="Timezone of the user making the request",
     *                     nullable=true
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Message not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid Request"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized to update message"
     *     ),
     * )
     */


     public function updateTextMessage(Request $request)
     {
         try {
             $rules = [
                 'message_id' => 'required|integer|exists:message,id',
                 'message' => 'required|string',
                 'timezone' => 'required|string|timezone' // New validation rule for timezone
             ];
     
             $messages = [
                 'message_id.required' => 'The message ID is required.',
                 'message_id.integer' => 'The message ID must be an integer.',
                 'message_id.exists' => 'The message ID does not exist.',
                 'message.required' => 'The message content is required.',
                 'message.string' => 'The message content must be a string.',
                 'timezone.required' => 'The timezone is required.',
                 'timezone.string' => 'The timezone must be a string.',
                 'timezone.timezone' => 'The timezone must be a valid timezone identifier.'
             ];
     
             $validator = Validator::make($request->all(), $rules, $messages);
             if ($validator->fails()) {
                 return $this->sendJsonResponse([
                     'status_code' => 400,
                     'message' => $validator->errors()->first(),
                     'data' => ""
                 ]);
             }        
     
             $msg = Message::where('id', $request->message_id)->first();
     
             if (!$msg) {
                 return response()->json(['status_code' => 404, 'message' => 'Message not found'], 404);
             }
     
             // Check if the logged-in user is both the sender and creator of the message
             $messageSenderReceiver = MessageSenderReceiver::where('message_id', $msg->id)
                 ->where('sender_id', auth()->user()->id) // Ensure the logged-in user is the sender
                 ->first();
     
             if (!$messageSenderReceiver) {
                 return response()->json(['status_code' => 403, 'message' => 'You are not authorized to edit this message'], 403);
             }    
     
            // Update only the message field without modifying the updated_at field
            $msg->timestamps = false; // Disable automatic timestamps
            $msg->message = $request->message;
            $msg->save();
            $msg->timestamps = true; // Re-enable timestamps after the operation
            // Prepare the time in the requested timezone or default to UTC
            $formattedTime = $request->timezone 
                ? Carbon::parse($msg->updated_at)->setTimezone($request->timezone)->format('h:i a') 
                : Carbon::parse($msg->updated_at)->format('h:i a');

            // Response data
            $data = [
                'status_code' => 200,
                'message' => 'Message updated successfully!',
                'data' => [
                    'id' => $msg->id,
                    'updated_message' => $msg->message,
                    // 'updated_at' => Carbon::parse($msg->updated_at)->toDateTimeString(),
                    'time' => $formattedTime // Return the updated time in the specified timezone
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
     *     path="/api/v1/group-text-message",
     *     summary="Add a new group message",
     *     tags={"Messages"},
     *     description="Create a new group message along with its sender, receiver group, and push notifications.",
     *     operationId="groupTextMessage",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Group Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type", "group_id", "message"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="text",
     *                     description="Type of the message"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     example="This is a group message.",
     *                     description="Content of the message",
     *                     nullable=true
     *                 ),
     *                 @OA\Property(
     *                     property="group_id",
     *                     type="integer",
     *                     example="1",
     *                     description="ID of the group"
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

    public function groupTextMessage(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'message' => 'required|string',
                'group_id' => 'required|integer',
            ];

            $messages = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'message.required' => 'The message content is required.',
                'message.string' => 'The message content must be a string.',
                'group_id.required' => 'The group ID is required.',
                'group_id.integer' => 'The group ID must be an integer.',
            ];

            $validator = Validator::make($request->all(), $rules, $messages);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            // Create the message
            $msg = new Message();
            $msg->message_type = $request->message_type;
            $msg->group_id = $request->group_id; 
            $msg->message = $request->message;
            $msg->status = "Unread";
            $msg->save();

            // Fetch group members
            $groupMembers = GroupMembers::where('group_id', $request->group_id)->pluck('user_id')->toArray();
            if (empty($groupMembers)) {
                return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No members found in this group']);
            }

            // Send the message to all group members
            foreach ($groupMembers as $memberId) {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = auth()->user()->id;
                $messageSenderReceiver->receiver_id = $memberId;
                $messageSenderReceiver->save();
            }

            // Create message payload for broadcasting and notifications
            $message = [
                'id' => $msg->id,
                'sender' => auth()->user()->id,
                'group_id' => $request->group_id,
                'message_type' => $request->message_type,
                'message' => $request->message,
                "screen" => "groupchat"
            ];

            // Pusher: Broadcast the message to group members
            broadcast(new MessageSent($message))->toOthers();

            // Push Notification
            $validTokens = [];
            $invalidTokens = [];

            foreach ($groupMembers as $memberId) {
                $validationResults = validateToken($memberId);
                foreach ($validationResults as $result) {
                    $validTokens = array_merge($validTokens, $result['valid']);
                    $invalidTokens = array_merge($invalidTokens, $result['invalid']);
                }
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
                'message' => 'Group Message Sent Successfully!',
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
     *     path="/api/v1/group/question-with-options",
     *     summary="Send a question with options to a group",
     *     description="This endpoint allows sending a question with multiple choice options to a specific group. The options are provided as an array.",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data", 
     *             @OA\Schema(
     *                 type="object",
     *                 required={"message_type", "message", "group_id"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     description="The type of message being sent, which should be 'Options' for questions with multiple choices.",
     *                     example="Options"
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     description="The question content for the group, asking them to choose from options.",
     *                     example="What is your favorite color?"
     *                 ),
     *                 @OA\Property(
     *                     property="group_id",
     *                     type="integer",
     *                     description="The ID of the group to which the message will be sent.",
     *                     example=123
     *                 ),
     *                 @OA\Property(
     *                     property="options[]", 
     *                     type="array",
     *                     description="The options for the users to choose from, required if message_type is 'options'.",
     *                     @OA\Items(
     *                         type="string",
     *                         example="Red"
     *                     ),
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Question with options sent successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Group Message Sent Successfully!"),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error for missing or invalid parameters.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="The message content is required."),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No members found in the group.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="No members found in this group."),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong."),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     )
     * )
     */

    public function questionWithOptions(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string',
                'message' => 'required|string', // The question or content related to the options
                'group_id' => 'required|integer',
                'options' => 'nullable|array', // Optional: Array of options if message type is 'options'
                'options.*' => 'string', // Each option should be a string
            ];

            $messages = [
                'message_type.required' => 'The message type is required.',
                'message_type.string' => 'The message type must be a string.',
                'message.required' => 'The message content is required.',
                'message.string' => 'The message content must be a string.',
                'group_id.required' => 'The group ID is required.',
                'group_id.integer' => 'The group ID must be an integer.',
                'options.array' => 'The options should be an array of strings.',
                'options.*.string' => 'Each option should be a string.',
            ];

            $validator = Validator::make($request->all(), $rules, $messages);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            // Create the message with type 'options'
            $msg = new Message();
            $msg->message_type = 'Options'; // Set message type to options
            $msg->group_id = $request->group_id; 
            $msg->message = $request->message; // Store the question or prompt
            $msg->status = "Unread";
            $msg->save();
            
            $result = [];
            foreach ($request->options as $item) {
                $parsed = str_getcsv($item); // Automatically handles quoted strings
                $result[] = $parsed;
            }

            // if ($request->has('options') && is_array($request->options)) {
            //     foreach ($result[0] as $option) {
            //         // Ensure the option is a valid string and not empty
            //         if (!empty($option)) {
            //             // Check if this option already exists for the same message
            //             $existingOption = Option::where('message_id', $msg->id)
            //                                     ->where('option', $option)
            //                                     ->first();
            
            //             if (!$existingOption) {
            //                 // Save the option with a unique ID
            //                 $msgOption = new Option();
            //                 $msgOption->message_id = $msg->id;       // Link to the message
            //                 $msgOption->option = $option;           // Individual option text
            //                 $msgOption->option_id = (string) Str::uuid(); // Unique option ID
            //                 $msgOption->save();                     // Save to database
            //             }
            //         }
            //     }
            // }
        
            if ($request->has('options') && is_array($request->options)) {
                // Fetch all existing options for the message in one query
                $existingOptions = Option::where('message_id', $msg->id)
                                         ->pluck('option')
                                         ->toArray();
            
                // Prepare data for new options
                $optionData = [];
                foreach ($result[0] as $option) {
                    if (!empty($option) && !in_array($option, $existingOptions)) {
                        $optionData[] = [
                            'message_id' => $msg->id,
                            'option' => $option,
                            'option_id' => (string) Str::uuid(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            
                // Bulk insert new options
                if (!empty($optionData)) {
                    Option::insert($optionData);
                }
            }
            
            // Fetch group members
            $groupMembers = GroupMembers::where('group_id', $request->group_id)->pluck('user_id')->toArray();

            $groupMembers = array_unique($groupMembers);
            
            if (empty($groupMembers)) {
                return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No members found in this group']);
            }

            // Send the message to all group members
            foreach ($groupMembers as $memberId) {
                $messageSenderReceiver = new MessageSenderReceiver();
                $messageSenderReceiver->message_id = $msg->id;
                $messageSenderReceiver->sender_id = auth()->user()->id;
                $messageSenderReceiver->receiver_id = $memberId;
                $messageSenderReceiver->save();
            }

            // Create message payload for broadcasting and notifications
            $message = [
                'message_id' => $msg->id,
                'sender' => auth()->user()->id,
                'group_id' => $request->group_id,
                'message_type' => 'Options',
                'message' => $request->message, // Question or prompt
                'options' => $result[0], // Include options
                "screen" => "groupchat"
            ];

          // Pusher: Broadcast the message to group members
            broadcast(new MessageSent($message))->toOthers();

            // Push Notification
            $validTokens = [];
            $invalidTokens = [];

            foreach ($groupMembers as $memberId) {
                $validationResults = validateToken($memberId);
                foreach ($validationResults as $result) {
                    $validTokens = array_merge($validTokens, is_array($result['valid']) ? $result['valid'] : []);
                    $invalidTokens = array_merge($invalidTokens, is_array($result['invalid']) ? $result['invalid'] : []);
                }
            }
        
            if (count($invalidTokens) > 0) {                
                foreach ($invalidTokens as $singleInvalidToken) {
                    UserDeviceToken::where('token', $singleInvalidToken)->forceDelete();
                }
            }

            $notification = [
                'title' => auth()->user() ? auth()->user()->first_name . ' ' . auth()->user()->last_name : 'Unknown User',
                'body' => $request->message,
                'image' => "", // Update with a default image if required
            ];         

            if (count($validTokens) > 0) {
                sendPushNotification(array_unique($validTokens), $notification, $message);
            }

            $data = [
                'status_code' => 200,
                'message' => 'Group Options Message Sent Successfully!',
                'data' => $message
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
     *     path="/api/v1/group/select-option",
     *     summary="User selects an option (True/False)",
     *     description="This API allows a user to select or deselect an option for a particular option ID. If True, the user is added to the users list, if False, the user is removed from the list.",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data", 
     *             @OA\Schema(
     *                 required={"option_id", "selected"},
     *                 @OA\Property(property="option_id", type="string", description="The ID of the option being selected."),
     *                 @OA\Property(property="selected", type="boolean", description="True to add the user to the option, False to remove the user from the option.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Option selection updated successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Option selection updated successfully!"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error (missing required fields or invalid data).",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="Option ID is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Option not found.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="Option not found.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong.")
     *         )
     *     )
     * )
     */
        
     public function selectOption(Request $request)
     {
         try {
             // Validation
             $rules = [
                 'option_id' => 'required|string|exists:options,option_id', // Ensure the option exists
                 'selected' => 'required|in:true,false', // Accept string "true" or "false"
             ];
     
             $messages = [
                 'option_id.required' => 'Option ID is required.',
                 'option_id.string' => 'Option ID must be a string.',
                 'option_id.exists' => 'The selected option does not exist.',
                 'selected.required' => 'Selection is required.',
                 'selected.in' => 'Selection must be either true or false.',
             ];
     
             $validator = Validator::make($request->all(), $rules, $messages);
             if ($validator->fails()) {
                 $data = [
                     'status_code' => 400,
                     'message' => $validator->errors()->first(),
                     'data' => ""
                 ];
                 return $this->sendJsonResponse($data);
             }
     
             // Get the selected option
             $option = Option::where('option_id', $request->option_id)->first();
     
             if (!$option) {
                 return $this->sendJsonResponse(['status_code' => 404, 'message' => 'Option not found']);
             }
     
             // Get the existing users (if any) in the `users` column
             $users = explode(',', $option->users); // Convert comma-separated list to array
     
             // Convert selected to boolean
             $selected = filter_var($request->selected, FILTER_VALIDATE_BOOLEAN);
     
             if ($selected) {
                 // Add user if not already in the list
                 if (!in_array(auth()->user()->id, $users)) {
                     $users[] = auth()->user()->id; // Add user to the array
                 }
             } else {
                 // Remove user if they exist in the list
                 $users = array_filter($users, function($userId) {
                     return $userId != auth()->user()->id; // Filter out the current user's ID
                 });
     
                 // Re-index the array after removal to avoid gaps
                 $users = array_values($users); // Reset array keys after filtering
             }
     
             // After filtering, if users list is empty, set it to an empty string
             $usersString = implode(',', $users); // Convert array back to a comma-separated string
             
             // Trim leading/trailing commas
             $usersString = trim($usersString, ',');
     
             // If the string is empty, set it as an empty string
             if (empty($usersString)) {
                 $usersString = '';
             }
     
             // Save the updated users list
             $option->users = $usersString;
             $option->save();
     
             // Return success response
             $data = [
                 'status_code' => 200,
                 'message' => 'Option selection updated successfully!',
                 'data' => $option,
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
     * @OA\Get(
     *     path="/api/v1/group/votes/fetch",
     *     summary="Fetch votes for a message by message_id",
     *     description="This endpoint fetches the vote details for a given message_id, including the question and the options with vote counts.",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="message_id",
     *         in="query",
     *         description="The ID of the message to fetch votes for",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vote details fetched successfully!",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=200
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Vote details fetched successfully!"
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="question",
     *                     type="string",
     *                     example="What is your favorite programming language?"
     *                 ),
     *                 @OA\Property(
     *                     property="options",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(
     *                             property="option",
     *                             type="string",
     *                             example="PHP"
     *                         ),
     *                         @OA\Property(
     *                             property="vote_count",
     *                             type="integer",
     *                             example=5
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request: Missing or invalid message_id",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=400
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="The 'message_id' parameter is required."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Not Found: No options message found for the provided message_id",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=404
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="No options message found for the provided message_id"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=500
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Something went wrong"
     *             )
     *         )
     *     )
     * )
     */

    public function fetchVotes(Request $request)
    {
        try {
            // Validate the incoming request to ensure message_id is provided
            $validator = Validator::make($request->all(), [
                'message_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return $this->sendJsonResponse([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            // Retrieve the message (question) based on the provided message_id
            $message = Message::where('id', $request->message_id)
                            ->where('message_type', 'Options') // Ensure this is an "Options" type message
                            ->first();

            if (!$message) {
                return $this->sendJsonResponse([
                    'status_code' => 404,
                    'message' => 'No options message found with the provided message_id',
                    'data' => ""
                ]);
            }

            // Fetch the options for this message
            $options = Option::where('message_id', $message->id)->get();

            $optionsWithCounts = $options->map(function($option) {
                // Initialize vote count and user details array
                $voteCount = 0;
                $userDetails = [];
            
                if ($option->users) {

                    // Check if $option->users is null or an empty string before applying explode
                    if ($option->users) {
                        $voteCount = count(explode(',', $option->users));
                    } else {
                        $voteCount = 0; // No users, hence 0 votes
                    }               

                    // Split the comma-separated list of user IDs
                    $userIds = explode(',', $option->users);                                                    
            
                    // Fetch user details (e.g., name, email) for each user ID
                    $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'email', 'profile']);  // Adjust fields as needed
            
                    // Process each user and check if they have a profile
                    $userDetails = $users->map(function($user) {
                    
                    // Set the profile image if available, or use a default image
                    $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                        
                        return [
                            'id' => $user->id,
                            'name' => $user->first_name . $user->last_name,
                            'email' => $user->email,
                            'profile' => $user->profile,
                        ];
                    });
                }
            
                return [
                    'option' => $option->option,
                    'vote_count' => $voteCount,
                    'users' => $userDetails,  // Include user details with profile
                ];
            });                   

            // Prepare the final response including the message (question) and options with counts
            $response = [
                'question' => $message->message,
                'options' => $optionsWithCounts,
            ];

            return $this->sendJsonResponse([
                'status_code' => 200,
                'message' => 'Vote details fetched successfully!',
                'data' => $response
            ]);
        } catch (\Exception $e) {
            // Log any errors that occur
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse([
                'status_code' => 500,
                'message' => 'Something went wrong',
                'data' => ''
            ]);
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
     *                 required={"message_type", "receiver_id", "task_name", "date", "time"},
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
     *                @OA\Property(
     *                     property="task_name",
     *                     type="string",                     
     *                     example="Task name",
     *                     description="Enter Task Name"
     *                 ),
     *                 @OA\Property(
     *                     property="checkbox",
     *                     type="array",
     *                     @OA\Items(
     *                         type="string",
     *                         example="CRUD Module"
     *                     ),
     *                     description="Array of checkbox"
     *                 ),
     *                 @OA\Property(
     *                     property="task_description",
     *                     type="array",                     
     *                     @OA\Items(
     *                         type="string",
     *                         example="Description area"
     *                     ),
     *                     description="Enter Task Description"
     *                 ),
     *                 @OA\Property(
     *                     property="date",
     *                     type="string",
     *                     format="date",
     *                     example="2024-09-19",
     *                     description="Date of the task"
     *                 ),
     *                 @OA\Property(
     *                     property="time",
     *                     type="string",
     *                     format="time",
     *                     example="14:30",
     *                     description="Time of the task"
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message Sent Successfully",
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
                 'task_name' => 'required|string', // Validating each task_name element                 
             ];
     
             $message = [
                 'message_type.required' => 'The message type is required.',
                 'message_type.string' => 'The message type must be a string.',
                 'receiver_id.required' => 'The receiver ID is required.',
                 'receiver_id.string' => 'The receiver ID must be a string.', 
                 'task_name.required' => 'The Task Name is required.',              
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
             $msg->date = $request->date; // Save date
             $msg->time = $request->time; // Save time
             $msg->save();           
     
             $receiverIdsArray = explode(',', $request->receiver_id);
             $senderId = auth()->user()->id;
     
             $receiverIdsArray[] = $senderId;
             $uniqueIdsArray = array_unique($receiverIdsArray);
             $mergedIds = implode(',', $uniqueIdsArray);


             $task_name_Array = explode(',', $request->checkbox);
             $task_name_UArray = array_unique($task_name_Array);
             
             // Ensure task_description is also an array
             $taskDescriptions = $request->task_description ? explode(',', $request->task_description) : [];    
             
             if (empty($task_name_UArray)) {
                 // If the array is empty, create a MessageTask entry with null data
                 $messageTask = new MessageTask();
                 $messageTask->message_id = $msg->id;
                 $messageTask->task_name = null; // or a default value
                 $messageTask->task_description = null; // or a default value
                 $messageTask->checkbox = null; // or a default value
                 $messageTask->users = $mergedIds;
                 $messageTask->save();
             } else {
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
             }             
             
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
                     'task_name' => $request->task_name, // You may want to send all task names here
                     "screen" => "chatinner"
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
            $messageMeeting->users = $mergedIds;            
            if($request->mode == "Online")
            {
                $messageMeeting->meeting_url = @$request->meeting_url ? $request->meeting_url : NULL;
                $messageMeeting->latitude = NULL;
                $messageMeeting->longitude = NULL;
                $messageMeeting->location_url = NULL;
                $messageMeeting->location = NULL;
            }else{
                $messageMeeting->meeting_url = NULL;
                $messageMeeting->latitude = @$request->latitude ? $request->latitude : NULL;
                $messageMeeting->longitude = @$request->longitude ? $request->longitude : NULL;
                $messageMeeting->location_url = @$request->location_url ? $request->location_url : NULL;
                $messageMeeting->location = @$request->location ? $request->location : NULL;
            }
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
     *     @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     )
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
     *     path="/api/v1/unread-message-count",
     *     summary="Get count of unread messages for specified types",
     *     tags={"Messages"},
     *     description="Retrieve the total count of unread messages for the given message_type values (e.g., 'Meetings', 'Task', 'event')",
     *     operationId="getUnreadMessageCount",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Filter by message type",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="Meeting,Task,event",
     *                     description="Comma-separated message types to filter"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Unread message count",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="status_code",
     *                     type="integer",
     *                     example=200
     *                 ),
     *                 @OA\Property(
     *                     property="message",
     *                     type="string",
     *                     example="Unread message count retrieved successfully."
     *                 ),
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(
     *                         property="unread_count",
     *                         type="integer",
     *                         example=5,
     *                         description="Total unread messages count"
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     )
     * )
     */

     public function getUnreadMessageCount(Request $request)
     {
         try {
             $rules = [
                 'message_type' => 'required|string'
             ];
     
             $message = [
                 'message_type.required' => 'The message type is required.',
                 'message_type.string' => 'The message type must be a string.'
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
             
            $lastMessageTask = DB::table('message_task')
                            ->select('id', 'message_id', 'task_name', 'checkbox', 'task_checked', 'task_checked_users', 'task_description', 'users', 'read_status', 'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at')
                            ->where('users', 'LIKE', '%'. $loginUser. '%' )
                            ->orderBy('message_id', 'desc')
                            ->first();
                            
             // Convert message types to an array
             $messageTypes = explode(',', $request->message_type);
             
             // Initialize an array to hold counts
             $unreadCounts = [];
             
             // Loop through each message type and count unread messages
             foreach ($messageTypes as $type) {
                 $trimmedType = trim($type);            
                 
                 // Check if the type is 'Meeting' or 'Task' and query accordingly
                 if ($trimmedType === 'Meeting') {
                    
                     $unreadCounts[$trimmedType] = MessageMeeting::where('users', 'LIKE', '%' . $loginUser . '%')
                            ->where(function ($query) use ($loginUser) {
                                // Check if the event has not been read by the specified user
                                $query->where('read_status', 'NOT LIKE', '%' . $loginUser . '%')
                                    ->orWhereNull('read_status'); // In case 'read_status' is empty or NULL                                    
                            })
                            ->distinct('message_id') // Ensure unique message IDs
                            ->count('message_id'); // Count the distinct message IDs 

                 } elseif ($trimmedType === 'Task') {

                    $unreadCounts[$trimmedType] = MessageTask::where('message_id',$lastMessageTask->message_id)
                    ->where(function ($query) use ($loginUser) {
                        $query->where('read_status', 'NOT LIKE', '%' . $loginUser . '%')
                              ->orWhereNull('read_status'); // In case 'read_status' is empty or NULL
                    })
                    // ->distinct('message_id') // Ensure unique message IDs
                    ->count(); // Count the distinct message IDs                
                    
                 }elseif ($trimmedType === 'event') {
                     $unreadCounts[$trimmedType] = ProjectEvent::where('created_by', $loginUser)
                         ->where(function ($query) use ($loginUser) {
                             // Check if the event has not been read by the specified user
                             $query->where('read_status', 'NOT LIKE', '%' . $loginUser . '%')
                                   ->orWhereNull('read_status'); // In case 'read_status' is empty or NULL
                         })
                         ->count();                
                 }
             }
             
             $data = [
                 'status_code' => 200,
                 'message' => 'Unread message counts retrieved successfully.',
                 'data' => [
                     'unread_counts' => $unreadCounts,
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
     *     path="/api/v1/read-unread-manage",
     *     summary="Add a new message for meeting",
     *     tags={"Messages"},
     *     description="Change Message status",
     *     operationId="manageReadStatus",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_type","messageIds","status"},
     *                 @OA\Property(
     *                     property="message_type",
     *                     type="string",
     *                     example="event",
     *                     description="only for event use"
     *                 ),
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

    public function manageReadStatus(Request $request)
    {
        try {
            $rules = [
                'message_type' => 'required|string|in:Meeting,Task,Event',
                'messageIds' => 'required|string|regex:/^\d+(,\d+)*$/',
            ];

            $message = [
                'messageIds.required' => 'The message IDs are required.',
                'messageIds.string' => 'The message IDs must be a string.',
                'messageIds.regex' => 'The message IDs must be a comma-separated list of numeric values.',
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

            if($request->message_type == 'Event')
            {             
                $messageIds = explode(',', $request->messageIds);                
                
                // Retrieve the relevant ProjectEvent records
                $projectEvents = ProjectEvent::whereIn('id', $messageIds)                    
                    ->get();                                      
                // Step 3: Create a new read_status string for each event
                foreach ($projectEvents as $event) {
                    // Get the existing read_status
                    $existingStatus = $event->read_status;
                    
                    // Prepare the new read_status by concatenating the existing status and loginUser
                    $newStatus = $existingStatus ? $existingStatus . ',' . $loginUser : $loginUser;                  

                    // Update the read_status in the database
                    $event->update(['read_status' => $newStatus]);
                    // Step 4: Get message IDs from the request
                                  

                    // Step 5: Update the read_status for the specified message IDs
                    ProjectEvent::whereIn('id', $messageIds)->update(['read_status' => $newStatus]);

                    $messageIds = explode(',', $request->messageIds); // Get message IDs from the request
                    $newUserId = $loginUser; // The user ID you want to add to read_status

                    foreach ($messageIds as $messageId) {
                        // Retrieve the current ProjectEvent by ID
                        $projectEvent = ProjectEvent::find($messageId);

                        if ($projectEvent) {
                            // Get the current read_status and convert it to an array
                            $currentReadStatus = explode(',', $projectEvent->read_status);
                            // Check if the new user ID is already in the current read_status
                            if (in_array($newUserId, $currentReadStatus)) {
                                // Add the new user ID to the array
                                $currentReadStatus[] = $newUserId;                           
                                // Convert back to a string and ensure unique values
                                $projectEvent->read_status = implode(',', array_unique($currentReadStatus));

                                // Save the model
                                $projectEvent->save();
                            }
                        }
                    }

                }

            }elseif($request->message_type == 'Meeting'){


                $messageIds = explode(',', $request->messageIds);                
                
                $projectMeetings = MessageMeeting::whereIn('message_id', $messageIds)                    
                    ->get();        
                                      
                // Step 3: Create a new read_status string for each event
                foreach ($projectMeetings as $event) {
                    // Get the existing read_status
                    $existingStatus = $event->read_status;
                    
                    // Prepare the new read_status by concatenating the existing status and loginUser
                    $newStatus = $existingStatus ? $existingStatus . ',' . $loginUser : $loginUser;                  

                    // Update the read_status in the database
                    $event->update(['read_status' => $newStatus]);
                    // Step 4: Get message IDs from the request
                    $messageIds = explode(',', $request->messageIds);              

                    // Step 5: Update the read_status for the specified message IDs
                    MessageMeeting::whereIn('message_id', $messageIds)->update(['read_status' => $newStatus]);

                    $messageIds = explode(',', $request->messageIds); // Get message IDs from the request
                    $newUserId = $loginUser; // The user ID you want to add to read_status

                    foreach ($messageIds as $messageId) {
                        // Retrieve the current ProjectEvent by ID
                        $projectMeetings = MessageMeeting::where('message_id',$messageId)->first();

                        if ($projectMeetings) {
                            // Get the current read_status and convert it to an array
                            $currentReadStatus = explode(',', $projectMeetings->read_status);
                            // Check if the new user ID is already in the current read_status
                            if (in_array($newUserId, $currentReadStatus)) {
                                // Add the new user ID to the array
                                $currentReadStatus[] = $newUserId;                           
                                // Convert back to a string and ensure unique values
                                $projectMeetings->read_status = implode(',', array_unique($currentReadStatus));

                                // Save the model
                                $projectMeetings->save();
                            }
                        }
                    }
                }

            }elseif($request->message_type == 'Task'){

                $messageIds = explode(',', $request->messageIds);                

                // Retrieve the MessageTask records for the provided message IDs
                $projectTasks = MessageTask::whereIn('message_id', $messageIds)->get();

                // Loop through each task and update read_status
                foreach ($projectTasks as $task) {
                    // Get the current read_status and convert it to an array
                    $currentReadStatus = $task->read_status ? explode(',', $task->read_status) : [];

                    // Add the new user ID only if it's not already in the array
                    if (!in_array($loginUser, $currentReadStatus)) {
                        $currentReadStatus[] = $loginUser;

                        // Ensure unique values and convert back to a comma-separated string
                        $task->read_status = implode(',', array_unique($currentReadStatus));

                        // Save the updated task
                        $task->save();
                    }
                }
            }

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
     *                     property="message_type",
     *                     type="string",
     *                     example="event",
     *                     description="only for event use"
     *                 ),
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
                'message_type' => 'string',
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

            if($request->message_type == 'event')
            {
                $messageIds = explode(',', $request->messageIds);
                ProjectEvent::whereIn('id', $messageIds)->update(['status' => $request->status]);
            }else{
                $messageIds = explode(',', $request->messageIds);
                Message::whereIn('id', $messageIds)->update(['status' => $request->status]);
            }

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
     * @OA\Get(
     *     path="/api/v1/task-complete-incomplete",
     *     summary="Fetch Task Complete or Incomplete by message_id",
     *     description="This endpoint fetches the Task Complete or Incomplete for a given message_id, including the question and the options with vote counts.",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="message_id",
     *         in="query",
     *         description="The ID of the message to fetch votes for",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="select",
     *         in="query",
     *         description="The status of the task (complete or incomplete)",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *             enum={"complete", "incomplete"}
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task Complete or Incomplete details fetched successfully!",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=200
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Task Complete or Incomplete fetched successfully!"
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="question",
     *                     type="string",
     *                     example="What is your favorite programming language?"
     *                 ),
     *                 @OA\Property(
     *                     property="options",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(
     *                             property="option",
     *                             type="string",
     *                             example="PHP"
     *                         ),
     *                         @OA\Property(
     *                             property="vote_count",
     *                             type="integer",
     *                             example=5
     *                         )
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="users",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(
     *                             property="id",
     *                             type="integer",
     *                             example=1
     *                         ),
     *                         @OA\Property(
     *                             property="name",
     *                             type="string",
     *                             example="John Doe"
     *                         ),
     *                         @OA\Property(
     *                             property="profile_picture",
     *                             type="string",
     *                             example="https://example.com/images/johndoe.jpg"
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request: Missing or invalid message_id or select",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=400
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="The 'message_id' or 'select' parameter is required."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Not Found: No options message found for the provided message_id",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=404
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="No options message found for the provided message_id"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=500
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Something went wrong"
     *             )
     *         )
     *     )
     * )
     */
    
     public function taskCompleteIncomplete(Request $request)
    {
        try {
            // Validate the incoming request to ensure message_id and select are provided
            $validator = Validator::make($request->all(), [
                'message_id' => 'required|integer',
                'select' => 'required|in:complete,incomplete,all', // Validate select field
            ]);

            if ($validator->fails()) {
                return $this->sendJsonResponse([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            // Retrieve all tasks associated with the provided message_id
            $messageTasks = MessageTask::where('message_id', $request->message_id)->get();           

            if ($messageTasks->isEmpty()) {
                return $this->sendJsonResponse([
                    'status_code' => 404,
                    'message' => 'No tasks found with the provided message_id',
                    'data' => ""
                ]);
            }

        // Map each task to include its complete/incomplete users
        $tasks = $messageTasks->map(function ($task) use ($request) {
            // Parse complete and incomplete user IDs
            $completeUsers = $task->task_checked_users ? explode(',', $task->task_checked_users) : [];
            $allUsers = $task->users ? explode(',', $task->users) : [];
            
            // Filter incomplete users to exclude those in the complete users list
            $incompleteUsers = array_diff($allUsers, $completeUsers);                     

            // Determine which set of users to return based on the 'select' field
            $selectedUsers = ($request->select === 'complete') ? $completeUsers : $incompleteUsers;

            $loginUserId = auth()->user()->id;

            // Remove the logged-in user's ID from the selected users array
            $selectedUsers = array_diff($selectedUsers, [$loginUserId]);

            // Only include tasks with valid selected users
            if (!empty($selectedUsers)) {
                // Fetch profiles for the selected users
                $userProfiles = User::whereIn('id', $selectedUsers)->get(['id', 'first_name', 'profile']);

                // Return the task data with user profiles
                return [
                    'taskname' => $task->checkbox,
                    'taskdescription' => $task->task_description,
                    'userData' => $userProfiles->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'first_name' => $user->first_name,
                            'profile' => $user->profile
                            ? setAssetPath('user-profile/' . $user->profile)
                            : setAssetPath('assets/media/avatars/blank.png'),
                        ];
                    }),
                ];
            }
            // Return null for tasks with no valid selected users
            return null;
        });

        // Remove null values from the mapped tasks
        $tasks = $tasks->filter(function ($task) {
            return !is_null($task);
        })->values(); // Reindex the array to avoid gaps in indices

        // Prepare the final response
        return $this->sendJsonResponse([
            'status_code' => 200,
            'message' => 'Task Complete or Incomplete fetched successfully!',
            'data' => $tasks
        ]);

        } catch (\Exception $e) {
            // Log any errors that occur
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse([
                'status_code' => 500,
                'message' => 'Something went wrong',
                'data' => ''
            ]);
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
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="Asia/Kolkata",
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

    // ****************************** Main Code **********************************//
    // public function taskUserList(Request $request)
    // {
    //     try {
    //         $rules = [
    //             'type' => 'required|string|in:Receive,Given,All Task',
    //         ];

    //         $message = [
    //             'type.required' => 'The type field is required.',
    //             'type.string' => 'The type field must be a string.',
    //             'type.in' => 'The selected type is invalid. Valid options are: Receive, Given, All Task.',
    //         ];

    //         $validator = Validator::make($request->all(), $rules, $message);
    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'status_code' => 400,
    //                 'message' => $validator->errors(),
    //                 'data' => []
    //             ]);
    //         }

    //         $type = $request->type;
    //         $loginUser = auth()->user()->id;
            
    //         $lastMessageTask = DB::table('message_task')
    //                         ->select('id', 'message_id', 'task_name', 'checkbox', 'task_checked', 'task_checked_users', 'task_description', 'users', 'read_status', 'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at')
    //                         ->where('users', 'like', '%' .$loginUser. '%')
    //                         ->where('created_by', $loginUser)
    //                         ->orderBy('message_id', 'desc')
    //                         ->first();
            
    //         $baseQuery = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
    //             ->whereHas('message', function ($query) {
    //                 $query->where('message_type', 'Task');
    //             });
    //         if ($type == 'Receive') {
    //             $userList = (clone $baseQuery)
    //                 ->where('receiver_id', $loginUser)
    //                 ->where('message_id', $lastMessageTask->message_id)
    //                 ->get();
    //         } elseif ($type == 'Given') {
    //             $userList = (clone $baseQuery)
    //                 ->where('sender_id', $loginUser)
    //                 ->where('message_id', $lastMessageTask->message_id)
    //                 ->get();
    //         } elseif ($type == 'All Task') {
    //             $userList = (clone $baseQuery)
    //                 ->where(function ($query) use ($loginUser) {
    //                     $query->where('sender_id', $loginUser)
    //                           ->where('message_id', $lastMessageTask->message_id)
    //                         ->orWhere('receiver_id', $loginUser);
    //                 })
    //                 ->get();
    //         }
           
    //        $result = $userList->map(function ($messageSenderReceiver) use ($loginUser) {
    //         $sender = $messageSenderReceiver->sender;
    //         $receiver = $messageSenderReceiver->receiver;
        
    //         // Fetch the last message with task type
    //         $lastMessage = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
    //             ->whereHas('message', function ($query) use ($loginUser) {
    //                 $query->where('message_type', 'Task');
    //             })
    //             ->where('created_by', $loginUser)
    //             ->orderBy('id', 'desc')
    //             ->first();
                        
    //             if ($sender && $sender->id != $loginUser) {   
    //                 $totalTasks = MessageTask::where('message_id', $lastMessage->message_id)->count();                  
    //                 $profileUrl = $sender->profile ? setAssetPath('user-profile/' . $sender->profile) : setAssetPath('assets/media/avatars/blank.png');

    //                 if ($lastMessage) {
    //                     // Fetch tasks related to the last message and that are marked as checked
    //                     $completedTasks = MessageTask::where('message_id', $lastMessage->message_id)
    //                         ->whereRaw('FIND_IN_SET(?, task_checked_users)', [$sender->id])
    //                         ->get();
                    
    //                     // Initialize counters
    //                     $completedCount = 0;
           
    //                     // Iterate through tasks to filter based on checked users
    //                     foreach ($completedTasks as $task) {
    //                         $taskCheckedUsers = explode(',', $task->task_checked_users);  // Split the string by comma
    //                         $specificUserId = $sender->id;  // Example: Check if user 121 has completed the task
                    
    //                         if (in_array($specificUserId, $taskCheckedUsers)) {
    //                             // This task is completed by the specific user
    //                             $completedCount++; // Increment the completed counter
    //                         }
    //                     }
    //                 }

    //                 if ($messageSenderReceiver->updated_by == 1) {                        
                      
    //                     $response['taskStatus'] = true;
    //                     $response['totalTasks'] = $totalTasks;
    //                 }else 
    //                 {
    //                     $response['taskStatus'] = false;
    //                     $response['totalTasks'] = $totalTasks;
    //                 }
    //                 return [
    //                     'id' => $sender->id,
    //                     'message_id' => $lastMessage->message_id,
    //                     'name' => $sender->first_name . ' ' . $sender->last_name,
    //                     'profile' => $profileUrl,
    //                     'taskStatus' => $response['taskStatus'],
    //                     'totalTasks' => $response['totalTasks'],
    //                     'completedCount' => $completedCount,
    //                 ];
    //             }

    //             if ($receiver && $receiver->id != $loginUser) {
    //                 $totalTasks = MessageTask::where('message_id', $lastMessage->message_id)->count();  
                    
    //                 if ($lastMessage) {
    //                     // Fetch tasks related to the last message and that are marked as checked
    //                     $completedTasks = MessageTask::where('message_id', $lastMessage->message_id)
    //                         ->whereRaw('FIND_IN_SET(?, task_checked_users)', [$receiver->id])
    //                         ->get();
                    
    //                     // Initialize counters
    //                     $completedCount = 0;
           
    //                     // Iterate through tasks to filter based on checked users
    //                     foreach ($completedTasks as $task) {
    //                         $taskCheckedUsers = explode(',', $task->task_checked_users);  // Split the string by comma
    //                         $specificUserId = $receiver->id;  // Example: Check if user 121 has completed the task
                    
    //                         if (in_array($specificUserId, $taskCheckedUsers)) {
    //                             // This task is completed by the specific user
    //                             $completedCount++; // Increment the completed counter
    //                         }
    //                     }
    //                 }
    //                 $profileUrl = $receiver->profile ? setAssetPath('user-profile/' . $receiver->profile) : setAssetPath('assets/media/avatars/blank.png');

    //                 if ($messageSenderReceiver->updated_by == 1) {
    //                     $response['taskStatus'] = true;
    //                     $response['totalTasks'] = $totalTasks;
    //                 }else{
    //                     $response['taskStatus'] = false;
    //                     $response['totalTasks'] = $totalTasks;
    //                 }

    //                 return [
    //                     'id' => $receiver->id,
    //                     'message_id' => $lastMessage->message_id,
    //                     'name' => $receiver->first_name . ' ' . $receiver->last_name,
    //                     'profile' => $profileUrl,
    //                     'taskStatus' => $response['taskStatus'],
    //                     'totalTasks' => $response['totalTasks'],
    //                     'completedCount' => $completedCount,
    //                     'date' => $lastMessage->created_at,
    //                     'time' => $lastMessage->created_at, 
    //                 ];

    //             }

    //             return null;
    //         });
    //         $uniqueResult = $result->filter()->unique('id')->values();            

    //         $data = [
    //             'status_code' => 200,
    //             'message' => "Task User List Get Successfully!",
    //             'data' => [
    //                 'userList' => $uniqueResult
    //             ]
    //         ];
    //         return $this->sendJsonResponse($data);
    //     } catch (\Exception $e) {
    //         Log::error(
    //             [
    //                 'method' => __METHOD__,
    //                 'error' => [
    //                     'file' => $e->getFile(),
    //                     'line' => $e->getLine(),
    //                     'message' => $e->getMessage()
    //                 ],
    //                 'created_at' => date("Y-m-d H:i:s")
    //             ]
    //         );
    //         return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
    //     }
    // }
    // ****************************** Main Code **********************************//
    public function taskUserList(Request $request)
    {
        try {
            $rules = [
                'type' => 'required|string|in:Receive,Given,All Task',
                'timezone' => 'nullable',
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
            $timezone = $request->timezone;
            $loginUser = auth()->user()->id;

            $baseQuery = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
                                                ->whereHas('message', function ($query) {
                                                    $query->whereIn('message_type', ['Task', 'SimpleTask', 'DailyTask']);
                                                });
            $userList = [];
            if ($type == 'Receive') {
                $userList = (clone $baseQuery)
                        ->where('receiver_id', $loginUser)
                        ->where('sender_id', '!=', $loginUser)
                        // ->get();
                        ->distinct()
                        ->get(['message_id', 'created_by']);
            } else {
                $userList = (clone $baseQuery)
                        ->where('sender_id', $loginUser)
                        ->where('receiver_id', '!=', $loginUser)
                        // ->get();
                        ->distinct()
                        ->get(['message_id', 'created_by']);
            }

            $messageIds = $userList->pluck('message_id')->unique();

            $tasksByMessage = MessageTask::whereIn('message_id', $messageIds)->get()->groupBy('message_id');

            $allTaskUserIds = [];
            foreach ($tasksByMessage as $messageTasks) {
                foreach ($messageTasks as $task) {
                    $taskUserIds = array_filter(explode(',', $task->users));
                    $allTaskUserIds = array_merge($allTaskUserIds, $taskUserIds);
                }
            }
            $allTaskUserIds = array_unique($allTaskUserIds);

            $taskUsersProfiles = User::whereIn('id', $allTaskUserIds)->get(['id', 'profile', 'first_name', 'last_name'])->keyBy('id');

            $result = $userList->map(function ($messageSenderReceiver, $index) use ($loginUser, $request, $tasksByMessage, $taskUsersProfiles) {
    
                $message = $messageSenderReceiver->message;
                $receiver = $messageSenderReceiver->receiver;
                
                $tasks = $tasksByMessage->get($messageSenderReceiver->message_id, collect());
                $totalTasks = $tasks->count();
                $firstTask = $tasks->first();
                
                $completedTasks = $tasks->filter(function ($task) use ($messageSenderReceiver) {
                    $checkedUsers = array_filter(explode(',', $task->task_checked_users));
                    return in_array($messageSenderReceiver->created_by, $checkedUsers);
                })->count();
                
                $receiverProfileUrl = $receiver && !empty($receiver->profile)
                    ? setAssetPath('user-profile/' . $receiver->profile)
                    : setAssetPath('assets/media/avatars/blank.png');
                $receiverName = $receiver ? "{$receiver->first_name} {$receiver->last_name}" : 'Unknown';

                $getMessage = $message;
                
                $taskUsers = array_filter(explode(',', $firstTask->users ?? ''));
                $profiles = empty($taskUsers) ? [] : User::whereIn('id', array_map('intval', $taskUsers))
                            ->get(['id', 'profile', 'first_name', 'last_name'])
                            ->map(fn($user) => [
                                'id' => $user->id,
                                'profile' => $user->profile ? setAssetPath('user-profile/' . $user->profile) 
                                                            : setAssetPath('assets/media/avatars/blank.png'),
                                'name' => "{$user->first_name} {$user->last_name}"
                            ]);

                return [
                    'unique_id'            => $index + 1,
                    'user_id'              => $messageSenderReceiver->created_by ?? '',
                    'message_id'           => $messageSenderReceiver->message_id,
                    'inner_task_id'        => $firstTask ? $firstTask->id : null,
                    'message_type'         => $getMessage ? $getMessage->message_type : '',
                    'task_name'            => $firstTask ? $firstTask->task_name : 'No Task Name',
                    'taskReceiverName'     => $receiverName,
                    'taskReceiverProfile'  => $receiverProfileUrl,
                    'date'                 => $getMessage
                                                ? (isset($request->timezone)
                                                    ? Carbon::parse($getMessage->created_at)
                                                            ->setTimezone($request->timezone)
                                                            ->format('Y-m-d H:i:s')
                                                    : Carbon::parse($getMessage->created_at)
                                                            ->format('Y-m-d H:i:s'))
                                                : null,
                    'time'                 => $getMessage
                                                ? (isset($request->timezone)
                                                    ? Carbon::parse($getMessage->created_at)
                                                            ->setTimezone($request->timezone)
                                                            ->format('h:i a')
                                                    : Carbon::parse($getMessage->created_at)
                                                            ->format('h:i a'))
                                                : null,
                    'timeZone'             => $getMessage
                                                ? (isset($request->timezone)
                                                    ? Carbon::parse($getMessage->created_at)
                                                            ->setTimezone($request->timezone)
                                                            ->format('Y-m-d\TH:i:s.u\Z')
                                                    : Carbon::parse($getMessage->created_at)
                                                            ->format('Y-m-d\TH:i:s.u\Z'))
                                                : null,
                    'taskStatus'           => $messageSenderReceiver->updated_by == 1,
                    'totalTasks'           => $totalTasks,
                    'completedTasks'       => $completedTasks,
                    'priority_task'        => $firstTask ? $firstTask->priority_task : null,
                    'profiles'             => $profiles,
                    'task_checked_users'   => $firstTask ? $firstTask->task_checked_users : '',
                ];
            });

            // Apply final filtering and sorting
            $uniqueResult = $result
                        ->filter() // Remove null or empty entries if any
                        ->sortByDesc('message_id')
                        ->sortBy(function ($item) {
                            // Prioritize items with no completed tasks (i.e. false when cast to boolean)
                            return $item['completedTasks'] > 0;
                        })
                        ->values(); // Reset keys to be sequential

            $data = [
                'status_code' => 200,
                'message'     => "Task User List Get Successfully!",
                'data'        => [
                    'userList' => $uniqueResult
                ]
            ];
            return $this->sendJsonResponse($data);
    
            // $baseQuery = MessageSenderReceiver::with(['message', 'sender', 'receiver'])
            //     ->whereHas('message', function ($query) {
            //         $query->whereIn('message_type', array('Task', 'SimpleTask'));
            //     });
    
            // // Apply filter for "Receive" type to exclude tasks created by the current user
            // if ($type == 'Receive') {
            //     $userList = (clone $baseQuery)
            //         ->where('receiver_id', $loginUser)
            //         ->where('sender_id', '!=', $loginUser) // Exclude tasks created by the user
            //         ->distinct()
            //         ->get(['message_id']);

            //     $result = $userList->map(function ($messageSenderReceiver, $index ) use ($loginUser, $request) {
            //         $sender = $messageSenderReceiver->sender;
            //         $receiver = $messageSenderReceiver->receiver;
        
            //         // Get total tasks and completed tasks
            //         $totalTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)->count();
            //         $taskname = MessageTask::where('message_id', $messageSenderReceiver->message_id)->first();
            //         $completedTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)
            //             ->whereRaw('FIND_IN_SET(?, task_checked_users)', [$loginUser])
            //             ->count();
                        
            //             // Fetch the task creator profile and name
            //         $creator = User::find($messageSenderReceiver->created_by);
            //         $creatorProfileUrl = !empty($creator->profile) ? setAssetPath('user-profile/' . $creator->profile) : setAssetPath('assets/media/avatars/blank.png');
            //         $creatorName = $creator ? $creator->first_name . ' ' . $creator->last_name : 'Unknown';
        
            //         // Get the task name (assuming the task name is stored in 'message' or a related field)
            //         $taskName = $taskname->task_name ?? 'No Task Name';

            //         $getMessage = Message::where('id', $messageSenderReceiver->message_id)->first();

            //         $profiles = [];
            //         $taskUsers = array_filter(explode(',', $taskname->users)); // Remove empty values
                    
            //         // Ensure all values are integers
            //         $taskUsers = array_map('intval', $taskUsers);
                    
            //         // Fetch and process users only if taskUsers is not empty
            //         if (!empty($taskUsers)) {
            //             $profiles = User::whereIn('id', $taskUsers)
            //                 ->get(['id', 'profile', 'first_name', 'last_name'])
            //                 ->map(function ($user) {
            //                     $user->profile = $user->profile
            //                         ? setAssetPath('user-profile/' . $user->profile)
            //                         : setAssetPath('assets/media/avatars/blank.png');
            //                     $user->name = "{$user->first_name} {$user->last_name}";
            //                     return $user;
            //                 });
            //         }

            //         // Return unique message_id data with task details
            //         return [
            //             'unique_id' => $index + 1, // Unique ID for each entry
            //             'user_id' => isset($messageSenderReceiver->created_by) ? $messageSenderReceiver->created_by : '',
            //             'message_id' => $messageSenderReceiver->message_id, // Unique message_id
            //             'message_type' => $getMessage ? $getMessage->message_type : '',
            //             'task_name' => $taskName,
            //             'taskCreatorName' => $creatorName,
            //             'taskCreatorProfile' => $creatorProfileUrl,
            //             'date' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($getMessage->created_at)->format('Y-m-d H:i:s'),
            //             'time' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($getMessage->created_at)->format('h:i a'),
            //             'timeZone' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($getMessage->created_at)->format('Y-m-d\TH:i:s.u\Z'),
            //             'taskStatus' => $messageSenderReceiver->updated_by == 1, // Task status (completed or not)
            //             'totalTasks' => $totalTasks,
            //             'completedTasks' => $completedTasks,
            //             'priority_task' => $taskname->priority_task,
            //             'profiles' => $profiles,
            //             'task_checked_users' => $taskname->task_checked_users
            //         ];
            //     });
        
            //     // Ensure unique message_id results (filter out duplicates)
            //     $uniqueResult = $result
            //                     ->filter() // Remove null or empty entries
            //                     ->unique('message_id') // Ensure unique entries by message_id
            //                     ->sortByDesc('message_id') // Sort by message_id in descending order
            //                     ->sortBy(fn($item) => $item['completedTasks'] > 0) // Prioritize completedTasks = 0
            //                     ->values(); // Reset keys to be sequential    
            //     $data = [
            //         'status_code' => 200,
            //         'message' => "Task User List Get Successfully!",
            //         'data' => [
            //             'userList' => $uniqueResult
            //         ]
            //     ];
            // } elseif ($type == 'Given') {           
            //     $userList = (clone $baseQuery)                    
            //         ->where('sender_id', $loginUser)
            //         ->where('receiver_id', '!=', $loginUser)
            //         ->distinct()
            //         ->get(['message_id']);

            //     $result = $userList->map(function ($messageSenderReceiver,$index) use ($loginUser, $request) {
            //         $sender = $messageSenderReceiver->sender;
            //         $receiver = $messageSenderReceiver->receiver;
        
            //         // Get total tasks and completed tasks
            //         $totalTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)->count();
            //         $taskname = MessageTask::where('message_id', $messageSenderReceiver->message_id)->first();
            //         $completedTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)
            //             ->whereRaw('FIND_IN_SET(?, task_checked_users)', [$messageSenderReceiver->receiver_id])
            //             ->count();
                        
            //             // Fetch the task creator profile and name
            //             $receiver = User::find($messageSenderReceiver->receiver_id);
            //             $receiverProfileUrl = !empty($receiver->profile) ? setAssetPath('user-profile/' . $receiver->profile) : setAssetPath('assets/media/avatars/blank.png');
            //             $receiverName = $receiver ? $receiver->first_name . ' ' . $receiver->last_name : 'Unknown';
        
            //         // Get the task name (assuming the task name is stored in 'message' or a related field)
            //         $taskName = $taskname->task_name ?? 'No Task Name';
                    
            //         $getMessage = Message::where('id', $messageSenderReceiver->message_id)->first();

            //         $profiles = [];
            //         $taskUsers = array_filter(explode(',', $taskname->users)); // Remove empty values
                    
            //         // Ensure all values are integers
            //         $taskUsers = array_map('intval', $taskUsers);
                    
            //         // Fetch and process users only if taskUsers is not empty
            //         if (!empty($taskUsers)) {
            //             $profiles = User::whereIn('id', $taskUsers)
            //                 ->get(['id', 'profile', 'first_name', 'last_name'])
            //                 ->map(function ($user) {
            //                     $user->profile = $user->profile
            //                         ? setAssetPath('user-profile/' . $user->profile)
            //                         : setAssetPath('assets/media/avatars/blank.png');
            //                     $user->name = "{$user->first_name} {$user->last_name}";
            //                     return $user;
            //                 });
            //         }
            //         // Return unique message_id data with task details
            //         return [
            //             'unique_id' => $index + 1, // Unique ID for each entry
            //             'user_id' => isset($messageSenderReceiver->receiver_id) ? $messageSenderReceiver->receiver_id : '',
            //             'message_id' => $messageSenderReceiver->message_id,
            //             'inner_task_id' => $taskname->id,
            //             'message_type' => $getMessage ? $getMessage->message_type : '',
            //             'task_name' => $taskname->task_name ?? 'No Task Name',
            //             'taskReceiverName' => $receiverName,
            //             'taskReceiverProfile' => $receiverProfileUrl,
            //             'date' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($getMessage->created_at)->format('Y-m-d H:i:s'),
            //             'time' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($getMessage->created_at)->format('h:i a'),
            //             'timeZone' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($getMessage->created_at)->format('Y-m-d\TH:i:s.u\Z'),
            //             'taskStatus' => $messageSenderReceiver->updated_by == 1,
            //             'totalTasks' => $totalTasks,
            //             'completedTasks' => $completedTasks,
            //             'priority_task' => $taskname->priority_task,
            //             'profiles' => $profiles,
            //             'task_checked_users' => $taskname->task_checked_users
            //         ];
            //     });
        
            //     // Ensure unique message_id results (filter out duplicates)
            //     $uniqueResult = $result
            //                     ->filter() // Remove null or empty entries
            //                     // ->unique('message_id') // Ensure unique entries by message_id
            //                     ->sortByDesc('message_id') // Sort by message_id in descending order
            //                     ->sortBy(fn($item) => $item['completedTasks'] > 0) // Prioritize completedTasks = 0
            //                     ->values(); // Reset keys to be sequential    
            //     $data = [
            //         'status_code' => 200,
            //         'message' => "Task User List Get Successfully!",
            //         'data' => [
            //             'userList' => $uniqueResult
            //         ]
            //     ];
            // } elseif ($type == 'All Task') {
           
            //     $receiveList = (clone $baseQuery)
            //         ->where('receiver_id', $loginUser)
            //         ->where('sender_id', '!=', $loginUser)
            //         ->distinct()
            //         ->get(['message_id']);
            
            //     $givenList = (clone $baseQuery)
            //         ->where('sender_id', $loginUser)
            //         ->where('receiver_id', '!=', $loginUser)
            //         ->distinct()
            //         ->get(['message_id']);
            
            //     $combinedResult = $receiveList->merge($givenList)->map(function ($messageSenderReceiver,$index) use ($loginUser, $request) {
            //         $user = User::find($messageSenderReceiver->created_by ?? $messageSenderReceiver->receiver_id);
            //         $userProfileUrl = !empty($user->profile) ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
            //         $userName = $user ? $user->first_name . ' ' . $user->last_name : 'Unknown';
            //         $isGivenTask = $messageSenderReceiver->sender_id == $loginUser;
            //         $userId = $isGivenTask ? $messageSenderReceiver->receiver_id : $messageSenderReceiver->created_by;

            
            //         // Get total tasks and completed tasks
            //         $getMessage = Message::where('id', $messageSenderReceiver->message_id)->first();
            //         $totalTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)->count();
            //         $taskname = MessageTask::where('message_id', $messageSenderReceiver->message_id)->first();
            //         $completedTasks = MessageTask::where('message_id', $messageSenderReceiver->message_id)
            //             ->whereRaw('FIND_IN_SET(?, task_checked_users)', [$loginUser])
            //             ->count();

            //         $profiles = [];
            //         $taskUsers = array_filter(explode(',', $taskname->users)); // Remove empty values
                    
            //         // Ensure all values are integers
            //         $taskUsers = array_map('intval', $taskUsers);
                    
            //         // Fetch and process users only if taskUsers is not empty
            //         if (!empty($taskUsers)) {
            //             $profiles = User::whereIn('id', $taskUsers)
            //                 ->get(['id', 'profile', 'first_name', 'last_name'])
            //                 ->map(function ($user) {
            //                     $user->profile = $user->profile
            //                         ? setAssetPath('user-profile/' . $user->profile)
            //                         : setAssetPath('assets/media/avatars/blank.png');
            //                     $user->name = "{$user->first_name} {$user->last_name}";
            //                     return $user;
            //                 });
            //         }
            
            //         return [
            //             'unique_id' => $index + 1, // Unique ID for each entry
            //             'user_id' => $userId,
            //             'message_id' => $messageSenderReceiver->message_id,
            //             'message_type' => $getMessage ? $getMessage->message_type : '',
            //             'task_name' => $taskname->task_name ?? 'No Task Name',
            //             'userName' => $userName,
            //             'userProfile' => $userProfileUrl,
            //             'date' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($getMessage->created_at)->format('Y-m-d H:i:s'),
            //             'time' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($getMessage->created_at)->format('h:i a'),
            //             'timeZone' => @$request->timezone ? Carbon::parse($getMessage->created_at)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($getMessage->created_at)->format('Y-m-d\TH:i:s.u\Z'),
            //             'taskStatus' => $messageSenderReceiver->updated_by == 1,
            //             'totalTasks' => $totalTasks,
            //             'completedTasks' => $completedTasks,
            //             'priority_task' => $taskname->priority_task,
            //             'profiles' => $profiles
            //         ];
            //     });
            
            //     $data = [
            //         'status_code' => 200,
            //         'message' => "All Tasks Retrieved Successfully!",
            //         'data' => ['userList' => $combinedResult->sortByDesc('message_id')->sortBy(fn($item) => $item['completedTasks'] > 0)->values()]
            //     ];
            // }
            // return $this->sendJsonResponse($data);
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
   

     /**
     * @OA\Get(
     *     path="/api/v1/meetings",
     *     summary="Get meeting details",
     *     description="Fetches meeting details based on the type (Receive or Given) and user ID.",
     *     tags={"Meetings"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=false,
     *         description="Type of meetings to fetch. Can be 'Receive', 'Given', or omitted to fetch all meetings.",
     *         @OA\Schema(
     *             type="string",
     *             enum={"Receive", "Given"}
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="timezone",
     *         in="query",
     *         required=false,
     *         description="Enter Timezone",
     *         @OA\Schema(
     *             type="string",
     *             example="Asia/Kolkata"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Meetings fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="message_id", type="integer"),
     *                     @OA\Property(property="mode", type="string"),
     *                     @OA\Property(property="title", type="string"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="date", type="string", format="date"),
     *                     @OA\Property(property="start_time", type="string", format="date-time"),
     *                     @OA\Property(property="end_time", type="string", format="date-time"),
     *                     @OA\Property(property="meeting_url", type="string", format="uri"),
     *                     @OA\Property(property="users", type="string"),
     *                     @OA\Property(property="latitude", type="number", format="float"),
     *                     @OA\Property(property="longitude", type="number", format="float"),
     *                     @OA\Property(property="location_url", type="string", format="uri"),
     *                     @OA\Property(property="location", type="string"),
     *                     @OA\Property(
     *                         property="created_by",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="email", type="string", format="email")
     *                     ),
     *                     @OA\Property(
     *                         property="assigned_users",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="id", type="integer"),
     *                             @OA\Property(property="name", type="string"),
     *                             @OA\Property(property="email", type="string", format="email")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid type provided",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="Invalid type provided"),
     *             @OA\Property(property="data", type="array", items={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No meetings data found",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="No Meetings Data Found"),
     *             @OA\Property(property="data", type="array", items={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="data", type="array", items={})
     *         )
     *     )
     * )
     */
    public function getMeetingDetails(Request $request)
    {
        try {
            $userId = auth()->user()->id; // Get the ID of the currently authenticated user
            $type = $request->input('type'); // Type can be 'Receive', 'Given', or omitted

            // Start the query
            $meetingDetailsQuery = DB::table('message_meeting as mm')
                ->join('message as m', 'mm.message_id', '=', 'm.id')
                ->leftJoin('users as mu', 'm.created_by', '=', 'mu.id')
                ->leftJoin('message_sender_receiver as msr', 'mm.message_id', '=', 'msr.message_id')
                ->where('m.message_type', 'Meeting')
                ->where(function ($query) use ($userId) {
                    $query->where('mm.created_by', $userId)
                          ->orWhere('mm.users', 'like', "%$userId%");
                })
                ->orderBy('mm.created_at', 'desc') // Sorting by created_at in descending order
                ->select(
                    'mm.id',
                    'mm.message_id',
                    'mm.mode',
                    'mm.title',
                    'mm.description',
                    'mm.date',
                    'mm.start_time',
                    'mm.end_time',
                    'mm.meeting_url',
                    'mm.users',
                    'mm.accepted_users',
                    'mm.decline_users',
                    'mm.latitude',
                    'mm.longitude',
                    'mm.location_url',
                    'mm.location',                   
                    'mm.created_by',
                    'mm.updated_by',
                    'mm.deleted_by',
                    'mm.created_at',
                    'mm.updated_at',
                    'mm.deleted_at',
                    'm.created_by as creator_id'
                );

            // Apply filter based on the type if provided
            if ($type === 'Receive') {
                $meetingDetailsQuery->where('msr.receiver_id', $userId);
            } elseif ($type === 'Given') {
                $meetingDetailsQuery->where('msr.sender_id', $userId);
            } elseif ($type !== null) {
                // If type is provided but not valid, return 400 response
                return response()->json([
                    'status_code' => 400,
                    'message' => 'Invalid type provided',
                    'data' => [],
                ]);
            }
            
            // Group by meeting ID
            $meetingDetails = $meetingDetailsQuery
                ->groupBy(
                    'mm.id',
                    'mm.message_id',
                    'mm.mode',
                    'mm.title',
                    'mm.description',
                    'mm.date',
                    'mm.start_time',
                    'mm.end_time',
                    'mm.meeting_url',
                    'mm.users',
                    'mm.accepted_users',
                    'mm.decline_users',
                    'mm.latitude',
                    'mm.longitude',
                    'mm.location_url',
                    'mm.location',
                    'mm.created_by',
                    'mm.updated_by',
                    'mm.deleted_by',
                    'mm.created_at',
                    'mm.updated_at',
                    'mm.deleted_at',
                    'm.created_by'
                )
                ->get();
            
            if ($meetingDetails->isEmpty()) {
                return response()->json([
                    'status_code' => 404,
                    'message' => 'No Meetings Data Found',
                    'data' => [],
                ]);
            }

            // Process the meeting details
            $meetingDetails = $meetingDetails->map(function ($item) use($request) {
                // Ensure 'users' column is not null or empty
                if (!empty($item->users)) {
                    $assignedUserIds = explode(',', $item->users);
                    
                    // Fetch user details for the meeting creator and assigned users
                    $creatorUser = User::find($item->creator_id);
                    $assignedUsers = User::whereIn('id', $assignedUserIds)->get();
                
                    $item->created_by = $creatorUser;
                    $item->assigned_users = $assignedUsers;
                } else {
                    $item->created_by = User::find($item->creator_id);
                    $item->assigned_users = collect(); // Empty collection
                }

                $item->timeZone = @$request->timezone ? Carbon::parse($item->created_at)->setTimezone($request->timezone)->format('Y-m-d\TH:i:s.u\Z') : Carbon::parse($item->created_at)->format('Y-m-d\TH:i:s.u\Z');
                
                unset($item->creator_id);

                return $item;
            });
            

            return response()->json([
                'status_code' => 200,
                'message' => 'Meetings fetched successfully',
                'data' => $meetingDetails,
            ]);
        
        } catch (\Exception $e) {
            \Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }


    /**
     * @OA\Get(
     *     path="/api/v1/meetings/{id}",
     *     summary="Get meeting details by message ID with user profiles",
     *     description="Retrieve meeting details and include accepted and/or declined user profiles.",
     *     tags={"Meetings"},
     *     operationId="getMeetingById",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Message ID of the meeting to retrieve",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="filter",
     *         in="query",
     *         required=false,
     *         description="Filter users by 'accepted', 'declined', or leave blank for all",
     *         @OA\Schema(type="string", enum={"accepted", "declined"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Meeting details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Meeting retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="meeting", type="object"),
     *                 @OA\Property(property="users", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Meeting not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="Meeting not found")
     *         )
     *     )
     * )
     */
  
     public function getMeetingById(Request $request, $id)
    {
        try {
            // Retrieve the meeting record
            $meeting = MessageMeeting::where('message_id', $id)->first();

            if (!$meeting) {
                return response()->json([
                    'status_code' => 404,
                    'message' => 'Meeting not found',
                ], 404);
            }

            // Decode user IDs
            $acceptedUserIds = $meeting->accepted_users ? explode(',', $meeting->accepted_users) : [];
            $declinedUserIds = $meeting->decline_users ? explode(',', $meeting->decline_users) : [];

            // Filter users based on query parameter
            $filter = $request->query('filter', 'all');
            $users = [];

            // Common function to fetch and process users
            $fetchUsers = function ($userIds) {
                if (empty($userIds)) {
                    return [];
                }

                return User::whereIn('id', $userIds)
                    ->get(['id', 'profile', 'first_name', 'last_name'])
                    ->map(function ($user) {
                        $user->profile = $user->profile
                            ? setAssetPath('user-profile/' . $user->profile)
                            : setAssetPath('assets/media/avatars/blank.png');
                        $user->name = "{$user->first_name} {$user->last_name}";
                        return $user;
                    });
            };

            if ($filter === 'accepted') {
                $users['accepted_users'] = $fetchUsers($acceptedUserIds);
            } elseif ($filter === 'declined') {
                $users['declined_users'] = $fetchUsers($declinedUserIds);
            } else { // 'all'
                $users = [
                    'accepted_users' => $fetchUsers($acceptedUserIds),
                    'declined_users' => $fetchUsers($declinedUserIds),
                ];
            }

            return response()->json([
                'status_code' => 200,
                'message' => 'Meeting retrieved successfully',
                'data' => [
                    'meeting' => $meeting,
                    'users' => $users,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
            ]);

            return response()->json([
                'status_code' => 500,
                'message' => 'Something went wrong',
            ], 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/api/v1/file-scan-upload",
     *     summary="Upload a file",
     *     description="Uploads a file and stores its information in the database",
     *     tags={"Files"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"file", "attachment_type"},
     *                 @OA\Property(
     *                     property="file",
     *                     description="File to upload",
     *                     type="string",
     *                     format="binary"
     *                 ),
     *                 @OA\Property(
     *                     property="attachment_type",
     *                     description="Type of the attachment",
     *                     type="string",
     *                     example="scan"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File Uploaded Successfully!",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="File Uploaded Successfully!"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="attachment_name", type="string", example="file_name_123456.jpg"),
     *                 @OA\Property(property="file_type", type="string", example="jpg"),
     *                 @OA\Property(property="attachment_path", type="string", example="https://yourdomain.com/chat-file/file_name_123456.jpg"),
     *                 @OA\Property(
     *                     property="attachment_type",
     *                     type="string",
     *                     default="scan",
     *                     description="Type of the attachment"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="File is required."),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */
    public function fileScanUpload(Request $request)
    {
        try {
            $rules = [
                'file' => 'required|file',
                'attachment_type' => 'required|string|in:scan'
            ];

            $message = [
                'file.required' => 'File is required.',
                'file.file' => 'File must be a valid file.',
                'attachment_type.required' => 'Attachment type is required.',
                'attachment_type.in' => 'Attachment type must be "scan".'
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return $this->sendJsonResponse([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            if ($request->hasFile('file') && $request->input('attachment_type') === 'scan') {
                $file = $request->file('file');
                $extension = strtolower($file->getClientOriginalExtension());
                $originalNameWithExt = $file->getClientOriginalName();
                $originalName = pathinfo($originalNameWithExt, PATHINFO_FILENAME);
                $originalName = create_slug($originalName);
                $attachmentName = $originalName . '_' . time() . '.' . $extension;
                $attachmentPath = 'document-file/' . $attachmentName;
                $destinationPath = public_path('document-file/');
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0777, true);
                }
                $file->move($destinationPath, $attachmentName);

                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $optimizerChain = OptimizerChainFactory::create();
                    $optimizerChain->optimize($destinationPath . $attachmentName);
                }

                // Store file information in the user_documents table
                $userDocument = new UserDocument();
                $userDocument->attachment_name = $attachmentName;
                $userDocument->attachment_path = setAssetPath('document-file/' . $attachmentName);
                $userDocument->created_by = auth()->user()->id;
                $userDocument->save();

                return $this->sendJsonResponse([
                    'status_code' => 200,
                    'message' => 'File Uploaded Successfully!',
                    'data' => [
                        'attachment_name' => $attachmentName,
                        'file_type' => $extension,
                        'attachment_path' => setAssetPath('document-file/' . $attachmentName),
                        'attachment_type' => 'scan'
                    ]
                ]);
            } else {
                return $this->sendJsonResponse([
                    'status_code' => 400,
                    'message' => 'File is required or attachment type is invalid.',
                    'data' => ""
                ]);
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
     * @OA\Get(
     *     path="/api/v1/user-documents",
     *     summary="Get user documents",
     *     description="Retrieves a list of documents created by the currently authenticated user",
     *     tags={"Documents"},
     *     operationId="getUserDocuments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User documents retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Documents retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="attachment_name", type="string"),
     *                     @OA\Property(property="attachment_path", type="string"),
     *                     @OA\Property(property="created_by", type="integer"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function getUserDocuments(Request $request)
    {
        try {
            $userId = auth()->user()->id; // Get the ID of the currently authenticated user

            $documents = UserDocument::where('created_by', $userId)->get();

            return response()->json([
                'status_code' => 200,
                'message' => 'Documents retrieved successfully',
                'data' => $documents
            ], 200);
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong'], 500);
        }
    }
    
     /**
     * @OA\Post(
     *     path="/api/v1/message-task-notification",
     *     summary="Send notifications for message tasks",
     *     description="Sends notifications to users associated with tasks for a given message ID.",
     *     tags={"Notifications"},
     *     operationId="messageTaskNotification",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id"},
     *                 @OA\Property(property="message_id", type="integer", example=1, description="The ID of the message to fetch associated tasks.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Notifications sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Notifications sent successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */


     public function message_task_notification(Request $request)
     {
         try {
             $userId = auth()->user()->id; // Get the ID of the currently authenticated user
     
             // Fetch tasks associated with the event's message_id
             $tasks = MessageTask::where('message_id', $request->message_id)->first();                 

                 $receiverIdsArray = $tasks->users ? explode(',', $tasks->users) : [];
              
                 $senderId = null;

                 if (in_array($tasks->created_by, $receiverIdsArray)) {
                     $senderId = $tasks->created_by;
                 }

                 foreach ($receiverIdsArray as $receiverId) {
                     $messageForNotification = [
                         'id' => $tasks->id,
                         'sender' => $senderId,
                         'receiver' => $receiverId,
                         'message_type' => "Task",
                         'Task_title' => $tasks->task_name, // Use task users                         
                     ];                   
                    
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
                         'title' => 'Task',
                         'body' => $tasks->task_name,
                         'image' => "",
                     ];
     
                     if (count($validTokens) > 0) {
                         sendPushNotification($validTokens, $notification, $messageForNotification);
                     }

                
                 }  
             return response()->json([
                 'status_code' => 200,
                 'message' => 'Notifications sent successfully'
             ], 200);
     
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
     
             return response()->json(['status_code' => 500, 'message' => 'Something went wrong'], 500);
         }
     }
     
   
    /**
     * @OA\Post(
     *     path="/api/v1/sent-meeting-done",
     *     summary="sent meeting done",
     *     tags={"Messages"},
     *     description="Meeting done",
     *     operationId="meetingDone",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Meeting Done Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id","user_id", "type"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="string",
     *                     example="111",
     *                     description="Enter message_id"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
     *                 ),
     *                 @OA\Property(
     *                     property="type",
     *                     type="string",
     *                     example="accept",
     *                     description="Enter 'accept' or 'decline'"
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

    public function sentMeetingDone(Request $request)
    {
        try {
            $rules = [
                'message_id' => 'required|string',
                'user_id' => 'required|string|regex:/^(\d+)(,\d+)*$/',
                'type' => 'required|string|in:accept,decline', // Add type validation
            ];
    
            $messages = [
                'message_id.required' => 'The message_id field is required.',
                'message_id.string' => 'The message_id field must be a string.',
                'user_id.required' => 'The user_id field is required.',
                'user_id.string' => 'The user_id field must be a string.',
                'user_id.regex' => 'The user_id field must be a comma-separated list of integers.',
                'type.required' => 'The type field is required.',
                'type.string' => 'The type field must be a string.',
                'type.in' => 'The type field must be either accept or decline.',
            ];
    
            $validator = Validator::make($request->all(), $rules, $messages);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors(),
                    'data' => []
                ]);
            }
    
            $recipient = explode(',', $request->user_id);
            $loginUser = auth()->user()->id;
    
            // Fetch the meeting record
            $messageMeeting = MessageMeeting::where('message_id', $request->message_id)->first();
            if ($messageMeeting) {
                // Process based on type (accept or decline)
                if ($request->type === 'accept') {
                    // Update accepted_users
                    $existingAcceptedUsers = $messageMeeting->accepted_users ? explode(',', $messageMeeting->accepted_users) : [];
                    $newAcceptedUsers = array_unique(array_merge($existingAcceptedUsers, $recipient));
                    $messageMeeting->accepted_users = implode(',', $newAcceptedUsers);
                } elseif ($request->type === 'decline') {
                    // Update declined_users
                    $existingDeclinedUsers = $messageMeeting->decline_users ? explode(',', $messageMeeting->decline_users) : [];
                    $newDeclinedUsers = array_unique(array_merge($existingDeclinedUsers, $recipient));
                    $messageMeeting->decline_users = implode(',', $newDeclinedUsers);
                }
    
                // Save the updated meeting data
                $messageMeeting->save();
            }
    
            $data = [
                'status_code' => 200,
                'message' => $request->type === 'accept' ? 'Meeting accepted!' : 'Meeting declined!',
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
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/sent-event-done",
     *     summary="sent event done",
     *     tags={"Messages"},
     *     description="Event done",
     *     operationId="eventDone",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Event Done Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"event_id","user_id", "type"},
     *                 @OA\Property(
     *                     property="event_id",
     *                     type="string",
     *                     example="111",
     *                     description="Enter event_id"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
     *                 ),
     *                 @OA\Property(
     *                     property="type",
     *                     type="string",
     *                     example="attend",
     *                     description="Enter 'attend' or 'notattend'"
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

     public function sentEventDone(Request $request)
     {
         try {
             $rules = [
                 'event_id' => 'required|string',
                 'user_id' => 'required|string|regex:/^(\d+)(,\d+)*$/',
                 'type' => 'required|string|in:attend,notattend', // Add type validation
             ];
     
             $messages = [
                 'event_id.required' => 'The event_id field is required.',
                 'event_id.string' => 'The event_id field must be a string.',
                 'user_id.required' => 'The user_id field is required.',
                 'user_id.string' => 'The user_id field must be a string.',
                 'user_id.regex' => 'The user_id field must be a comma-separated list of integers.',
                 'type.required' => 'The type field is required.',
                 'type.string' => 'The type field must be a string.',
                 'type.in' => 'The type field must be either attend or notattend.',
             ];
     
             $validator = Validator::make($request->all(), $rules, $messages);
             if ($validator->fails()) {
                 return response()->json([
                     'status_code' => 400,
                     'message' => $validator->errors(),
                     'data' => []
                 ]);
             }
     
             $recipient = explode(',', $request->user_id);
             $loginUser = auth()->user()->id;
     
             // Fetch the meeting record
             $eventDetails = ProjectEvent::where('id', $request->event_id)->first();
             if ($eventDetails) {
                 // Process based on type (accept or decline)
                 if ($request->type === 'attend') {
                     // Update attend_users
                     $existingAcceptedUsers = $eventDetails->attend_users ? explode(',', $eventDetails->attend_users) : [];
                     $newAcceptedUsers = array_unique(array_merge($existingAcceptedUsers, $recipient));
                     $eventDetails->attend_users = implode(',', $newAcceptedUsers);
                 } elseif ($request->type === 'notattend') {
                     // Update declined_users
                     $existingDeclinedUsers = $eventDetails->notAttend_users ? explode(',', $eventDetails->notAttend_users) : [];
                     $newDeclinedUsers = array_unique(array_merge($existingDeclinedUsers, $recipient));
                     $eventDetails->notAttend_users = implode(',', $newDeclinedUsers);
                 }
     
                 // Save the updated meeting data
                 $eventDetails->save();
             }
     
             $data = [
                 'status_code' => 200,
                 'message' => $request->type === 'attend' ? 'Event attend!' : 'Event NotAttend!',
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
             return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
         }
     } 

    /**
    * @OA\Post(
    *     path="/api/v1/tasks/comments",
    *     summary="Add a comment to a task",
    *     description="This endpoint allows authenticated users to add comments to a specific task.",
    *     operationId="addComment",
    *     tags={"Messages"},
    *     security={{"bearerAuth": {}}},
    *     @OA\RequestBody(
    *         required=true,
    *         @OA\MediaType(
    *             mediaType="multipart/form-data",
    *             @OA\Schema(
    *                 @OA\Property(property="comment", type="string", example="This is a sample comment."),
    *                 @OA\Property(property="task_chat_id", type="integer", example=318),
    *                 @OA\Property(property="message_id", type="integer", example=1434)
    *             )
    *         )
    *     ),
    *     @OA\Response(
    *         response=200,
    *         description="Comment added successfully",
    *         @OA\JsonContent(
    *             @OA\Property(property="success", type="boolean", example=true),
    *             @OA\Property(property="comment", type="object", 
    *                 @OA\Property(property="id", type="integer", example=1),
    *                 @OA\Property(property="task_id", type="integer", example=318),
    *                 @OA\Property(property="comment", type="string", example="This is a sample comment."),
    *                 @OA\Property(property="task_chat_id", type="integer", example=318),
    *                 @OA\Property(property="message_id", type="integer", example=1434),
    *                 @OA\Property(property="created_at", type="string", example="2024-11-20 10:30:00"),
    *                 @OA\Property(property="updated_at", type="string", example="2024-11-20 10:30:00")
    *             )
    *         )
    *     ),
    *     @OA\Response(
    *         response=400,
    *         description="Invalid input",
    *         @OA\JsonContent(
    *             @OA\Property(property="success", type="boolean", example=false),
    *             @OA\Property(property="message", type="string", example="Validation error.")
    *         )
    *     ),
    *     @OA\Response(
    *         response=401,
    *         description="Unauthorized",
    *         @OA\JsonContent(
    *             @OA\Property(property="success", type="boolean", example=false),
    *             @OA\Property(property="message", type="string", example="Token is invalid or expired.")
    *         )
    *     )
    * )
    */

    public function addComment(Request $request)
    {
        $loginUser = auth()->user()->id;

        $request->validate([
            'comment' => 'required|string',
            'task_chat_id' => 'required|exists:message_task,id',
            'message_id' => 'required|exists:message,id',
        ]);

        $comment = MessageTaskChatComment::create([
            'user_id' => $loginUser,
            'comment' => $request->comment,
            'task_chat_id' => $request->task_chat_id,
            'message_id' => $request->message_id,
        ]);

        return response()->json(['success' => true, 'comment' => $comment]);
    }



     /**
     * @OA\Post(
     *     path="/api/v1/getTasks/comments",
     *     summary="Fetch all comments with pagination",
     *     description="Retrieve all comments for a specific message and task with pagination.",
     *     operationId="getComments",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="message_id", type="integer", example=1434),
     *                 @OA\Property(property="task_id", type="integer", example=318),
     *                 @OA\Property(property="per_page", type="integer", example=10, description="Number of comments per page (optional)"),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Comments retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="comments", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="data", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="task_id", type="integer", example=318),
     *                         @OA\Property(property="message_id", type="integer", example=1434),
     *                         @OA\Property(property="comment", type="string", example="This is a sample comment."),
     *                         @OA\Property(property="task_chat_id", type="integer", example=318),
     *                         @OA\Property(property="user_id", type="integer", example=1),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2024-11-20T10:30:00.000000Z"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time", example="2024-11-20T10:30:00.000000Z")
     *                     )
     *                 ),
     *                 @OA\Property(property="first_page_url", type="string", example="http://example.com/api/v1/tasks/comments?page=1"),
     *                 @OA\Property(property="from", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=5),
     *                 @OA\Property(property="last_page_url", type="string", example="http://example.com/api/v1/tasks/comments?page=5"),
     *                 @OA\Property(property="next_page_url", type="string", example="http://example.com/api/v1/tasks/comments?page=2"),
     *                 @OA\Property(property="path", type="string", example="http://example.com/api/v1/tasks/comments"),
     *                 @OA\Property(property="per_page", type="integer", example=10),
     *                 @OA\Property(property="prev_page_url", type="string", example=null),
     *                 @OA\Property(property="to", type="integer", example=10),
     *                 @OA\Property(property="total", type="integer", example=50)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Token is invalid or expired.")
     *         )
     *     )
     * )
     */

    public function getComments(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:message,id',
            'task_id' => 'required|exists:message_task,id',
        ]);

        $comments = MessageTaskChatComment::where('message_id', $request->message_id)
            ->where('task_chat_id', $request->task_id)
            ->orderBy('created_at', 'desc') // Optional: Order by latest comments
            ->with(['user:id,profile']) // Eager load user data (selecting only the necessary fields)
            ->paginate($request->input('per_page', 10)); // Use the 'per_page' query parameter or default to 10

        // Iterate through the comments and modify the profile field
        $comments->getCollection()->transform(function ($comment) {
            $user = $comment->user; // Access the user object
            // Ensure you're passing only the relative path, not the full URL
            $comment->user->profile_url = $user->profile 
                ? setAssetPath('user-profile/' . $user->profile) // Only append the relative path here
                : setAssetPath('assets/media/avatars/blank.png');
            
            return $comment; // Return the modified comment
        });

        return response()->json(['success' => true, 'comments' => $comments]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/image-to-pdf",
     *     summary="Upload multiple images",
     *     tags={"Documents"},
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(
     *                     property="images[]",
     *                     type="array",
     *                     items=@OA\Items(type="file")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Images uploaded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Images uploaded successfully"),
     *             @OA\Property(property="files", type="array",
     *                 @OA\Items(type="string", example="images/filename.jpg")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid file format"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function imageToPdf(Request $request)
    {
         try {
             // Validate the request
             $request->validate([
                 'images' => 'required|array',
                 'images.*' => 'image|mimes:jpeg,png,jpg|max:5120', // Max size 5MB per file
             ]);
 
             // Upload images and store paths
             $uploadedImages = $request->file('images');
             $imageDirectory = public_path('images');
             if (!file_exists($imageDirectory)) {
                 mkdir($imageDirectory, 0755, true); // Create the directory if it doesn't exist
             }
 
             $uploadedImages = $request->file('images');
             $imagePaths = [];
 
             foreach ($uploadedImages as $key => $image) {
                 $imageHash = md5_file($image->getRealPath());
 
                 // Define a unique file name based on the hash
                 $filename = $imageHash . '.' . $image->getClientOriginalExtension();
                 $filePath = $imageDirectory . '/' . $filename;
     
                 // Check if the image already exists
                 if (!file_exists($filePath)) {
                     // Move the image to the target directory if it doesn't exist
                     $image->move($imageDirectory, $filename);
                 }
     
                 $imagePaths[] = [
                     'url' => asset("images/{$filename}"), // URL to be used in the PDF
                     'path' => $filePath, // Local path for file processing
                     'name' => $image->getClientOriginalName(), // Original file name
                 ];
             }
 
             // Generate PDF content
             $htmlContent = '<html><body>';
             foreach ($imagePaths as $index => $image) {
                 $htmlContent .= '<div style="margin-bottom: 20px; text-align: center;">';
                 // Use the URL directly in the img src
                 $imageData = base64_encode(file_get_contents($image['path']));
                 $htmlContent .= "<img src='data:image/png;base64,{$imageData} ' style='width:100%; height:auto; margin-bottom: 10px;' />";
                 $htmlContent .= '</div>';
             }
             $htmlContent .= '</body></html>';
 
             // Generate PDF
             $pdf = Pdf::loadHTML($htmlContent);
             $pdf->set_option('isHtml5ParserEnabled', true);
             $pdf->set_option('DOMPDF_ENABLE_REMOTE', true); // Allow remote images
 
             $pdfPath = 'pdfs/' . time() . '.pdf';
             Storage::put("public/{$pdfPath}", $pdf->output());
 
             // Delete images from the folder
             foreach ($imagePaths as $image) {
                 if (file_exists($image['path'])) {
                     unlink($image['path']); // Delete the image file
                 }
             }
 
             // Return response
             return response()->json([
                 'status' => true,
                 'message' => 'PDF generated successfully!',
                 'pdf_url' => asset("public/storage/{$pdfPath}"),
             ], 200);
         } catch (\Illuminate\Validation\ValidationException $e) {
             return response()->json([
                 'status' => 'Failure',
                 'message' => 'Validation error',
                 'data' => $e->errors(),
             ], 422);
         } catch (\Exception $e) {
             return response()->json([
                 'status' => 'Failure',
                 'message' => 'Internal server error',
                 'data' => $e->getMessage(),
             ], 500);
         }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/get-single-message",
     *     summary="Get message details",
     *     description="Retrieve detailed information about a specific message by its ID.",
     *     operationId="getSingleMessage",
     *     tags={"Messages"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Get message details",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"message_id"},
     *                 @OA\Property(
     *                     property="message_id",
     *                     type="integer",
     *                     example=1,
     *                     description="ID of the message to be updated"
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="America/New_York",
     *                     description="Timezone of the user making the request",
     *                     nullable=true
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message details retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Message details retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *             )
     *         ),
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="The message_id field is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Message not found",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="Message not found.")
     *         )
     *     )
     * )
     */

    public function getSingleMessage(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:message,id',
        ]);
    
        $message = Message::with('tasks')->find($request->message_id);
        if (!$message) {
            return response()->json(['status_code' => 404, 'message' => 'Message not found.', 'data' => ""]);
        }
    
        $loginUser = auth()->id();
    
        // Fetch task details
        $taskDetails = MessageTask::where('message_id', $request->message_id)->whereNull('deleted_at')->get();
    
        if ($taskDetails->isEmpty()) {
            return response()->json(['status_code' => 404, 'message' => 'No tasks found for this message.', 'data' => ""]);
        }
    
        $taskDetails_task = $taskDetails->first();
    
        // Fetch users assigned to tasks
        $userIds = collect($taskDetails)->pluck('users')->flatMap(fn($users) => explode(',', $users))->unique()->filter();
        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'country_code', 'mobile', 'profile']);
    
        $users = $users->map(function ($user) use ($taskDetails, $message) {
            $user->profile = $user->profile ? setAssetPath("user-profile/{$user->profile}") : setAssetPath('assets/media/avatars/blank.png');
            $user->task_ids = '';
            $allTaskId = $doneTaskId = [];
            $allTasksCheckedByOthers = true;
    
            foreach ($taskDetails as $task) {
                $checkedUsers = array_filter(explode(',', $task->task_checked_users));
                $checkedByOthers = array_diff($checkedUsers, [$message->created_by]);
    
                if (in_array($user->id, $checkedUsers)) {
                    $doneTaskId[] = $task->id;
                }
    
                if (empty($checkedByOthers) || count($checkedByOthers) < count(explode(',', $task->users)) - 1) {
                    $allTasksCheckedByOthers = false;
                }
    
                $allTaskId[] = $task->id;
            }
    
            $user->task_done = ($user->id == $message->created_by) ? $allTasksCheckedByOthers : false;
            return $user;
        });
    
        // Fetch checked user profiles
        $checkedUserIds = $taskDetails->pluck('task_checked_users')->flatMap(fn($users) => explode(',', $users))->unique()->filter();
        $checkedUserProfiles = User::whereIn('id', $checkedUserIds)->get(['id', 'profile'])->mapWithKeys(fn($user) => [
            $user->id => setAssetPath($user->profile ? "user-profile/{$user->profile}" : 'assets/media/avatars/blank.png')
        ]);
    
        // Fetch comments
        $taskComments = MessageTaskChatComment::with('user')
            ->whereIn('task_chat_id', $taskDetails->pluck('id'))
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('task_chat_id');
    
        $tasks = $taskDetails->map(function ($task) use ($checkedUserProfiles, $taskComments) {
            return [
                'id' => $task->id,
                'message_id' => $task->message_id,
                'checkbox' => $task->checkbox,
                'task_checked' => (bool) $task->task_checked,
                'task_checked_users' => implode(',', array_filter(explode(',', $task->task_checked_users))),
                'profiles' => collect(explode(',', $task->task_checked_users))->map(fn($id) => [
                    'id' => $id,
                    'profile_url' => $checkedUserProfiles[$id] ?? setAssetPath('assets/media/avatars/blank.png')
                ])->values(),
                'comments' => $taskComments[$task->id] ?? [],
                'priority_task' => $task->priority_task,
            ];
        });
    
        return response()->json([
            'status_code' => 200,
            'message' => 'Message details retrieved successfully.',
            'data' => [
                'messageId' => $request->message_id,
                'messageType' => $message->message_type,
                'attachmentType' => $message->attachment_type,
                'date' => Carbon::parse($message->created_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s'),
                'time' => Carbon::parse($message->updated_at)->setTimezone($request->timezone ?? 'UTC')->format('h:i a'),
                'timeZone' => Carbon::parse($message->updated_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d\TH:i:s.u\Z'),
                'sentBy' => ($message->created_by == $loginUser) ? 'loginUser' : 'User',
                'messageDetails' => [
                    'task_name' => $taskDetails_task->task_name,
                    'date' => $message->date,
                    'time' => $message->time,
                    'users' => $users,
                    'tasks' => $tasks,
                ],
            ],
        ]);
    }
     
}