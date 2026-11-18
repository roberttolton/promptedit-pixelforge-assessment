<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Submitting',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const isInProgress = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.status),
);
</script>

<template>
    <Badge
        :variant="
            status === 'failed'
                ? 'destructive'
                : status === 'succeeded'
                  ? 'default'
                  : 'secondary'
        "
    >
        <Spinner v-if="isInProgress" class="size-3" />
        {{ labels[status] }}
    </Badge>
</template>
