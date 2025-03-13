<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockUser extends Model
{
    use HasFactory;

    protected $table = 'block_users'; // Table name

    protected $fillable = [
        'from_id', 
        'to_id'
    ];
}
