<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\OutboundPart;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OutboundPart> */
class OutboundPartFactory extends Factory
{
    public function definition(): array
    {
        return ['execution_id' => Execution::factory(), 'conversation_head_id' => fn (array $attributes) => Execution::findOrFail($attributes['execution_id'])->message->conversation_head_id, 'source_key' => fake()->unique()->uuid(), 'local_order' => 1, 'part_index' => 0, 'text' => 'Arquivo criado.'];
    }
}
