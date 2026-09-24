<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Laptop', 'Inkjet Printer', 'Monitor 24in', 'HDMI Cable', 'Wireless Mouse', 'Black Ink', 'SSD 1TB'])
            .' '.fake()->unique()->bothify('??-###');

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'brand' => fake()->randomElement(['Acer', 'Epson', 'Canon', 'Logitech', 'Samsung', 'Lenovo']),
            'model' => fake()->bothify('M-####'),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'description' => fake()->sentence(12),
            'price' => '1000.00',
            'low_stock_threshold' => 3,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
