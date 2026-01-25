<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'subject' => $this->faker->sentence(),
            'body' => $this->faker->randomHtml(),
        ];
    }
}
