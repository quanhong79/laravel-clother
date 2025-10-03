<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
    'name','email','password',
    'phone','address','district','city','country',
    'language','notify_email','notify_sms','default_payment_method',
];

protected $casts = [
    'email_verified_at' => 'datetime',
    'notify_email' => 'boolean',
    'notify_sms'   => 'boolean',
];

    protected $hidden = ['password','remember_token'];

    // Hiển thị tên vai trò đẹp hơn
    public function getRoleNameAttribute(): string
    {
        return ($this->role === 'admin') ? 'Admin' : 'User';
    }
    public function reviews(){
    return $this->hasMany(\App\Models\Review::class);
}
}