import type { NavItem, Role } from '@/types';
import { ClipboardList, LayoutGrid, Settings, Store, Wrench } from 'lucide-vue-next';

export type Area = 'account' | 'staff' | 'admin';

/**
 * Sidebar entries per area. Hiding links is cosmetic; the server enforces roles.
 * Phase 1 adds reservations, pickup counter, Stock Receiving, items and settings.
 */
export function navFor(area: Area): NavItem[] {
    switch (area) {
        case 'account':
            return [
                { title: 'My reservations', href: route('account.home'), icon: ClipboardList },
                { title: 'Browse items', href: route('home'), icon: Store },
            ];
        case 'staff':
            return [{ title: 'Staff dashboard', href: route('staff.home'), icon: LayoutGrid }];
        case 'admin':
            return [
                { title: 'Admin dashboard', href: route('admin.home'), icon: Settings },
                { title: 'Staff dashboard', href: route('staff.home'), icon: Wrench },
            ];
    }
}

export function areaForRole(role: Role): Area {
    return role === 'customer' ? 'account' : role;
}
