<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

/**
 * Pure price calculations. No database, no side effects: every rule here is checked by the self-test.
 *
 * Truck trip (spec): starting fee + billable km × rate per km + company driver fee + daily rate × days.
 *   Company driver fee = flat fee for the first N km, then driver rate per km after that.
 *   N is counted on the round trip, or on the one-way distance when the setting says so (then N × 2 of the round trip).
 *   Free km (if any) are taken off the distance charge only.
 *
 * Equipment line: charged days = max(rental days, minimum days); each piece costs the cheapest mix of
 *   months (30 days), weeks and days that covers the charged days.
 */
final class Pricing
{
    /**
     * @param array<string, mixed> $r truck type rates (starting_fee, rate_per_km, driver_flat_fee, driver_flat_km, driver_rate_per_km, daily_rate, free_km)
     * @return array{km:float, billable_km:float, starting_fee:float, distance:float, driver:float, daily:float, total:float}
     */
    public static function truckTrip(array $r, float $roundTripKm, bool $withDriver, int $days = 0, string $driverBasis = 'round_trip'): array
    {
        $km = round(max(0.0, $roundTripKm), 1);
        $billable = max(0.0, $km - (float) ($r['free_km'] ?? 0));
        $start = (float) $r['starting_fee'];
        $distance = round($billable * (float) $r['rate_per_km'], 2);
        $driver = 0.0;
        if ($withDriver) {
            $flatKm = (float) $r['driver_flat_km'] * ($driverBasis === 'one_way' ? 2 : 1);
            $driver = round((float) $r['driver_flat_fee'] + max(0.0, $km - $flatKm) * (float) $r['driver_rate_per_km'], 2);
        }
        $daily = round(max(0, $days) * (float) ($r['daily_rate'] ?? 0), 2);
        return [
            'km' => $km,
            'billable_km' => $billable,
            'starting_fee' => $start,
            'distance' => $distance,
            'driver' => $driver,
            'daily' => $daily,
            'total' => round($start + $distance + $driver + $daily, 2),
        ];
    }

    /**
     * @param array<string, mixed> $r item rates (rate_day, rate_week, rate_month, min_days)
     * @return array{charged_days:int, months:int, weeks:int, days:int, unit_price:float, amount:float}
     */
    public static function equipment(array $r, int $qty, int $rentalDays): array
    {
        $charged = max($rentalDays, (int) $r['min_days'], 1);
        $d = (float) $r['rate_day'];
        $w = (float) $r['rate_week'];
        $m = (float) $r['rate_month'];
        $best = null;
        $maxMonths = $m > 0 ? (int) ceil($charged / 30) : 0;
        for ($mo = 0; $mo <= $maxMonths; $mo++) {
            $afterMonths = max(0, $charged - 30 * $mo);
            $maxWeeks = $w > 0 ? (int) ceil($afterMonths / 7) : 0;
            for ($wk = 0; $wk <= $maxWeeks; $wk++) {
                $left = max(0, $afterMonths - 7 * $wk);
                if ($left > 0 && $d <= 0) {
                    continue; // cannot cover leftover days without a day rate
                }
                $cost = $mo * $m + $wk * $w + $left * $d;
                if ($best === null || $cost < $best[0] - 0.001) {
                    $best = [$cost, $mo, $wk, $left];
                }
            }
        }
        $best ??= [0.0, 0, 0, 0];
        return [
            'charged_days' => $charged,
            'months' => $best[1],
            'weeks' => $best[2],
            'days' => $best[3],
            'unit_price' => round($best[0], 2),
            'amount' => round($best[0] * $qty, 2),
        ];
    }

    public static function deposit(string $mode, float $value, float $amount): float
    {
        return round($mode === 'fixed' ? $value : $amount * $value / 100, 2);
    }

    /** "1 month + 1 week + 3 days" */
    public static function periodLabel(array $e): string
    {
        $parts = [];
        foreach (['months' => 'month', 'weeks' => 'week', 'days' => 'day'] as $k => $word) {
            if ((int) $e[$k] > 0) {
                $parts[] = $e[$k] . ' ' . $word . ((int) $e[$k] === 1 ? '' : 's');
            }
        }
        return $parts === [] ? '—' : implode(' + ', $parts);
    }
}
