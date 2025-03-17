<?php

namespace App\Console\Commands;

use App\Events\MessageSent;
use Illuminate\Console\Command;
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

class SendDailyTaskCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'task:send-daily';

    /**
     * The console command description.
     */
    protected $description = 'Send daily task messages to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dailyTask = DailyTask::get();
        $todayName = date("l"); // Today's day name
        $currentTime = date("H:i:00"); // Current time (exact hour and minute)
        $currentDateTime = date("Y-m-d H:i:00"); // Current date with exact time
        
        foreach ($dailyTask as $dTask) {

            $days = explode(',', $dTask->task_day); // Convert to array
            $taskTime = date("H:i:00", strtotime($dTask->task_time)); // Task time in H:i format
            if (in_array($todayName, $days)) {
                // If today is in the list, check the time window
                if ($currentTime === $taskTime) {
                    // Send task at the exact time
                    \Log::info('Start Daily task messages have been dispatched taskId----> ' . $dTask->id . ' currentDate----> ' . $currentDateTime . ' currentTime----> ' . $currentTime . ' taskTime----> ' . $taskTime);

                    $receiverIdsArray = explode(',', implode(',', $dTask->payload['users']));
                    $senderId = $dTask->created_by;
                    
                    $msg = new Message();
                    $msg->message_type = $dTask->payload['message_type'];
                    $msg->status = "Unread";
                    $msg->date = $dTask->task_time;
                    $msg->time = $dTask->task_time;
                    $msg->created_by = $senderId;
                    $msg->save();

                    $createdUser = User::where('id', $senderId)->first();
            
                    $receiverIdsArray[] = $senderId;
                    $uniqueIdsArray = array_unique($receiverIdsArray);
                    $mergedIds = implode(',', $uniqueIdsArray);

                    $task_name_Array = explode(',', implode(',', $dTask->payload['checkbox']));
                    $task_name_UArray = array_unique($task_name_Array);

                    foreach ($task_name_UArray as $index => $taskName) { // Loop through multiple task names
                        $messageTask = new MessageTask();
                        $messageTask->message_id = $msg->id;
                        $messageTask->task_name = $dTask->payload['task_name'];
                        
                        // Use the corresponding task description if available
                        $messageTask->task_description = null;
                        
                        $messageTask->checkbox = $taskName; // Save each task name
                        $messageTask->users = $mergedIds;
                        $messageTask->created_by = $senderId;
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
                            'message_type' => $dTask->payload['message_type'],
                            'task_name' => $dTask->payload['task_name'], // You may want to send all task names here
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
                            'body' => 'Tasks: ' . $dTask->payload['task_name'], // Multiple task names
                            'image' => "",
                        ];
            
                        if (count($validTokens) > 0) {
                            sendPushNotification($validTokens, $notification, $message);
                        }
                    }

                    \Log::info('End Daily task messages have been dispatched taskId----> ' . $dTask->id . ' currentDate----> ' . $currentDateTime . ' currentTime----> ' . $currentTime . ' taskTime----> ' . $taskTime);
                }
            }
        }
    }
}
