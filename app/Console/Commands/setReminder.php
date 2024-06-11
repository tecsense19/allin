<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\MessageSenderReceiver;
use App\Models\Reminder;
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
                foreach ($receiverIdsArray as $receiverId) {
                    $messageSenderReceiver = new MessageSenderReceiver();
                    $messageSenderReceiver->message_id = $message->id;
                    $messageSenderReceiver->sender_id = $senderId;
                    $messageSenderReceiver->receiver_id = $receiverId;
                    $messageSenderReceiver->save();
                }

                $messageReminder = new MessageReminder();
                $messageReminder->message_id = $message->id;
                $messageReminder->title = $reminder->title;
                $messageReminder->description = $reminder->description;
                $messageReminder->date = $reminder->date;
                $messageReminder->time = $reminder->time;
                $messageReminder->created_by = $reminder->created_by;
                $messageReminder->save();

                $reminderData = Reminder::find($reminder->id);
                $reminderData->sent = 'Sent';
                $reminderData->save();
            }
        }
    }
}
