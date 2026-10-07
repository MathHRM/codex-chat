<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationHead;
use App\Models\InboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InboundMessage> */
class InboundMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'conversation_head_id' => fn (array $attributes) => Conversation::findOrFail($attributes['conversation_id'])->conversation_head_id, 'instance' => fn (array $attributes) => ConversationHead::findOrFail($attributes['conversation_head_id'])->instance, 'external_id' => fake()->unique()->uuid(), 'text' => 'Crie exemplo.txt', 'local_order' => 1, 'accepted_at' => now('UTC')];
    }
}
