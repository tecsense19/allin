<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    use HasFactory;

    protected $table = 'call_log';

    protected $fillable = ['sender_id','receiver_id','call_start_time','call_end_time'];
}
