<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What users were charged, what Pixelforge charged, and the profit, per UTC completion
 * day and user. Pixelforge bills on the UTC day a generation completed, so the days line
 * up with GET /v1/usage. Failed generations have no cost, so only succeeded ones count.
 *
 * @phpstan-type UserRow array{user_id: string, name: string, email: string, generations: int, charged: int, provider: int, profit: int}
 * @phpstan-type DayRow array{date: string, generations: int, charged: int, provider: int, profit: int, pixelforge: int|null, matches: bool|null, users: list<UserRow>}
 */
class SpendReport
{
    /**
     * @param  array<string, int>|null  $pixelforgeByDay  GET /v1/usage costs by day; null when unavailable.
     * @return array{days: list<DayRow>, totals: array{generations: int, charged: int, provider: int, profit: int, pixelforge: int|null, matches: bool|null}}
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to, ?array $pixelforgeByDay): array
    {
        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::SUCCEEDED->value)
            ->whereBetween('generations.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(generations.completed_at) as day, users.id as user_id, users.name, users.email, COUNT(*) as total_generations, SUM(generations.charged_cost) as total_charged, SUM(generations.provider_cost) as total_provider_cost')
            ->groupBy('day', 'users.id', 'users.name', 'users.email')
            ->orderBy('day')
            ->orderBy('users.name')
            ->get();

        $days = [];

        foreach ($rows as $row) {
            $charged = (int) $row->total_charged;
            $provider = (int) $row->total_provider_cost;

            $days[$row->day] ??= $this->emptyDay($row->day);
            $days[$row->day]['users'][] = [
                'user_id' => $row->user_id,
                'name' => $row->name,
                'email' => $row->email,
                'generations' => (int) $row->total_generations,
                'charged' => $charged,
                'provider' => $provider,
                'profit' => $charged - $provider,
            ];
            $days[$row->day]['generations'] += (int) $row->total_generations;
            $days[$row->day]['charged'] += $charged;
            $days[$row->day]['provider'] += $provider;
            $days[$row->day]['profit'] += $charged - $provider;
        }

        foreach ($pixelforgeByDay ?? [] as $date => $cost) {
            if ($cost > 0) {
                $days[$date] ??= $this->emptyDay($date);
            }
        }

        ksort($days);

        $totals = ['generations' => 0, 'charged' => 0, 'provider' => 0, 'profit' => 0, 'pixelforge' => null, 'matches' => null];

        foreach ($days as &$day) {
            $day['pixelforge'] = $pixelforgeByDay === null ? null : ($pixelforgeByDay[$day['date']] ?? 0);
            $day['matches'] = $day['pixelforge'] === null ? null : $day['pixelforge'] === $day['provider'];

            foreach (['generations', 'charged', 'provider', 'profit'] as $key) {
                $totals[$key] += $day[$key];
            }
        }
        unset($day);

        if ($pixelforgeByDay !== null) {
            $totals['pixelforge'] = array_sum($pixelforgeByDay);
            $totals['matches'] = $totals['pixelforge'] === $totals['provider'];
        }

        return ['days' => array_reverse(array_values($days)), 'totals' => $totals];
    }

    /**
     * @return DayRow
     */
    private function emptyDay(string $date): array
    {
        return ['date' => $date, 'generations' => 0, 'charged' => 0, 'provider' => 0, 'profit' => 0, 'pixelforge' => null, 'matches' => null, 'users' => []];
    }
}
