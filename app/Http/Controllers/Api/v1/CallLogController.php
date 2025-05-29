<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CallLog;
use App\Models\BlockUser;
use App\Models\User;
use App\Models\MessageSenderReceiver;
use App\Models\Group;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use App\Models\deleteChatUsers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CallLogController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/call-log",
     *     summary="Store a new call log",
     *     tags={"Call Logs"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"receiver_id"},
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="receiver_id", type="integer", format="int64", example=2)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Call log created",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=5),
     *             @OA\Property(property="time_min", type="integer", example=20)
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation failed"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function store(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|string',
            'receiver_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 422,
                'message' => $validator->errors()->first(),
                'data' => ''
            ]);
        }

        $validated = $validator->validated();

        $senderId = auth()->user()->id;

        if(isset($validated['id']) && $validated['id'] != '') {
            $call = CallLog::where('id', $validated['id'])->update([
                'sender_id' => $senderId,
                'receiver_id' => $validated['receiver_id'],
                'call_end_time' => now()
            ]);

            $call = CallLog::where('id', $validated['id'])->first();

            // $start = Carbon::parse($validated['call_start_time']);
            // $end = Carbon::parse($validated['call_end_time']);
            // $timeMin = $start->diffInMinutes($end);
        } else {
            // Store the call log with sender_id
            $call = CallLog::create([
                'sender_id' => $senderId,
                'receiver_id' => $validated['receiver_id'],
                'call_start_time' => now()
            ]);
        }
        

        return response()->json([
            'status_code' => 201,
            'message' => 'Call log created successfully.',
            'data' => $call
        ], 201);
    } catch (\Exception $e) {
        Log::error([
            'method' => __METHOD__,
            'error' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'message' => $e->getMessage()
            ],
            'created_at' => now()->format('Y-m-d H:i:s')
        ]);

        return response()->json([
            'status_code' => 500,
            'message' => 'Something went wrong while saving call log.',
            'data' => ''
        ]);
    }
}

    /**
     * @OA\Post(
     *     path="/api/v1/call-user-list",
     *     summary="User Call List",
     *     tags={"Call Logs"},
     *     description="User List",
     *     operationId="callUserList",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="timezone",
     *         in="query",
     *         example="",
     *         description="Enter Timezone",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         example="",
     *         description="Enter Search Value",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
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
  
     public function callUserList(Request $request)
     {
     try {
         $login_user_id = auth()->user()->id;

         $deletedUsers = deleteChatUsers::where('user_id', $login_user_id)->pluck('deleted_user_id');
         $blockUsers = BlockUser::where('from_id', $login_user_id)->pluck('to_id');
         $removeUsers = $deletedUsers->merge($blockUsers)->toArray();
 
         // Fetch users
         $users = User::where('id', '!=', $login_user_id)
             ->where('role', 'User')
             ->where('status', 'Active')
             ->where(function ($q) use ($request) {
                 if (@$request->search) {
                     $q->where(function ($qq) use ($request) {
                         $searchTerm = '%' . $request->search . '%';
                         $qq->where('first_name', 'LIKE', $searchTerm)
                             ->orWhere('last_name', 'LIKE', $searchTerm)
                             ->orWhere('mobile', 'LIKE', $searchTerm)
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm])
                             ->orWhereRaw("CONCAT(first_name, last_name) LIKE ?", [$searchTerm]);
                     });
                 }
             })
             ->whereNotIn('id', $removeUsers)
             ->whereNull('deleted_at')
             ->with(['sentMessages' => function ($query) use ($login_user_id) {
                 $query->where('receiver_id', $login_user_id)
                     ->whereNull('deleted_at');
             }, 'receivedMessages' => function ($query) use ($login_user_id) {
                 $query->where('sender_id', $login_user_id)
                     ->whereNull('deleted_at');
             }])
             ->get()
             ->map(function ($user) use ($login_user_id, $request) {
                 $lastMessage = null;
                 $messages = $user->sentMessages->merge($user->receivedMessages)->sortByDesc('created_at');
                 $filteredMessages = $messages->reject(function ($message) {
                     return $message->message && $message->message->message_type == 'Task Chat';
                 });
 
                  // Exclude group messages (where group_id is not null)
                 $filteredMessages = $messages->filter(function ($message) {
                     return is_null($message->message->group_id); // Exclude messages with group_id
                 });
 
                 $lastMessage = $filteredMessages->first();
                 if ($lastMessage && $lastMessage->message) {
                     $msg = ($lastMessage->message->message_type == 'Text') 
                         ? $lastMessage->message->message 
                         : $lastMessage->message->message_type;
                     
                     $lastMessageContent = @$msg ? $msg : null;
                     $lastMessageDate = $lastMessage->created_at 
                         ? Carbon::parse($lastMessage->created_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s')
                         : null;
                 } else {
                     $lastMessageContent = null;
                     $lastMessageDate = null;
                 }
 
                 // Count unread messages, excluding deleted messages
                 $unreadMessageCount = MessageSenderReceiver::where(function ($query) use ($user, $login_user_id) {
                     $query->where('sender_id', $user->id)
                         ->where('receiver_id', $login_user_id);
                 })
                 ->whereHas('message', function ($q) {
                     $q->where('status', 'Unread')
                         ->where('message_type', '!=', 'Task Chat')
                         ->whereNull('group_id') // Exclude messages with group_id
                         ->whereNull('deleted_at');
                 })
                 ->count();
 
                 return [
                     'id' => $user->id,
                     'first_name' => $user->first_name,
                     'last_name' => $user->last_name,
                     'country_code' => $user->country_code,
                     'mobile' => $user->mobile,
                     'profile' => @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png'),
                     'last_message' => $lastMessageContent,
                     'last_message_date' => $lastMessageDate,
                     'unread_message_count' => $unreadMessageCount,
                     'type' => 'user' // Identifying this entry as a user
                 ];
             });
 
                 $groups = Group::where('status', 'Active')
                 ->whereIn('id', function ($query) use ($login_user_id) {
                     $query->select('group_id')
                         ->from('group_members')
                         ->where('user_id', $login_user_id) // User is a member of the group  
                         ->whereNull('deleted_at')                      
                         ->orWhere('created_by', $login_user_id) // User created the group
                         ->whereNull('deleted_at');
                 })
                 ->get()
                 ->map(function ($group) use ($login_user_id, $request) {
                     // Fetch the last message in the group
                     $lastMessage = Message::where('group_id', $group->id)
                         ->whereNull('deleted_at')
                         ->where('message_type', '!=', 'Task Chat')
                         ->orderByDesc('created_at')
                         ->first();
                       
                    $group_date = Group::where('id', $group->id)->first();
 
                     // Get the content and date of the last message
                     $lastMessageContent = $lastMessage
                         ? (($lastMessage->message_type == 'Text') ? $lastMessage->message : $lastMessage->message_type)
                         : null;
 
                     $lastMessageDate = $lastMessage && $lastMessage->created_at
                         ? Carbon::parse($lastMessage->created_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s')
                         : null;
 
                     // Count unread messages in the group
                     $unreadgroupMessageCount = MessageSenderReceiver::whereHas('message', function ($q) use ($group, $login_user_id) {
                         $q->where('group_id', $group->id)
                         ->where('status', 'Unread')
                         ->where('message_type', '!=', 'Task Chat')
                         ->whereNull('deleted_at');
                     })
                     ->where('receiver_id', $login_user_id)
                     ->count();
 
 
                     return [
                         'id' => $group->id,
                         'name' => $group->name,
                         'profile' => @$group->profile_pic ? setAssetPath('group-profile/' . $group->profile_pic) : setAssetPath('assets/media/avatars/blank.png'),
                         'last_message' => $lastMessageContent,
                         'last_message_date' => $lastMessageDate ? $lastMessageDate : $group_date->created_at->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s'),
                         'unread_message_count' => $unreadgroupMessageCount,
                         'type' => 'group' // Identifying this entry as a group
                     ];
                 });
 
 
 
         // Merge and sort by last_message_date
         $allEntries = $users->merge($groups)->sortByDesc('last_message_date')->values();

         $callLogs = CallLog::where('sender_id', $login_user_id)->select('receiver_id', DB::raw('MAX(call_start_time) as latest_call_time'))->groupBy('receiver_id')->orderByDesc('latest_call_time')->get();

        $callLogsMap = $callLogs->keyBy('receiver_id');

        $allEntries = $allEntries->map(function ($entry) use ($callLogsMap) {
            // Only for entries with 'id' matching callLogs receiver_id (users only, groups probably don't have call logs)
            if (isset($callLogsMap[$entry['id']])) {
                $entry['latest_call_time'] = $callLogsMap[$entry['id']]->latest_call_time;
            } else {
                $entry['latest_call_time'] = null;
            }
            return $entry;
        });
        

        $allEntries = $allEntries->sortByDesc('last_message_date')->sortByDesc('latest_call_time')->values();
 
         $data = [
             'status_code' => 200,
             'message' => "Data fetched successfully.",
             'data' => [
                 'userList' => $allEntries
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
                 'created_at' => date("Y-m-d H:i:s")
             ]);
 
             return $this->sendJsonResponse([
                 'status_code' => 500,
                 'message' => 'Something went wrong.'
             ]);
         }
     }

}
