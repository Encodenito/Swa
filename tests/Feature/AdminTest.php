<?php

namespace Tests\Feature;

use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_updates_settings_within_the_allowed_ranges(): void
    {
        $valid = [
            'downpayment_percent' => 40,
            'pickup_days' => 5,
            'forfeit_refund_percent' => 50,
            'pending_minutes' => 20,
            'max_unpaid_reservations' => 3,
            'max_qty_per_item' => 5,
        ];

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $valid)->assertSessionHasNoErrors();
        $this->assertSame(40, setting(SettingKey::DownpaymentPercent));
        $this->assertSame(5, setting(SettingKey::PickupDays));

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), ['downpayment_percent' => 0] + $valid)
            ->assertSessionHasErrors('downpayment_percent');
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), ['forfeit_refund_percent' => 101] + $valid)
            ->assertSessionHasErrors('forfeit_refund_percent');

        $this->assertSame(40, setting(SettingKey::DownpaymentPercent));
    }

    public function test_admin_creates_an_item_with_an_image(): void
    {
        Storage::fake('public');
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.items.store'), [
            'category_id' => $category->id,
            'name' => 'Acer Aspire 5',
            'price' => '32999.50',
            'low_stock_threshold' => 2,
            'active' => '1',
            // A real 1x1 PNG (XAMPP has no GD, so fake()->image() is unavailable).
            'image' => UploadedFile::fake()->createWithContent('aspire.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')),
        ])->assertRedirect(route('admin.items.index'));

        $item = Item::sole();
        $this->assertSame('acer-aspire-5', $item->slug);
        $this->assertSame('32999.50', $item->price);
        Storage::disk('public')->assertExists($item->image);
    }

    public function test_item_price_must_be_a_positive_peso_amount(): void
    {
        $category = Category::factory()->create();

        foreach (['0', '-5', '12.345', 'abc'] as $price) {
            $this->actingAs($this->admin)->post(route('admin.items.store'), [
                'category_id' => $category->id, 'name' => 'X', 'price' => $price, 'low_stock_threshold' => 1,
            ])->assertSessionHasErrors('price');
        }
    }

    public function test_removing_an_item_soft_deletes_it(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)->delete(route('admin.items.destroy', $item->slug))->assertRedirect();

        $this->assertSoftDeleted($item);
        $this->assertFalse(Item::withTrashed()->find($item->id)->active);
    }

    public function test_an_item_cannot_replace_itself(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.items.update', $item->slug), [
            'category_id' => $item->category_id, 'name' => $item->name, 'price' => '100', 'low_stock_threshold' => 1,
            'superseded_by_id' => $item->id,
        ])->assertSessionHasErrors('superseded_by_id');
    }

    public function test_a_category_with_items_cannot_be_deleted(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $item->category))->assertSessionHas('error');

        $this->assertModelExists($item->category);
    }
}
