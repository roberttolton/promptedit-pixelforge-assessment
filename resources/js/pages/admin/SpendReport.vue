<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import MatchBadge from '@/components/MatchBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { spend } from '@/routes/admin';
import type { SpendDayRow, SpendFigures } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Spend report', href: spend() }],
    },
});

const props = defineProps<{
    start_date: string;
    end_date: string;
    pixelforge_available: boolean;
    totals: SpendFigures;
    days: SpendDayRow[];
}>();

const startDate = ref(props.start_date);
const endDate = ref(props.end_date);

function applyRange() {
    router.get(spend(), {
        start_date: startDate.value,
        end_date: endDate.value,
    });
}
</script>

<template>
    <Head title="Spend report" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <Heading
            title="Spend report"
            description="Per UTC day a generation completed: what users were charged, what Pixelforge billed, and the profit. Failed generations are free and not included."
        />

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyRange"
        >
            <div class="grid gap-1">
                <Label for="start_date">From</Label>
                <Input id="start_date" v-model="startDate" type="date" />
            </div>
            <div class="grid gap-1">
                <Label for="end_date">To</Label>
                <Input id="end_date" v-model="endDate" type="date" />
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <p v-if="!pixelforge_available" class="text-sm text-muted-foreground">
            Pixelforge usage is unavailable right now, so provider costs are not
            checked against GET /v1/usage.
        </p>

        <dl class="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="font-medium">Users paid</dt>
                <dd class="text-muted-foreground">
                    What users were charged: Pixelforge's cost plus our markup.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Provider cost</dt>
                <dd class="text-muted-foreground">
                    What we calculated Pixelforge billed us, from each
                    generation's token usage and rates.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Profit</dt>
                <dd class="text-muted-foreground">
                    Users paid minus provider cost.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Pixelforge reports (/v1/usage)</dt>
                <dd class="text-muted-foreground">
                    What Pixelforge itself says it billed this API key for that
                    day. It should equal our provider cost exactly.
                </dd>
            </div>
        </dl>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm tabular-nums">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3 font-medium">Day (UTC) / user</th>
                        <th
                            class="p-3 text-right font-medium"
                            title="Succeeded generations completed that day. Failed ones are free and not counted."
                        >
                            Generations
                        </th>
                        <th
                            class="p-3 text-right font-medium"
                            title="What users were charged: Pixelforge's cost plus the markup."
                        >
                            Users paid
                        </th>
                        <th
                            class="p-3 text-right font-medium"
                            title="The cost we recorded for each generation from its token usage and rates."
                        >
                            Provider cost
                        </th>
                        <th
                            class="p-3 text-right font-medium"
                            title="Users paid minus provider cost."
                        >
                            Profit
                        </th>
                        <th
                            v-if="pixelforge_available"
                            class="p-3 text-right font-medium"
                            title="What Pixelforge reports for this API key and day, from GET /v1/usage."
                        >
                            Pixelforge reports
                        </th>
                        <th
                            v-if="pixelforge_available"
                            class="p-3 text-right font-medium"
                            title="Whether our provider cost equals what Pixelforge reports, to the nanodollar."
                        >
                            Check
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="days.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="7">
                            No completed generations in this range.
                        </td>
                    </tr>

                    <template v-for="day in days" :key="day.date">
                        <tr class="border-t bg-muted/30 font-medium">
                            <td class="p-3">{{ day.date }}</td>
                            <td class="p-3 text-right">
                                {{ day.generations }}
                            </td>
                            <td
                                class="p-3 text-right"
                                :title="day.charged_exact"
                            >
                                {{ day.charged }}
                            </td>
                            <td
                                class="p-3 text-right"
                                :title="day.provider_exact"
                            >
                                {{ day.provider }}
                            </td>
                            <td
                                class="p-3 text-right"
                                :title="day.profit_exact"
                            >
                                {{ day.profit }}
                            </td>
                            <td
                                v-if="pixelforge_available"
                                class="p-3 text-right"
                                :title="day.pixelforge_exact ?? ''"
                            >
                                {{ day.pixelforge }}
                            </td>
                            <td
                                v-if="pixelforge_available"
                                class="p-3 text-right"
                            >
                                <MatchBadge :matches="day.matches" />
                            </td>
                        </tr>
                        <tr
                            v-for="user in day.users"
                            :key="`${day.date}-${user.user_id}`"
                            class="border-t text-muted-foreground"
                        >
                            <td class="py-2 pr-3 pl-8">
                                {{ user.name }}
                                <span class="text-xs">{{ user.email }}</span>
                            </td>
                            <td class="p-2 text-right">
                                {{ user.generations }}
                            </td>
                            <td
                                class="p-2 text-right"
                                :title="user.charged_exact"
                            >
                                {{ user.charged }}
                            </td>
                            <td
                                class="p-2 text-right"
                                :title="user.provider_exact"
                            >
                                {{ user.provider }}
                            </td>
                            <td
                                class="p-2 text-right"
                                :title="user.profit_exact"
                            >
                                {{ user.profit }}
                            </td>
                            <td
                                v-if="pixelforge_available"
                                class="p-2 text-right text-xs"
                                colspan="2"
                            >
                                not reported per user
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 bg-muted/50 font-semibold">
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right">{{ totals.generations }}</td>
                        <td
                            class="p-3 text-right"
                            :title="totals.charged_exact"
                        >
                            {{ totals.charged }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="totals.provider_exact"
                        >
                            {{ totals.provider }}
                        </td>
                        <td class="p-3 text-right" :title="totals.profit_exact">
                            {{ totals.profit }}
                        </td>
                        <td
                            v-if="pixelforge_available"
                            class="p-3 text-right"
                            :title="totals.pixelforge_exact ?? ''"
                        >
                            {{ totals.pixelforge }}
                        </td>
                        <td v-if="pixelforge_available" class="p-3 text-right">
                            <MatchBadge :matches="totals.matches" />
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            Hover a figure for its exact nine-decimal amount. Days are UTC and
            use the day a generation completed, which is how Pixelforge bills.
        </p>
    </div>
</template>
