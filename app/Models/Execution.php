<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(OutboundPart::class);
    }
}
