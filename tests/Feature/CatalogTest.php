<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    public function test_catalog_shows_clearance_current_price_and_low_stock(): void
    {
        $item = Item::factory()->create(['price' => '30000.00', 'low_stock_threshold' => 3]);
        $this->stockBatch($item, 1, '27999.00', '2026-01-01 09:00:00');
        $this->stockBatch($item, 1, null, '2026-06-01 09:00:00');

        $this->get(route('items.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Index')
                ->where('items.data.0.current_price', '27999.00')
                ->where('items.data.0.price', '30000.00')
                ->where('items.data.0.clearance', true)
                ->where('items.data.0.available', 2)
                ->where('items.data.0.low_stock', true));
    }

    public function test_newer_stock_takes_over_at_the_regular_price_when_clearance_runs_out(): void
    {
        $item = Item::factory()->create(['price' => '30000.00']);
        $old = $this->stockBatch($item, 1, '27999.00', '2026-01-01 09:00:00');
        $this->stockBatch($item, 5, null, '2026-06-01 09:00:00');

        $old->forceFill(['quantity_remaining' => 0])->save(); // as if sold

        $this->get(route('items.show', $item->slug))
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Show')
                ->where('item.current_price', '30000.00')
                ->where('item.clearance', false));
    }

    public function test_inactive_and_deleted_items_are_hidden(): void
    {
        $visible = Item::factory()->create();
        $inactive = Item::factory()->inactive()->create();
        $deleted = Item::factory()->create();
        $deleted->delete();

        $this->get(route('items.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $visible->id));

        $this->get(route('items.show', $inactive->slug))->assertForbidden();
        $this->get(route('items.show', $deleted->slug))->assertNotFound();
    }

    public function test_a_sold_out_superseded_item_is_hidden_and_redirects_to_its_replacement(): void
    {
        $new = Item::factory()->create();
        $old = Item::factory()->create(['superseded_by_id' => $new->id]);
        $this->stockBatch($new, 3);

        $this->get(route('items.index'))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $new->id));

        $this->get(route('items.show', $old->slug))->assertRedirect(route('items.show', $new->slug))->assertStatus(301);

        // While the old model still has stock it stays listed and viewable.
        $this->stockBatch($old, 1);
        $this->get(route('items.show', $old->slug))->assertOk();
    }

    public function test_the_catalog_uses_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('items.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (Item::factory()->count(2)->create() as $item) {
            $this->stockBatch($item, 2);
        }
        $few = $count();

        foreach (Item::factory()->count(10)->create() as $item) {
            $this->stockBatch($item, 2);
        }
        $many = $count();

        $this->assertSame($few, $many, 'Catalog queries grow with the number of items (N+1).');
    }

    public function test_search_and_category_filter(): void
    {
        $laptop = Item::factory()->create(['name' => 'Acer Aspire 5']);
        Item::factory()->create(['name' => 'Epson Ink 003']);

        $this->get(route('items.index', ['q' => 'aspire']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $laptop->id));

        $this->get(route('items.index', ['category' => $laptop->category->slug]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1));
    }
}
