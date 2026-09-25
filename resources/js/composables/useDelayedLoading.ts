import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

/**
 * Turns a raw "loading" flag into one that is safe to drive a skeleton:
 * - fast responses (under `delay` ms) never show it, so there is no flash;
 * - once shown it stays at least `minimum` ms, so it never flickers on and off.
 */
export function useDelayedLoading(source: Ref<boolean>, { delay = 150, minimum = 300 } = {}) {
    const visible = ref(false);
    let shownAt = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    watch(source, (loading) => {
        clearTimeout(timer);

        if (loading) {
            timer = setTimeout(() => {
                visible.value = true;
                shownAt = Date.now();
            }, delay);
            return;
        }

        if (visible.value) {
            timer = setTimeout(() => (visible.value = false), Math.max(0, minimum - (Date.now() - shownAt)));
        }
    });

    onBeforeUnmount(() => clearTimeout(timer));

    return visible;
}
