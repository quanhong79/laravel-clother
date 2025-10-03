<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id','sender_id','sender_is_admin','body','read_at'
    ];

    protected $casts = [
        'sender_is_admin' => 'bool',
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
