<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InboundMessage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['local_order' => 'integer', 'accepted_at' => 'immutable_datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(ConversationHead::class, 'conversation_head_id');
    }

    public function execution(): HasOne
    {
        return $this->hasOne(Execution::class);
    }
}
