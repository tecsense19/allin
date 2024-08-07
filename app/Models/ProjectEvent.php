<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectEvent extends Model
{
    use HasFactory, CreatedUpdatedBy, SoftDeletes;

    protected $table = 'project_events';

    protected $fillable = [
        'user_id',
        'event_title',
        'event_description',
        'event_image',
        'event_date',
        'event_time',
        'latitude',
        'longitude',
        'location_url',
        'location',
        'users',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];
}
