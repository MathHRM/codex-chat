<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_head_id' => ConversationHead::factory(), 'generation' => 1, 'started_at' => now('UTC'), 'last_accepted_at' => now('UTC')];
    }
}
