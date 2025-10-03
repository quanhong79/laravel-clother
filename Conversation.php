<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $fillable = [
        'user_id','status','last_message_at','unread_user_count','unread_admin_count'
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }
}
