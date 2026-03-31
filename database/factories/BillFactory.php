<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'creator_id' => User::factory(),
            'title' => fake()->sentence(3),
            'total_amount' => fake()->randomFloat(2, 100, 10000),
            'bill_date' => fake()->date(),
        ];
    }
}
