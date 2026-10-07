<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'started_at' => 'immutable_datetime', 'last_accepted_at' => 'immutable_datetime'];
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(ConversationHead::class, 'conversation_head_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }
}
