<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::updateOrCreate(
            ['title' => 'General'],
            [
                'image_path' => null,
                'is_active' => true,
            ],
        );
    }
}
