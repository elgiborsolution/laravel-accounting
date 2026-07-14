<?php

namespace ESolution\LaravelAccounting\Database\Factories;

use ESolution\LaravelAccounting\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition()
    {
        return [
            'code' => $this->faker->unique()->numerify('####'),
            'name' => $this->faker->word,
            'description' => null,
            'is_postable' => true,
            'status' => true,
        ];
    }
}
