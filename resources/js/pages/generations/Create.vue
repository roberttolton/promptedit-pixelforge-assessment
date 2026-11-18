<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationEstimateController from '@/actions/App/Http/Controllers/GenerationEstimateController';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index as creditsIndex } from '@/routes/credits';
import { create } from '@/routes/generations';
import type { Estimate, EstimateResponse, Option } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'New generation', href: create() }],
    },
});

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
}>();

const form = useForm({
    prompt: '',
    size: props.sizes[0].value,
    quality: 'medium',
    reference_images: '',
});

// One URL per line in the textarea, sent as an array.
const referenceImages = computed(() =>
    form.reference_images
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== ''),
);

form.transform((data) => ({
    ...data,
    reference_images: referenceImages.value,
}));

// Not tied to a field (pricing is down), so it arrives only in the shared errors.
const page = usePage();
const pricingError = computed(() => page.props.errors.pricing);
const creditsError = computed(() => page.props.errors.credits);

const referenceImagesError = computed(
    () =>
        Object.entries(form.errors).find(([field]) =>
            field.startsWith('reference_images'),
        )?.[1],
);

const canSubmit = computed(
    () => form.prompt.trim() !== '' && !form.processing && canAfford.value,
);

type EstimatePayload = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

const estimateRequest = useHttp<EstimatePayload, EstimateResponse>({
    prompt: '',
    size: '',
    quality: '',
    reference_images: [],
});

const estimate = ref<Estimate | null>(null);
const available = ref<string | null>(null);
const canAfford = ref(true);
const estimateError = ref<string | null>(null);
const isEstimating = ref(false);
let latestEstimateId = 0;

async function refreshEstimate(): Promise<void> {
    if (form.prompt.trim() === '') {
        latestEstimateId++;
        estimate.value = null;
        canAfford.value = true;
        estimateError.value = null;
        isEstimating.value = false;

        return;
    }

    const estimateId = ++latestEstimateId;

    estimateRequest.prompt = form.prompt;
    estimateRequest.size = form.size;
    estimateRequest.quality = form.quality;
    estimateRequest.reference_images = referenceImages.value;
    isEstimating.value = true;

    try {
        const response = await estimateRequest.post(
            GenerationEstimateController.url(),
        );

        if (estimateId === latestEstimateId) {
            estimate.value = response.estimate;
            available.value = response.available;
            canAfford.value = response.can_afford;
            estimateError.value = null;
        }
    } catch {
        if (estimateId === latestEstimateId) {
            estimate.value = null;
            estimateError.value =
                'An estimate is not available right now. Check the prompt and image URLs, or try again in a moment.';
        }
    } finally {
        if (estimateId === latestEstimateId) {
            isEstimating.value = false;
        }
    }
}

watchDebounced(
    () => [
        form.prompt,
        form.size,
        form.quality,
        referenceImages.value.join('\n'),
    ],
    refreshEstimate,
    { debounce: 400 },
);

function submit(): void {
    form.post(GenerationController.store.url());
}
</script>

<template>
    <Head title="New generation" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <Heading
            title="New generation"
            description="Describe the image you want. You will see an estimated price before anything is submitted."
        />

        <div class="grid gap-6 lg:grid-cols-3">
            <form class="space-y-6 lg:col-span-2" @submit.prevent="submit">
                <AlertError
                    v-if="pricingError"
                    :errors="[pricingError]"
                    title="Could not submit"
                />
                <AlertError
                    v-if="creditsError"
                    :errors="[creditsError]"
                    title="Not enough credits"
                />

                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <Textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="5"
                        :maxlength="4000"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        required
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="size">Size</Label>
                        <Select v-model="form.size">
                            <SelectTrigger id="size" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in sizes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="quality">Quality</Label>
                        <Select v-model="form.quality">
                            <SelectTrigger id="quality" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in qualities"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.quality" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="reference_images">
                        Reference image URLs (optional)
                    </Label>
                    <Textarea
                        id="reference_images"
                        v-model="form.reference_images"
                        name="reference_images"
                        rows="3"
                        placeholder="One http(s) URL per line, up to 4"
                    />
                    <p class="text-xs text-muted-foreground">
                        Each reference image adds to the price.
                    </p>
                    <InputError :message="referenceImagesError" />
                </div>

                <Button type="submit" :disabled="!canSubmit">
                    <Spinner v-if="form.processing" />
                    Generate image
                </Button>
            </form>

            <Card class="h-fit">
                <CardHeader>
                    <CardTitle>Estimated cost</CardTitle>
                    <CardDescription>
                        Updates as you change the options.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4" aria-live="polite">
                    <div
                        v-if="isEstimating && !estimate"
                        class="animate-pulse space-y-2"
                    >
                        <div class="h-8 w-28 rounded bg-muted" />
                        <div class="h-4 w-40 rounded bg-muted" />
                    </div>

                    <div v-else-if="estimate" class="space-y-1">
                        <p
                            class="text-3xl font-semibold tabular-nums"
                            :class="{ 'opacity-60': isEstimating }"
                        >
                            {{ estimate.expected }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Likely between {{ estimate.min }} and
                            {{ estimate.max }}
                        </p>
                    </div>

                    <p
                        v-else-if="estimateError"
                        class="text-sm text-destructive"
                    >
                        {{ estimateError }}
                    </p>

                    <p v-else class="text-sm text-muted-foreground">
                        Enter a prompt to see an estimate.
                    </p>

                    <div class="space-y-1 border-t pt-4 text-sm">
                        <p class="flex justify-between">
                            <span class="text-muted-foreground">
                                Available credits
                            </span>
                            <span
                                class="font-medium tabular-nums"
                                :title="page.props.credits?.balance_exact"
                            >
                                {{ available ?? page.props.credits?.balance }}
                            </span>
                        </p>
                        <p v-if="!canAfford" class="text-destructive">
                            Not enough credits to cover the most this could
                            cost.
                            <Link
                                :href="creditsIndex()"
                                class="font-medium underline"
                            >
                                Buy credits
                            </Link>
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            You need enough to cover the maximum shown. You are
                            only charged what it actually costs.
                        </p>
                    </div>

                    <ul
                        class="list-inside list-disc space-y-1 text-xs text-muted-foreground"
                    >
                        <li>
                            The final price is worked out from what the
                            generation actually used, once it finishes.
                        </li>
                        <li>Failed generations are never charged.</li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
