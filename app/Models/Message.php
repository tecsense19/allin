<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'message';

    protected $fillable = [
        'message_type',
        'attachment_type',
        'message',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];

    public function senderReceivers()
    {
        return $this->hasMany(MessageSenderReceiver::class);
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function tasks()
    {
        return $this->hasMany(MessageTask::class);
    }

    public function locations()
    {
        return $this->hasMany(MessageLocation::class);
    }

    public function meetings()
    {
        return $this->hasMany(MessageMeeting::class);
    }
}