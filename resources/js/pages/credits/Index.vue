<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/credits';
import type { CreditPack, CreditTransaction } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const props = defineProps<{
    purchase_id: string;
    packs: CreditPack[];
    transactions: CreditTransaction[];
}>();

const page = usePage();
const credits = computed(() => page.props.credits);

// One id per page load: a double-click or a retry is the same purchase, so it adds credits once.
const form = useForm({ pack: '', purchase_id: props.purchase_id });

watch(
    () => props.purchase_id,
    (id) => (form.purchase_id = id),
);

function buy(pack: string): void {
    form.pack = pack;
    form.post(store.url());
}

const isMounted = ref(false);

onMounted(() => (isMounted.value = true));

function formatDate(value: string): string {
    return isMounted.value ? new Date(value).toLocaleString() : '';
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <Heading
            title="Credits"
            description="Generations are paid for from your credits. Failed generations are free."
        />

        <Card>
            <CardHeader>
                <CardDescription>Your balance</CardDescription>
                <CardTitle
                    class="text-3xl tabular-nums"
                    :class="{ 'text-destructive': credits?.is_negative }"
                    :title="credits?.balance_exact"
                >
                    {{ credits?.balance }}
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-for="pack in packs"
                        :key="pack.value"
                        :disabled="form.processing"
                        @click="buy(pack.value)"
                    >
                        <Spinner
                            v-if="form.processing && form.pack === pack.value"
                        />
                        Buy {{ pack.label }}
                    </Button>
                </div>
                <InputError :message="form.errors.pack" />
                <p class="text-xs text-muted-foreground">
                    This is a demo: no payment is taken, the credits are simply
                    added.
                </p>
            </CardContent>
        </Card>

        <div class="space-y-3">
            <h2 class="text-sm font-medium">Recent activity</h2>

            <p
                v-if="transactions.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing yet. Buy credits to start generating.
            </p>

            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="transaction in transactions"
                    :key="transaction.id"
                    class="flex items-center justify-between gap-4 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{
                                transaction.type === 'purchase'
                                    ? 'Credits added'
                                    : 'Image generated'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDate(transaction.created_at) }}
                        </p>
                    </div>
                    <span
                        class="tabular-nums"
                        :class="
                            transaction.type === 'purchase'
                                ? 'text-green-600'
                                : ''
                        "
                        :title="`Exact: ${transaction.amount_exact}`"
                    >
                        {{ transaction.type === 'purchase' ? '+' : ''
                        }}{{ transaction.amount }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
