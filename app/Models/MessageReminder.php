<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageReminder extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'message_reminder';

    protected $fillable = [
        'message_id',
        'title',
        'description',
        'date',
        'time',
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
}
