<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTask extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'message_task';

    protected $fillable = [
        'message_id',
        'task_name',
        'task_description',
        'task_checked',
        'task_checked_users',
        'checkbox',
        'users',
        'read_status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }

    public function chats()
    {
        return $this->hasMany(MessageTaskChat::class, 'task_id');
    }

    public function getChats()
    {
        return $this->hasMany(MessageTaskChatComment::class, 'task_chat_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'task_checked_users');
    }

    public function getUserProfile()
    {
        return $this->belongsTo(User::class, 'profile','id');
    }
    
    public function getUserDetails()
    {
        return $this->belongsTo(User::class, 'user_id'); // Adjust 'user_id' to your actual foreign key
    }

}
