<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationHead extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_order' => 'integer', 'last_accepted_at' => 'immutable_datetime'];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function currentConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'current_conversation_id');
    }
}
