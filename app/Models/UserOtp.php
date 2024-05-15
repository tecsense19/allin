<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserOtp extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $table = 'user_otp';

    protected $fillable = [
        'user_id',
        'country_code',
        'mobile',
        'otp',
        'status',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];
}
