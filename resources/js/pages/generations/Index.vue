<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ImageOff, ImagePlus } from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index as creditsIndex } from '@/routes/credits';
import { create } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: dashboard() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
}>();

// Refresh only while something is still moving, so an idle page makes no requests.
const hasActiveGeneration = computed(() =>
    props.generations.data.some((generation) => generation.is_active),
);

const page = usePage();

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'credits'] },
    { autoStart: false },
);

watch(hasActiveGeneration, (isActive) => (isActive ? start() : stop()), {
    immediate: true,
});

// An image that fails to load is shown as unavailable, e.g. if the file was removed.
const brokenImages = reactive(new Set<string>());

// Server and browser differ in locale/timezone, so format only after mounting to avoid a hydration mismatch.
const isMounted = ref(false);

onMounted(() => (isMounted.value = true));

function formatDate(value: string): string {
    return isMounted.value ? new Date(value).toLocaleString() : '';
}
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, their status and what each one cost."
            />
            <div class="flex shrink-0 items-center gap-3">
                <Link
                    :href="creditsIndex()"
                    class="text-right text-sm"
                    title="Your credit balance. Click to buy more."
                >
                    <span class="block text-xs text-muted-foreground">
                        Credits
                    </span>
                    <span
                        class="font-medium tabular-nums"
                        :class="{
                            'text-destructive': page.props.credits?.is_negative,
                        }"
                        :title="page.props.credits?.balance_exact"
                    >
                        {{ page.props.credits?.balance }}
                    </span>
                </Link>
                <Button as-child>
                    <Link :href="create()">
                        <ImagePlus />
                        New generation
                    </Link>
                </Button>
            </div>
        </div>

        <Card
            v-if="generations.data.length === 0"
            class="items-center gap-3 px-6 py-12 text-center"
        >
            <p class="font-medium">No generations yet</p>
            <p class="text-sm text-muted-foreground">
                Create your first image to see it here.
            </p>
        </Card>

        <ul v-else class="flex flex-col gap-4">
            <li v-for="generation in generations.data" :key="generation.id">
                <Card class="flex-row gap-4 p-4">
                    <div
                        class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted"
                        :class="{ 'animate-pulse': generation.is_active }"
                    >
                        <img
                            v-if="
                                generation.image_url &&
                                !brokenImages.has(generation.id)
                            "
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="size-full object-cover"
                            @error="brokenImages.add(generation.id)"
                        />
                        <Spinner
                            v-else-if="generation.is_active"
                            class="size-6 text-muted-foreground"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center gap-1 px-2 text-center text-xs text-muted-foreground"
                        >
                            <ImageOff class="size-5" />
                            {{
                                generation.status === 'failed'
                                    ? 'No image'
                                    : 'Image unavailable'
                            }}
                        </div>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <GenerationStatusBadge
                                :status="generation.status"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ generation.size }} ·
                                {{ generation.quality }} ·
                                {{ formatDate(generation.created_at) }}
                            </span>
                        </div>

                        <p class="line-clamp-2 text-sm">
                            {{ generation.prompt }}
                        </p>

                        <p
                            v-if="generation.status === 'failed'"
                            class="text-sm text-destructive"
                        >
                            {{
                                generation.error_message ??
                                'The generation failed.'
                            }}
                            <span
                                v-if="
                                    !/charged/i.test(
                                        generation.error_message ?? '',
                                    )
                                "
                                class="text-muted-foreground"
                            >
                                You were not charged.
                            </span>
                        </p>

                        <p
                            v-else-if="generation.charged_cost"
                            class="text-sm tabular-nums"
                        >
                            <span
                                class="font-medium"
                                :title="`Exact: ${generation.charged_cost_exact}`"
                            >
                                Charged {{ generation.charged_cost }}
                            </span>
                            <span class="text-muted-foreground">
                                · estimated {{ generation.estimated_cost }}
                            </span>
                        </p>

                        <p
                            v-else
                            class="text-sm text-muted-foreground tabular-nums"
                        >
                            Estimated {{ generation.estimated_cost }} (up to
                            {{ generation.estimated_cost_max }}). The final
                            price is set when it finishes.
                        </p>
                    </div>
                </Card>
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Button v-if="generations.links.prev" variant="outline" as-child>
                <Link :href="generations.links.prev" preserve-scroll>
                    Newer
                </Link>
            </Button>
            <span v-else />

            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>

            <Button v-if="generations.links.next" variant="outline" as-child>
                <Link :href="generations.links.next" preserve-scroll>
                    Older
                </Link>
            </Button>
            <span v-else />
        </nav>
    </div>
</template>
