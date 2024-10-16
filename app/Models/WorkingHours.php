<?php

namespace App\Models;

use App\Http\Traits\CreatedUpdatedBy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkingHours extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $table = 'work_hours';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'start_date_time' => 'datetime',
        'end_date_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'start_date_time',
        'end_date_time',
        'summary',
        'location',
        'timezone',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * Get the user that owns the work hours.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Set the start_date_time attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setStartDateTimeAttribute($value)
    {
        $this->attributes['start_date_time'] = Carbon::parse($value);
    }

    /**
     * Set the end_date_time attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setEndDateTimeAttribute($value)
    {
        $this->attributes['end_date_time'] = Carbon::parse($value);
    }
}
