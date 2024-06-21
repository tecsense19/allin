<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\MessageSenderReceiver;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class setReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:set-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set Reminder';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $currentDate = Carbon::parse(now())->format('Y-m-d');
        $currentTime = Carbon::parse(now())->format('H:i');
        $reminders = Reminder::whereDate('date', $currentDate)->whereTime('time', $currentTime)->where('sent','Pending')->get();
        if (count($reminders) > 0) {
            foreach ($reminders as $reminder) {
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

                    $message = [
                        'id' => $message->id,
                        'sender' => $senderId,
                        'receiver' => $receiverId,
                        'message_type' => "Reminder",
                        'title' => $reminder->title,
                        'description' => @$reminder->description ? $reminder->description : NULL,
                        'date' => @$reminder->date ? Carbon::parse($reminder->date)->format('Y-m-d') : NULL,
                        'time' => @$reminder->time ? Carbon::parse($reminder->time)->format('H:i:s') : NULL,
                        'users' => $reminder->users,
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
                    $userDetails = User::find($senderId);
                    $notification = [
                        'title' => $userDetails->first_name . ' ' . $userDetails->last_name,
                        'body' => 'Reminder: ' . @$reminder->title ? $reminder->title : '',
                        'image' => "",
                    ];

                    if (count($validTokens) > 0) {
                        sendPushNotification($validTokens, $notification, $message);
                    }
                }

                $reminderData = Reminder::find($reminder->id);
                $reminderData->sent = 'Sent';
                $reminderData->save();


            }
        }
    }
}
