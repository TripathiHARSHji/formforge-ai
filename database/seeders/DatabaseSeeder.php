<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Form::create([
            'title' => 'Sample internship application',
            'description' => 'Seeded demo form',
            'schema' => [
                'title' => 'Sample internship application',
                'fields' => [
                    ['id' => 'name', 'type' => 'text', 'label' => 'Full name', 'key' => 'name', 'required' => true],
                    ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'required' => true],
                ],
            ],
            'public_uuid' => '11111111-1111-1111-1111-111111111111',
            'status' => 'published',
        ]);
    }
}
