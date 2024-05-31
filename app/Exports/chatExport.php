<?php

namespace App\Exports;

use App\Models\Message;
use App\Models\MessageSenderReceiver;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;


class ChatExport implements FromCollection, WithHeadings
{
    protected $login_user_id;
    protected $id;
    protected $timezone;

    public function __construct($login_user_id, $id, $timezone)
    {
        $this->id = $id;
        $this->login_user_id = $login_user_id;
        $this->timezone = $timezone;
    }

    public function collection()
    {
        $loginUser = $this->login_user_id;
        $userId = $this->id;

        $messages = MessageSenderReceiver::where(function ($query) use ($loginUser, $userId) {
            $query->where('sender_id', $loginUser)->where('receiver_id', $userId);
        })->orWhere(function ($query) use ($loginUser, $userId) {
            $query->where('sender_id', $userId)->where('receiver_id', $loginUser);
        })
            ->whereNull('deleted_at')
            ->with([
                'message',
                'message.attachment:id,message_id,attachment_name,attachment_path',
                'message.task:id,message_id,task_name,task_description',
                'message.location:id,message_id,latitude,longitude,location_url',
                'message.meeting:id,message_id,mode,title,description,date,start_time,end_time,meeting_url'
            ])
            ->orderByDesc('created_at')
            ->get();

        $data = [];

        foreach ($messages as $message) {
            $messageDetails = [];
            switch ($message->message->message_type) {
                case 'Text':
                    $messageDetails = $message->message->message;
                    break;
                case 'Attachment':
                    $messageDetails = $message->message->attachment;
                    break;
                case 'Location':
                    $messageDetails = $message->message->location;
                    break;
                case 'Meeting':
                    $messageDetails = $message->message->meeting;
                    break;
                case 'Task':
                    $messageDetails = $message->message->task;
                    break;
            }

            // Skip if messageDetails is null or empty
            if (is_null($messageDetails) || (is_array($messageDetails) && count($messageDetails) <= 0)) {
                continue;
            }

            $messageDetails = is_array($messageDetails) ? json_encode($messageDetails) : $messageDetails;

            $messageDate = $this->timezone ? Carbon::parse($message->message->created_at)->setTimezone($this->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->message->created_at)->format('Y-m-d H:i:s');
            $messageTime = $this->timezone ? Carbon::parse($message->message->created_at)->setTimezone($this->timezone)->format('h:i a') : Carbon::parse($message->message->created_at)->format('h:i a');

            if ($message->sender_id == $loginUser) {
                $data[] = [
                    'Sender' => $messageDetails,
                    'Receiver' => '',
                    'Message Date' => $messageDate,
                    'Message Time' => $messageTime
                ];
            } else {
                $data[] = [
                    'Sender' => '',
                    'Receiver' => $messageDetails,
                    'Message Date' => $messageDate,
                    'Message Time' => $messageTime
                ];
            }
        }

        return new Collection($data);
    }

    public function headings(): array
    {
        return [
            'Sender',
            'Receiver',
            'Message Date',
            'Message Time'
        ];
    }
}
