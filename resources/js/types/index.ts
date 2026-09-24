import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    auth: Auth;
    flash: { success: string | null; error: string | null };
    cartCount: number;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type Role = 'customer' | 'staff' | 'admin';

/** Matches the fields shared by HandleInertiaRequests; nothing else is sent. */
export interface User {
    id: number;
    name: string;
    email: string;
    role: Role;
    avatar?: string;
    email_verified_at: string | null;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type ReservationStatus = 'pending_payment' | 'confirmed' | 'processing' | 'completed' | 'cancelled' | 'expired' | 'forfeited' | 'voided';

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Laravel LengthAwarePaginator as serialized by Inertia. */
export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/** App\Http\Presenters\ItemPresenter::card(). Money values are decimal strings like "1234.50". */
export interface ItemCard {
    id: number;
    slug: string;
    name: string;
    brand: string | null;
    model: string | null;
    category: string | null;
    image_url: string | null;
    price: string;
    current_price: string;
    clearance: boolean;
    available: number;
    low_stock: boolean;
}

export interface ItemDetail extends ItemCard {
    sku: string | null;
    description: string | null;
}

/** App\Http\Presenters\ReservationPresenter::summary(). */
export interface ReservationSummary {
    reference_code: string;
    type: 'reservation' | 'walk_in';
    status: ReservationStatus;
    status_label: string;
    total: string;
    downpayment_amount: string;
    balance: string;
    expires_at: string | null;
    pickup_by: string | null;
    created_at: string | null;
    item_count: number | null;
    customer: { name: string; email: string; phone: string | null } | null;
    walk_in_name: string | null;
}

export interface ReservationLine {
    id: number;
    item_name: string | null;
    item_slug: string | null;
    quantity: number;
    unit_price: string;
    clearance: boolean;
    line_total: string;
}

export interface PaymentRow {
    id: number;
    parent_id: number | null;
    type: 'downpayment' | 'balance' | 'full' | 'refund';
    amount: string;
    method: 'paymongo' | 'cash';
    status: 'paid' | 'needs_review' | 'refund_pending' | 'refunded' | 'refund_failed';
    created_at: string | null;
    provider_ref: string | null;
    note: string | null;
    reviewed_at: string | null;
}

/** App\Http\Presenters\ReservationPresenter::detail(). */
export interface ReservationDetail extends ReservationSummary {
    confirmed_at: string | null;
    processing_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    forfeited_at: string | null;
    walk_in_phone: string | null;
    can_pay: boolean;
    /** Staff only, while pending_payment: the PayMongo link to hand to the customer. */
    checkout_url: string | null;
    /** What the viewer may do. Display only; the server re-checks. */
    cancel: { allowed: boolean; percents: number[]; reason_required: boolean };
    can_void: boolean;
    lines: ReservationLine[];
    payments: PaymentRow[];
}
