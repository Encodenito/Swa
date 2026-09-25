<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <!-- The root keeps any id passed in, so inputs can point aria-describedby at it. -->
    <div v-show="message">
        <!-- Inserted only when there is an error, so screen readers announce it as it appears. -->
        <p v-if="message" role="alert" class="text-sm text-danger">
            {{ message }}
        </p>
    </div>
</template>
