<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/shop/Pagination.vue';
import EmptyState from '@/components/shop/customer/EmptyState.vue';
import ItemCardView from '@/components/shop/customer/ItemCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/layouts/PublicLayout.vue';
import type { ItemCard, Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { LoaderCircle, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    items: Paginated<ItemCard>;
    categories: { name: string; slug: string }[];
    filters: { q: string | null; category: string | null };
}>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const q = ref(props.filters.q ?? '');
const loading = ref(false);

const activeCategory = computed(() => props.categories.find((c) => c.slug === props.filters.category) ?? null);
const hasFilters = computed(() => Boolean(props.filters.q) || Boolean(props.filters.category));

function search(category: string | null = props.filters.category) {
    const query: Record<string, string> = {};
    if (q.value.trim() !== '') query.q = q.value.trim();
    if (category) query.category = category;

    router.get(route('items.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

function clearFilters() {
    q.value = '';
    search(null);
}

const resultLine = computed(() => {
    const total = props.items.total;
    const parts = [`${total} ${total === 1 ? 'item' : 'items'}`];
    if (activeCategory.value) parts.push(`in ${activeCategory.value.name}`);
    if (props.filters.q) parts.push(`matching "${props.filters.q}"`);

    return parts.join(' ');
});

const chipClass =
    'inline-flex h-10 shrink-0 items-center whitespace-nowrap rounded-full border px-4 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-60';
</script>

<template>
    <Head title="Items" />

    <PublicLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Items</h1>
                <p class="mt-1 text-muted-foreground">Reserve online with a downpayment and pick up at the counter.</p>
            </div>

            <form role="search" class="grid gap-2" @submit.prevent="search()">
                <Label for="catalog-search">Search by name, brand or model</Label>
                <div class="flex gap-2">
                    <Input
                        id="catalog-search"
                        v-model="q"
                        type="search"
                        maxlength="100"
                        placeholder="e.g. Epson L3210"
                        autocomplete="off"
                        enterkeyhint="search"
                        class="h-11 min-w-0 flex-1"
                        :aria-invalid="Boolean(errors.q) || undefined"
                        aria-describedby="catalog-search-error"
                    />
                    <Button type="submit" class="h-11 shrink-0 px-4" :disabled="loading">
                        <LoaderCircle v-if="loading" class="size-4 animate-spin" aria-hidden="true" />
                        <Search v-else class="size-4" aria-hidden="true" />
                        Search
                    </Button>
                </div>
                <InputError id="catalog-search-error" :message="errors.q" />
            </form>

            <div v-if="categories.length > 0">
                <p id="category-filter-label" class="mb-2 text-sm font-medium">Category</p>
                <!-- Scrolls sideways on phones instead of wrapping into a tall block. -->
                <div
                    role="group"
                    aria-labelledby="category-filter-label"
                    class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0"
                >
                    <button
                        type="button"
                        :aria-pressed="!filters.category"
                        :disabled="loading"
                        :class="[
                            chipClass,
                            !filters.category ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-accent',
                        ]"
                        @click="search(null)"
                    >
                        All
                    </button>
                    <button
                        v-for="category in categories"
                        :key="category.slug"
                        type="button"
                        :aria-pressed="filters.category === category.slug"
                        :disabled="loading"
                        :class="[
                            chipClass,
                            filters.category === category.slug
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:bg-accent',
                        ]"
                        @click="search(category.slug)"
                    >
                        {{ category.name }}
                    </button>
                </div>
                <InputError class="mt-2" :message="errors.category" />
            </div>

            <section :aria-busy="loading" aria-labelledby="results-heading">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="results-heading" class="text-sm text-muted-foreground" aria-live="polite">
                        {{ loading ? 'Loading items…' : resultLine }}
                    </h2>
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="h-10 rounded-md px-2 text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :disabled="loading"
                        @click="clearFilters"
                    >
                        Clear filters
                    </button>
                </div>

                <EmptyState
                    v-if="items.data.length === 0"
                    :title="hasFilters ? 'No items match your search' : 'No items listed yet'"
                    :description="
                        hasFilters ? 'Try a shorter search, check the spelling, or pick another category.' : 'Check back soon, or visit the shop.'
                    "
                >
                    <Button v-if="hasFilters" type="button" variant="outline" class="h-11" :disabled="loading" @click="clearFilters"
                        >Show all items</Button
                    >
                    <Link
                        v-else
                        :href="route('home')"
                        class="inline-flex h-11 items-center rounded-md border border-border bg-card px-5 font-semibold hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        Back to home
                    </Link>
                </EmptyState>

                <ul
                    v-else
                    class="grid grid-cols-1 gap-4 transition-opacity sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                    :class="{ 'pointer-events-none opacity-60': loading }"
                >
                    <li v-for="item in items.data" :key="item.id">
                        <ItemCardView :item="item" />
                    </li>
                </ul>

                <Pagination :links="items.links" />
            </section>
        </div>
    </PublicLayout>
</template>
