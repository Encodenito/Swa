<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ClearanceBadge from '@/components/shop/ClearanceBadge.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import ItemImage from '@/components/shop/customer/ItemImage.vue';
import QuantityStepper from '@/components/shop/customer/QuantityStepper.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import PublicLayout from '@/layouts/PublicLayout.vue';
import type { ItemDetail, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ChevronLeft, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    item: ItemDetail;
    maxQuantity: number;
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const isCustomer = computed(() => user.value?.role === 'customer');

const soldOut = computed(() => props.item.available <= 0);
// Upper bound for the stepper only; the server enforces the real limit.
const maxSelectable = computed(() => Math.max(1, Math.min(props.maxQuantity, props.item.available)));
const subtitle = computed(() => [props.item.brand, props.item.model].filter(Boolean).join(' '));

const form = useForm({
    item_id: props.item.id,
    quantity: 1,
});

function addToCart() {
    if (soldOut.value) return;
    form.post(route('account.cart.add'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="item.name" />

    <PublicLayout>
        <Link
            :href="route('items.index')"
            class="-ml-2 mb-4 inline-flex h-10 items-center gap-1 rounded-md px-2 text-sm font-medium text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <ChevronLeft class="size-4" aria-hidden="true" />
            All items
        </Link>

        <article class="grid gap-6 md:grid-cols-2 md:gap-10">
            <div class="aspect-square overflow-hidden rounded-lg border border-border bg-white p-4 dark:bg-muted md:sticky md:top-20 md:self-start">
                <ItemImage :src="item.image_url" :alt="item.name" eager />
            </div>

            <div class="space-y-6">
                <header class="space-y-2">
                    <p v-if="item.category" class="text-sm text-muted-foreground">{{ item.category }}</p>
                    <h1 class="text-2xl font-bold leading-tight tracking-tight sm:text-3xl">{{ item.name }}</h1>
                    <p v-if="subtitle" class="text-muted-foreground">{{ subtitle }}</p>
                    <p v-if="item.sku" class="text-sm text-muted-foreground">SKU {{ item.sku }}</p>
                </header>

                <div class="space-y-3">
                    <ClearanceBadge v-if="item.clearance" />
                    <div>
                        <PriceTag
                            size="lg"
                            :price="item.current_price"
                            :regular-price="item.clearance ? item.price : null"
                            :clearance="item.clearance"
                        />
                    </div>
                    <p class="text-sm font-medium" :class="soldOut ? 'text-muted-foreground' : item.low_stock ? 'text-warning' : 'text-foreground'">
                        <template v-if="soldOut">Sold out</template>
                        <template v-else-if="item.low_stock">Only {{ item.available }} left</template>
                        <template v-else>In stock, {{ item.available }} available</template>
                    </p>
                </div>

                <div class="rounded-lg border border-border bg-card p-4">
                    <!-- Customers. Hiding this for other roles is cosmetic; the server checks the role. -->
                    <form v-if="isCustomer" class="space-y-4" @submit.prevent="addToCart">
                        <div v-if="!soldOut" class="grid gap-2">
                            <Label for="quantity">Quantity</Label>
                            <QuantityStepper
                                id="quantity"
                                v-model="form.quantity"
                                label="Quantity"
                                :max="maxSelectable"
                                :disabled="form.processing"
                                :invalid="Boolean(form.errors.quantity)"
                                describedby="quantity-hint quantity-error"
                            />
                            <p id="quantity-hint" class="text-xs text-muted-foreground">Up to {{ maxSelectable }} per reservation.</p>
                            <InputError id="quantity-error" :message="form.errors.quantity" />
                            <InputError :message="form.errors.item_id" />
                        </div>
                        <Button type="submit" class="h-11 w-full sm:w-auto sm:px-6" :disabled="soldOut || form.processing">
                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" aria-hidden="true" />
                            {{ soldOut ? 'Sold out' : 'Add to cart' }}
                        </Button>
                        <p v-if="!soldOut" class="text-sm text-muted-foreground">
                            Items are held for you once you pay the downpayment from your cart.
                        </p>
                    </form>

                    <!-- Staff and admin -->
                    <p v-else-if="user" class="text-sm text-muted-foreground">
                        Reserving is for customer accounts. Staff and admin accounts can't reserve items.
                    </p>

                    <!-- Guests -->
                    <div v-else class="space-y-3">
                        <Button v-if="soldOut" type="button" class="h-11 w-full sm:w-auto sm:px-6" disabled>Sold out</Button>
                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="inline-flex h-11 w-full items-center justify-center rounded-md bg-primary px-6 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 sm:w-auto"
                            >
                                Log in to reserve
                            </Link>
                            <p class="text-sm text-muted-foreground">
                                New here?
                                <Link
                                    :href="route('register')"
                                    class="font-medium text-primary underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    Create an account
                                </Link>
                            </p>
                        </template>
                    </div>
                </div>

                <section v-if="item.description" aria-labelledby="description-heading">
                    <h2 id="description-heading" class="mb-2 text-lg font-semibold">Description</h2>
                    <p class="max-w-prose whitespace-pre-line text-muted-foreground">{{ item.description }}</p>
                </section>
            </div>
        </article>
    </PublicLayout>
</template>
