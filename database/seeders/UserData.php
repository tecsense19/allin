<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserData extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'first_name' => 'Test User',
            'email' => 'user@yopmail.com',
            'country_code' => '+91',
            'mobile' => '9876543210',
            'role' => 'User',
            'status' => 'Active',
        ]);
    }
}
