<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyTask extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'daily_tasks'; // Table name

    protected $fillable = [
        'task_day', 
        'task_time', 
        'payload',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'payload' => 'array', // Automatically converts JSON to an array
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];
}
