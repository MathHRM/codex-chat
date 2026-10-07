<?php

namespace Database\Factories;

use App\Models\ConversationHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationHead> */
class ConversationHeadFactory extends Factory
{
    public function definition(): array
    {
        return ['instance' => 'test-'.fake()->unique()->uuid(), 'number' => '5511999990000'];
    }
}
