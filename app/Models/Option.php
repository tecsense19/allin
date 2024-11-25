<?php
// Option.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    use HasFactory;

    protected $fillable = ['message_id', 'option', 'option_id'];

    // Define the relationship with Message
    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
