<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTaskChat extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'message_task_chat';

    protected $fillable = [
        'task_id',
        'message_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];

    public function task()
    {
        return $this->belongsTo(MessageTask::class, 'task_id');
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
