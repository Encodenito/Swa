<script setup lang="ts">
import Chip from '@/components/shop/admin/Chip.vue';
import { computed } from 'vue';
import { tagTone } from './crm';

const props = defineProps<{ name: string; colour: string }>();
const tone = computed(() => tagTone(props.colour));
</script>

<template>
    <Chip :tone="tone">{{ name }}</Chip>
</template>
