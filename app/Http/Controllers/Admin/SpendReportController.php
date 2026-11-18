<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\PixelforgeException;
use App\Pricing\Money;
use App\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

class SpendReportController extends Controller
{
    /**
     * The same window as GET /v1/usage, which defaults to the last 30 days and allows at most 92.
     */
    private const int DEFAULT_DAYS = 30;

    private const int MAX_DAYS = 92;

    public function __invoke(Request $request, SpendReport $report, PixelforgeClient $client): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:today'],
        ]);

        $end = CarbonImmutable::parse($validated['end_date'] ?? 'today', 'UTC')->startOfDay();
        $start = CarbonImmutable::parse($validated['start_date'] ?? $end->subDays(self::DEFAULT_DAYS - 1), 'UTC')->startOfDay();

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        try {
            $pixelforgeByDay = $client->usageByDay($start->toDateString(), $end->toDateString());
        } catch (ConnectionException|PixelforgeException|UnexpectedValueException) {
            $pixelforgeByDay = null;
        }

        $data = $report->forRange($start, $end, $pixelforgeByDay);

        return Inertia::render('admin/SpendReport', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pixelforge_available' => $pixelforgeByDay !== null,
            'totals' => $this->format($data['totals']),
            'days' => array_map(fn (array $day): array => [
                ...$this->format($day),
                'date' => $day['date'],
                'users' => array_map(fn (array $user): array => [
                    ...$this->format($user),
                    'user_id' => $user['user_id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                ], $day['users']),
            ], $data['days']),
        ]);
    }

    /**
     * Money is formatted here, once: rounded for reading, with the exact 9-decimal figure beside it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $out = ['generations' => $row['generations'], 'matches' => $row['matches'] ?? null];

        foreach (['charged', 'provider', 'profit', 'pixelforge'] as $key) {
            $out[$key] = isset($row[$key]) ? Money::format($row[$key]) : null;
            $out["{$key}_exact"] = isset($row[$key]) ? Money::formatExact($row[$key]) : null;
        }

        return $out;
    }
}
