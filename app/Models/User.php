<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Http\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements  JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, CreatedUpdatedBy;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'country_code',
        'mobile',
        'password',
        'description',
        'profile',
        'cover_image',
        'instagram_profile_url',
        'facebook_profile_url',
        'twitter_profile_url',
        'youtube_profile_url',
        'linkedin_profile_url',
        'role',
        'status',
        'remember_token',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $auditEvents = [
        'created',
        'updated',
        'deleted'
    ];

    /**
    * Get the identifier that will be stored in the subject claim of the JWT.
    *
    * @return mixed
    */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    public function getUserDetailsUsingMobile($country_code, $mobile){
        $query = User::where('country_code',$country_code)
                    ->where('mobile',$mobile)
                    ->first();
        return $query;
    }
}
