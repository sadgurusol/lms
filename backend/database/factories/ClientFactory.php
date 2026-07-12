<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => 'ABC School',
            'slug' => 'abc-'.Str::lower(Str::random(6)),
            'status' => Client::ACTIVE,
            'integration' => Client::CUSTOM_JWT,
            'contact_email' => 'it@abcschool.edu',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
