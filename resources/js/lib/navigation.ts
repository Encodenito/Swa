import type { NavItem, Role } from '@/types';
import {
    ClipboardList,
    Cog,
    FolderTree,
    LayoutGrid,
    PackageCheck,
    PackageOpen,
    ScanLine,
    ShoppingCart,
    Store,
    Tags,
    Truck,
    Wrench,
} from 'lucide-vue-next';

export type Area = 'account' | 'staff' | 'admin';

const staffItems = (): NavItem[] => [
    { title: 'Reservations', href: route('staff.reservations.index'), icon: ClipboardList },
    { title: 'Pickup counter', href: route('staff.pickup'), icon: ScanLine },
    { title: 'Stock Receiving', href: route('staff.deliveries.index'), icon: Truck },
    { title: 'Stock', href: route('staff.stock.index'), icon: PackageOpen },
];

/**
 * Sidebar entries per area. Hiding links is cosmetic; the server enforces roles.
 */
export function navFor(area: Area): NavItem[] {
    switch (area) {
        case 'account':
            return [
                { title: 'My reservations', href: route('account.home'), icon: ClipboardList },
                { title: 'Cart', href: route('account.cart'), icon: ShoppingCart },
                { title: 'Browse items', href: route('items.index'), icon: Store },
            ];
        case 'staff':
            return [{ title: 'Staff dashboard', href: route('staff.home'), icon: LayoutGrid }, ...staffItems()];
        case 'admin':
            return [
                { title: 'Admin dashboard', href: route('admin.home'), icon: Wrench },
                ...staffItems(),
                { title: 'Items', href: route('admin.items.index'), icon: PackageCheck },
                { title: 'Categories', href: route('admin.categories.index'), icon: FolderTree },
                { title: 'Suppliers', href: route('staff.suppliers.index'), icon: Tags },
                { title: 'Settings', href: route('admin.settings.edit'), icon: Cog },
            ];
    }
}

export function areaForRole(role: Role): Area {
    return role === 'customer' ? 'account' : role;
}
