<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Item;
use App\Models\Supplier;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample catalog for local development: a few items, one with an older clearance batch.
 * Stock goes through StockService so the movement ledger stays consistent.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoCatalogSeeder only runs in local or testing.');
        }

        if (Item::query()->exists()) {
            return;
        }

        Supplier::query()->firstOrCreate(['name' => 'Metro Tech Distributors'], ['phone' => '0287001234', 'email' => 'orders@metrotech.example']);

        $catalog = [
            'Laptops' => [
                ['Acer Aspire 5 A515', 'Acer', 'A515-58M', '32999.00', [[2, '29999.00', '-60 days'], [4, null, '-3 days']]],
                ['Lenovo IdeaPad Slim 3', 'Lenovo', '15IAN8', '27499.00', [[3, null, '-10 days']]],
            ],
            'Printers' => [
                ['Epson EcoTank L3210', 'Epson', 'L3210', '8995.00', [[5, null, '-20 days']]],
                ['Canon PIXMA G3020', 'Canon', 'G3020', '10495.00', [[1, null, '-5 days']]],
            ],
            'Monitors' => [
                ['Samsung 24" FHD Monitor', 'Samsung', 'LS24C310', '6499.00', [[6, null, '-15 days']]],
            ],
            'Ink' => [
                ['Epson 003 Black Ink', 'Epson', 'T00V100', '349.00', [[20, null, '-30 days']]],
            ],
            'Cables' => [
                ['HDMI 2.0 Cable 1.8m', 'Ugreen', 'HD104', '299.00', [[2, null, '-7 days']]],
            ],
        ];

        foreach ($catalog as $categoryName => $items) {
            $category = Category::query()->firstOrCreate(['slug' => Str::slug($categoryName)], ['name' => $categoryName]);

            foreach ($items as [$name, $brand, $model, $price, $batches]) {
                $item = Item::query()->create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'brand' => $brand,
                    'model' => $model,
                    'sku' => strtoupper(Str::slug($model)),
                    'description' => "{$brand} {$model}. Sample item for local testing.",
                    'price' => $price,
                    'low_stock_threshold' => 3,
                    'active' => true,
                ]);

                foreach ($batches as [$quantity, $salePrice, $receivedAgo]) {
                    $stock->addBatch($item, $quantity, $salePrice, now()->modify($receivedAgo));
                }
            }
        }
    }
}
