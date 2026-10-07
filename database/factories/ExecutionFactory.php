<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\InboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Execution> */
class ExecutionFactory extends Factory
{
    public function definition(): array
    {
        return ['inbound_message_id' => InboundMessage::factory(), 'runner_id' => (string) Str::uuid()];
    }
}
