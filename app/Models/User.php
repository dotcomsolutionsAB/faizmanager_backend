<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    // Specify the guard for Spatie
    protected $guard_name = 'sanctum';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 'email', 'password','jamiat_id', 'family_id', 'title', 'its', 'hof_its', 'family_its_id', 'mobile', 'address', 'building', 'flat_no', 'lattitude', 'longitude', 'gender', 'date_of_birth', 'folio_no', 'sector', 'sub_sector', 'thali_status', 'status', 'username', 'mumeneen_type','role', 'photo_id'
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Define the relationship to the UploadModel
    public function photo()
    {
        return $this->belongsTo(UploadModel::class, 'photo_id', 'id');
    }
}
