<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/dates';
import { Check, Copy } from 'lucide-vue-next';
import { ref } from 'vue';

/** The PayMongo checkout link staff hand to a customer they reserved for. */
const props = defineProps<{ url: string; expiresAt: string | null }>();

const state = ref<'idle' | 'copied' | 'failed'>('idle');
let timer: ReturnType<typeof setTimeout> | undefined;

function selectLink() {
    const el = document.getElementById('checkout-url') as HTMLInputElement | null;
    el?.focus();
    el?.select();
}

async function copy() {
    clearTimeout(timer);
    try {
        if (!navigator.clipboard) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(props.url);
        state.value = 'copied';
        timer = setTimeout(() => (state.value = 'idle'), 3000);
    } catch {
        state.value = 'failed';
        selectLink();
    }
}
</script>

<template>
    <section aria-labelledby="checkout-heading" class="rounded-lg border border-blue-300 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/40">
        <h3 id="checkout-heading" class="font-semibold">Payment link for the customer</h3>
        <p class="mt-1 text-sm text-muted-foreground">
            Send this link so the customer can pay the downpayment with GCash or card. The reservation is confirmed only after PayMongo tells us the
            payment went through.
        </p>
        <div class="mt-3 grid gap-1.5">
            <Label for="checkout-url">Link</Label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="checkout-url"
                    :model-value="url"
                    readonly
                    class="font-mono text-xs md:text-xs"
                    aria-describedby="checkout-expires checkout-copy-status"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button type="button" variant="outline" class="h-10 shrink-0" @click="copy">
                    <Check v-if="state === 'copied'" class="size-4" aria-hidden="true" />
                    <Copy v-else class="size-4" aria-hidden="true" />
                    {{ state === 'copied' ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <p id="checkout-copy-status" aria-live="polite" class="text-sm">
                <span v-if="state === 'copied'" class="text-emerald-700 dark:text-emerald-400">Link copied.</span>
                <span v-else-if="state === 'failed'" class="text-red-600 dark:text-red-500"
                    >Could not copy automatically. The link is selected; copy it with Ctrl+C or a long press.</span
                >
            </p>
            <p v-if="expiresAt" id="checkout-expires" class="text-sm">
                Works until <span class="font-medium">{{ formatDateTime(expiresAt) }}</span
                >. After that the reservation expires and the stock goes back on sale.
            </p>
        </div>
    </section>
</template>
