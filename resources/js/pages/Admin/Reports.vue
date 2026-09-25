<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LoadingRegion from '@/components/shop/skeleton/LoadingRegion.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface SalesRow {
    date: string;
    reservations: string;
    walk_ins: string;
    total: string;
    count: number;
}

interface ForfeitedRow {
    reference_code: string;
    forfeited_at: string | null;
    paid: string;
    refunded: string;
    kept: string;
}

interface SlowItem {
    name: string;
    slug: string;
    sku: string | null;
    available: number;
    last_sale_at: string | null;
    days_since_sale: number | null;
}

const props = defineProps<{
    filters: { from: string; to: string; days: number };
    sales: { rows: SalesRow[]; total: string };
    forfeited: { rows: ForfeitedRow[]; kept_total: string };
    slowMoving: SlowItem[];
}>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Reports', href: route('admin.reports') },
];

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const days = ref<number | string>(props.filters.days);
const loading = ref(false);

function apply() {
    const params: Record<string, string> = {};
    if (from.value) params.from = from.value;
    if (to.value) params.to = to.value;
    if (days.value !== '' && days.value !== null) params.days = String(days.value);

    router.get(route('admin.reports'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

// Bar width only (display): each day's server total relative to the busiest day. Never summed or shown as money.
const maxDay = computed(() => Math.max(0, ...props.sales.rows.map((r) => Number(r.total) || 0)));
const barWidth = (total: string) => (maxDay.value > 0 ? `${Math.max(2, ((Number(total) || 0) / maxDay.value) * 100)}%` : '0%');

const salesLabel = (n: number) => (n === 1 ? '1 sale' : `${n} sales`);

// last_sale_at is a shop-time "Y-m-d H:i:s" string from the database; mark it as Manila time before formatting.
function shopTime(value: string): string {
    return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? `${value.replace(' ', 'T')}+08:00` : value;
}

function sinceLabel(item: SlowItem): string {
    if (item.days_since_sale === null) return 'Never sold';
    if (item.days_since_sale === 0) return 'Today';

    return item.days_since_sale === 1 ? '1 day ago' : `${item.days_since_sale} days ago`;
}

const periodLabel = computed(() => `${formatDate(props.filters.from)} to ${formatDate(props.filters.to)}`);
</script>

<template>
    <Head title="Reports" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 px-4 py-6">
            <Heading
                title="Reports"
                description="Completed sales, kept downpayments and items that are not moving. All amounts come from the server."
            />

            <form class="grid gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end" @submit.prevent="apply">
                <div class="grid gap-1.5">
                    <Label for="report-from">From</Label>
                    <Input
                        id="report-from"
                        v-model="from"
                        type="date"
                        :max="to || undefined"
                        :aria-invalid="!!errors.from"
                        aria-describedby="report-from-error"
                    />
                    <InputError id="report-from-error" :message="errors.from" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="report-to">To</Label>
                    <Input
                        id="report-to"
                        v-model="to"
                        type="date"
                        :min="from || undefined"
                        :aria-invalid="!!errors.to"
                        aria-describedby="report-to-error"
                    />
                    <InputError id="report-to-error" :message="errors.to" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="report-days">No sale for (days)</Label>
                    <Input
                        id="report-days"
                        v-model="days"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="365"
                        step="1"
                        :aria-invalid="!!errors.days"
                        aria-describedby="report-days-error"
                    />
                    <InputError id="report-days-error" :message="errors.days" />
                </div>
                <Button type="submit" class="h-10" :disabled="loading">{{ loading ? 'Loading…' : 'Show report' }}</Button>
            </form>

            <LoadingRegion :loading="loading" class="space-y-10">
                <!-- Sales per day -->
                <section aria-labelledby="sales-heading" class="space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 id="sales-heading" class="text-base font-semibold">Sales per day</h3>
                            <p class="text-sm text-muted-foreground">
                                Picked-up reservations and walk-ins, {{ periodLabel }}. Voided sales are left out.
                            </p>
                        </div>
                        <p v-if="sales.rows.length" class="text-right">
                            <span class="block text-xs text-muted-foreground">Total</span>
                            <span class="text-lg font-semibold tabular-nums">{{ formatPeso(sales.total) }}</span>
                        </p>
                    </div>

                    <EmptyState v-if="sales.rows.length === 0" title="No completed sales in this period" description="Try a wider date range." />

                    <template v-else>
                        <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th scope="col" class="px-4 py-2.5 font-medium">Date</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Reservations</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Walk-ins</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Sales</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="row in sales.rows" :key="row.date">
                                        <td class="whitespace-nowrap px-4 py-3">{{ formatDate(row.date) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ formatPeso(row.reservations) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ formatPeso(row.walk_ins) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ row.count }}</td>
                                        <td class="w-64 px-4 py-3">
                                            <span class="flex items-center justify-end gap-3">
                                                <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                                    <span class="block h-full rounded-full bg-primary/70" :style="{ width: barWidth(row.total) }" />
                                                </span>
                                                <span class="font-semibold tabular-nums">{{ formatPeso(row.total) }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-border bg-muted/30">
                                    <tr>
                                        <th scope="row" colspan="4" class="px-4 py-3 text-left font-semibold">Total for the period</th>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatPeso(sales.total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                            <li v-for="row in sales.rows" :key="row.date" class="space-y-2 p-4">
                                <p class="flex items-baseline justify-between gap-2">
                                    <span class="font-medium">{{ formatDate(row.date) }}</span>
                                    <span class="font-semibold tabular-nums">{{ formatPeso(row.total) }}</span>
                                </p>
                                <span class="block h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                    <span class="block h-full rounded-full bg-primary/70" :style="{ width: barWidth(row.total) }" />
                                </span>
                                <dl class="grid grid-cols-3 gap-2 text-sm">
                                    <div>
                                        <dt class="text-muted-foreground">Reservations</dt>
                                        <dd class="tabular-nums">{{ formatPeso(row.reservations) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Walk-ins</dt>
                                        <dd class="tabular-nums">{{ formatPeso(row.walk_ins) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Count</dt>
                                        <dd class="tabular-nums">{{ salesLabel(row.count) }}</dd>
                                    </div>
                                </dl>
                            </li>
                            <li class="flex items-baseline justify-between gap-2 bg-muted/30 p-4">
                                <span class="font-semibold">Total for the period</span>
                                <span class="font-semibold tabular-nums">{{ formatPeso(sales.total) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Forfeited downpayments -->
                <section aria-labelledby="forfeited-heading" class="space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 id="forfeited-heading" class="text-base font-semibold">Forfeited downpayments kept</h3>
                            <p class="text-sm text-muted-foreground">Reservations forfeited {{ periodLabel }}, less any refunds.</p>
                        </div>
                        <p v-if="forfeited.rows.length" class="text-right">
                            <span class="block text-xs text-muted-foreground">Kept</span>
                            <span class="text-lg font-semibold tabular-nums">{{ formatPeso(forfeited.kept_total) }}</span>
                        </p>
                    </div>

                    <EmptyState v-if="forfeited.rows.length === 0" title="No forfeited reservations in this period" />

                    <template v-else>
                        <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th scope="col" class="px-4 py-2.5 font-medium">Reference</th>
                                        <th scope="col" class="px-4 py-2.5 font-medium">Forfeited</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Paid</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Refunded</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Kept</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="row in forfeited.rows" :key="row.reference_code">
                                        <td class="px-4 py-3">
                                            <Link
                                                :href="route('staff.reservations.show', { reservation: row.reference_code })"
                                                class="font-mono font-semibold underline-offset-4 hover:underline"
                                                >{{ row.reference_code }}</Link
                                            >
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ formatDateTime(row.forfeited_at) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ formatPeso(row.paid) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ formatPeso(row.refunded) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatPeso(row.kept) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-border bg-muted/30">
                                    <tr>
                                        <th scope="row" colspan="4" class="px-4 py-3 text-left font-semibold">Total kept</th>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatPeso(forfeited.kept_total) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                            <li v-for="row in forfeited.rows" :key="row.reference_code" class="space-y-2 p-4">
                                <p class="flex items-baseline justify-between gap-2">
                                    <Link
                                        :href="route('staff.reservations.show', { reservation: row.reference_code })"
                                        class="font-mono font-semibold underline underline-offset-4"
                                        >{{ row.reference_code }}</Link
                                    >
                                    <span class="font-semibold tabular-nums">{{ formatPeso(row.kept) }} kept</span>
                                </p>
                                <p class="text-sm text-muted-foreground">Forfeited {{ formatDateTime(row.forfeited_at) }}</p>
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <dt class="text-muted-foreground">Paid</dt>
                                        <dd class="tabular-nums">{{ formatPeso(row.paid) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">Refunded</dt>
                                        <dd class="tabular-nums">{{ formatPeso(row.refunded) }}</dd>
                                    </div>
                                </dl>
                            </li>
                            <li class="flex items-baseline justify-between gap-2 bg-muted/30 p-4">
                                <span class="font-semibold">Total kept</span>
                                <span class="font-semibold tabular-nums">{{ formatPeso(forfeited.kept_total) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Slow-moving items -->
                <section aria-labelledby="slow-heading" class="space-y-3">
                    <div>
                        <h3 id="slow-heading" class="text-base font-semibold">Slow-moving items</h3>
                        <p class="text-sm text-muted-foreground">
                            In stock, but not reserved or sold in the last {{ filters.days }} {{ filters.days === 1 ? 'day' : 'days' }}. Consider a
                            clearance price.
                        </p>
                    </div>

                    <EmptyState
                        v-if="slowMoving.length === 0"
                        title="Every stocked item is moving"
                        description="Nothing in stock has gone this long without a sale."
                    />

                    <template v-else>
                        <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                            <table class="w-full text-sm">
                                <thead class="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th scope="col" class="px-4 py-2.5 font-medium">Item</th>
                                        <th scope="col" class="px-4 py-2.5 text-right font-medium">Available</th>
                                        <th scope="col" class="px-4 py-2.5 font-medium">Last sale</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="item in slowMoving" :key="item.slug">
                                        <td class="px-4 py-3">
                                            <Link
                                                :href="route('staff.stock.show', { item: item.slug })"
                                                class="font-medium underline-offset-4 hover:underline"
                                                >{{ item.name }}</Link
                                            >
                                            <span v-if="item.sku" class="block font-mono text-xs text-muted-foreground">{{ item.sku }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ item.available }}</td>
                                        <td class="px-4 py-3">
                                            <span :class="['block', item.last_sale_at ? '' : 'font-medium text-warning']">{{
                                                sinceLabel(item)
                                            }}</span>
                                            <span v-if="item.last_sale_at" class="block text-muted-foreground">{{
                                                formatDateTime(shopTime(item.last_sale_at))
                                            }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                            <li v-for="item in slowMoving" :key="item.slug">
                                <Link :href="route('staff.stock.show', { item: item.slug })" class="block space-y-1 p-4 hover:bg-accent/50">
                                    <span class="block font-medium">{{ item.name }}</span>
                                    <span v-if="item.sku" class="block font-mono text-xs text-muted-foreground">{{ item.sku }}</span>
                                    <span class="flex items-baseline justify-between gap-2 text-sm">
                                        <span :class="item.last_sale_at ? 'text-muted-foreground' : 'font-medium text-warning'">{{
                                            sinceLabel(item)
                                        }}</span>
                                        <span class="tabular-nums">{{ item.available }} available</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </template>
                </section>
            </LoadingRegion>
        </div>
    </AdminLayout>
</template>
