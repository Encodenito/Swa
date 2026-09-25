<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page opens with no query string (the way a sidebar link opens it) for a role allowed to see it.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function pages(): array
    {
        return [
            'home' => [null, '/'],
            'catalog' => [null, '/items'],
            'item' => [null, '/items/acer-aspire-5-a515'],
            'my reservations' => ['customer', '/account'],
            'cart' => ['customer', '/account/cart'],
            'staff home' => ['staff', '/staff'],
            'staff reservations' => ['staff', '/staff/reservations'],
            'pickup counter' => ['staff', '/staff/pickup'],
            'stock receiving' => ['staff', '/staff/deliveries'],
            'record delivery' => ['staff', '/staff/deliveries/create'],
            'stock' => ['staff', '/staff/stock'],
            'stock item' => ['staff', '/staff/stock/acer-aspire-5-a515'],
            'suppliers (staff)' => ['staff', '/staff/suppliers'],
            'admin home' => ['admin', '/admin'],
            'admin items' => ['admin', '/admin/items'],
            'new item' => ['admin', '/admin/items/create'],
            'edit item' => ['admin', '/admin/items/acer-aspire-5-a515/edit'],
            'categories' => ['admin', '/admin/categories'],
            'settings' => ['admin', '/admin/settings'],
            'walk-in sale' => ['staff', '/staff/walk-ins/create'],
            'reserve for a customer' => ['staff', '/staff/customer-reservations/create'],
            'payments to review' => ['staff', '/staff/payments/review'],
            'accounts' => ['admin', '/admin/users'],
            'reports' => ['admin', '/admin/reports'],
            'audit log' => ['admin', '/admin/audit-log'],
            'profile' => ['customer', '/settings/profile'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_opens(?string $role, string $uri): void
    {
        $this->seed([SettingsSeeder::class, DemoCatalogSeeder::class]);

        if ($role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        $this->get($uri)->assertOk();
    }
}
