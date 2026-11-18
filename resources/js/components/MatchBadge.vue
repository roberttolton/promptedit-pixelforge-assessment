<script setup lang="ts">
defineProps<{
    matches: boolean | null;
}>();
</script>

<template>
    <span
        v-if="matches !== null"
        class="rounded-full px-2 py-0.5 text-xs font-medium"
        :class="
            matches
                ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300'
                : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
        "
        :title="
            matches
                ? 'Our record equals what Pixelforge reports, to the nanodollar.'
                : 'Our record differs from what Pixelforge reports. A generation may be missing or priced differently.'
        "
    >
        {{ matches ? 'Matches' : 'Differs' }}
    </span>
</template>
