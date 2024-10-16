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
        'deleted'
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function chats()
    {
        return $this->hasMany(MessageTaskChat::class, 'task_id');
    }
    
}