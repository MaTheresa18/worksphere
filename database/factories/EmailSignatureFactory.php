<?php

namespace Database\Factories;

use App\Models\EmailSignature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailSignatureFactory extends Factory
{
    protected $model = EmailSignature::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'content' => $this->faker->randomHtml(),
            'is_default' => false,
        ];
    }
}
