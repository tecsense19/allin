<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTaskChatComment extends Model
{
    use HasFactory;

    protected $fillable = ['task_chat_id', 'message_id', 'user_id', 'comment'];

    public function taskChat()
    {
        return $this->belongsTo(Task::class, 'task_chat_id');
    }

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
