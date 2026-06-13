<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 1;

    public const ROLE_USER = 2;

    public const GENDER_LABELS = [0 => '未指定', 1 => '男', 2 => '女'];

    protected $fillable = [
        'username',
        'name',
        'gender',
        'unit',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_at' => 'datetime',
            'password' => 'hashed',
            'gender' => 'integer',
            'role' => 'integer',
            'failed_attempts' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** 參與的專案（pivot：role） */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'ques_authes', 'user_id', 'ques_id')
            ->withPivot('role')
            ->withTimestamps();
    }
}
