<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class userSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@yopmail.com',
            'country_code' => '+91',
            'mobile' => '1234567890',
            'password' => Hash::make('123456'),
            'role' => 'Admin',
            'status' => 'Active'
        ]);
    }
}
