<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundPart extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['local_order' => 'integer', 'part_index' => 'integer', 'attempts' => 'integer', 'retry_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(ConversationHead::class, 'conversation_head_id');
    }
}
