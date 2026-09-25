import type { NavItem, Role } from '@/types';
import {
    Activity,
    CalendarCheck,
    ClipboardCheck,
    ClipboardList,
    Cog,
    Contact,
    FolderTree,
    HelpCircle,
    LayoutGrid,
    Mail,
    MessagesSquare,
    PackageCheck,
    PackageOpen,
    Receipt,
    ScanLine,
    ScrollText,
    ShoppingCart,
    Store,
    Tags,
    TrendingUp,
    Truck,
    UserPlus,
    Users,
    Wrench,
} from 'lucide-vue-next';

export type Area = 'account' | 'staff' | 'admin';

const staffItems = (): NavItem[] => [
    { title: 'Reservations', href: route('staff.reservations.index'), icon: ClipboardList },
    { title: 'Pickup counter', href: route('staff.pickup'), icon: ScanLine },
    { title: 'Walk-in sale', href: route('staff.walk-ins.create'), icon: Receipt },
    { title: 'Reserve for a customer', href: route('staff.customer-reservations.create'), icon: UserPlus },
    { title: 'Payments to review', href: route('staff.payments.review'), icon: ClipboardCheck },
    { title: 'Customers', href: route('staff.customers.index'), icon: Contact },
    { title: 'Follow-ups', href: route('staff.follow-ups.index'), icon: CalendarCheck },
    { title: 'Chat handoffs', href: route('staff.handoffs.index'), icon: MessagesSquare },
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
                { title: 'Accounts', href: route('admin.users.index'), icon: Users },
                { title: 'Reports', href: route('admin.reports'), icon: TrendingUp },
                { title: 'Audit log', href: route('admin.audit-log'), icon: ScrollText },
                { title: 'Messages sent', href: route('admin.messages'), icon: Mail },
                { title: 'Chatbot FAQ', href: route('admin.faqs.index'), icon: HelpCircle },
                { title: 'System health', href: route('admin.system-health'), icon: Activity },
                { title: 'Settings', href: route('admin.settings.edit'), icon: Cog },
            ];
    }
}

export function areaForRole(role: Role): Area {
    return role === 'customer' ? 'account' : role;
}
