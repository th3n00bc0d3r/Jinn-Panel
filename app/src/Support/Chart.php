<?php
declare(strict_types=1);

/**
 * Charts as server-rendered HTML and SVG - no chart library, readable
 * without JavaScript. pages/charts.js (loaded automatically) adds the
 * crosshair, tooltips, arrow-key reading and range switching.
 *
 * The rules (docs/UI.md, "Charts"):
 *  - series colours in a fixed order: s1..s4, then "so" (Other) for the rest;
 *    a series keeps its class when others are filtered out;
 *  - 2px lines, a light wash under a single area, columns at most 24px wide
 *    with a 4px rounded top and a 2px gap between stacked parts;
 *  - hairline grid, axis text in text colours, never in the series colour;
 *  - one y-axis; two measures of different scale are two charts;
 *  - a legend for two or more series, and a table view for every chart.
 *
 * Every method returns escaped HTML.
 */
final class Chart
{
    public const FORMATS = ['count', 'compact', 'bytes', 'pct', 'load', 'ms'];

    /** A clean axis maximum and its ticks: 0 and $steps steps of 1, 2, 2.5 or 5 x 10^n. @return array{0:float,1:list<float>} */
    public static function scale(float $max, int $steps = 4): array
    {
        if ($max <= 0 || !is_finite($max)) {
            return [(float) $steps, array_map('floatval', range(0, $steps))];
        }
        $raw = $max / $steps;
        $mag = 10 ** floor(log10($raw));
        $step = 10 * $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw - 1e-9 * $raw) {
                $step = $m * $mag;
                break;
            }
        }
        $ticks = [];
        for ($i = 0; $i <= $steps; $i++) {
            $ticks[] = round($step * $i, 10);
        }
        return [round($step * $steps, 10), $ticks];
    }

    /** scale() in the unit the axis will show: bytes step in KB/MB/GB (powers of 1024), so ticks read 2 GB, not 1.9 GB. @return array{0:float,1:list<float>} */
    public static function scaleFor(float $max, string $fmt): array
    {
        if ($fmt !== 'bytes' || $max < 1024) {
            return self::scale($max);
        }
        $unit = 1024 ** min(5, (int) floor(log($max, 1024)));
        [$top, $ticks] = self::scale($max / $unit);
        return [$top * $unit, array_map(fn($t) => $t * $unit, $ticks)];
    }

    /** 1,284 / 12.9K / 3.4M / 1.2B: four characters or so, for axes and tight cells. */
    public static function compact(float $n): string
    {
        $a = abs($n);
        $short = fn(float $v, string $u) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . $u;
        return match (true) {
            $a >= 1e9 => $short($n / 1e9, 'B'),
            $a >= 1e6 => $short($n / 1e6, 'M'),
            $a >= 1e4 => $short($n / 1e3, 'K'),
            $a > 0 && $a < 10 && floor($a) != $a => rtrim(rtrim(number_format($n, 2), '0'), '.'),
            default => number_format($n),
        };
    }

    /** 0 B / 512 KB / 1.5 GB (powers of 1024, at most one decimal). */
    public static function bytes(float $b): string
    {
        if ($b <= 0) {
            return '0 B';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = max(0, min(count($u) - 1, (int) floor(log($b, 1024))));
        return rtrim(rtrim(number_format($b / 1024 ** $i, 1, '.', ''), '0'), '.') . ' ' . $u[$i];
    }

    /** One value in one of FORMATS: count (1,284), compact (12.9K), bytes, pct (43%), load (0.62), ms (180 ms / 1.2 s). */
    public static function format(float $v, string $how = 'compact'): string
    {
        return match ($how) {
            'bytes' => self::bytes($v),
            'pct' => round($v) . '%',
            'load' => number_format($v, 2),
            'ms' => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . ' s' : round($v) . ' ms',
            'count' => number_format($v),
            default => self::compact($v),
        };
    }

    /**
     * A small trend line for a table row or a stat: no axes, the last value
     * marked. $o: series (s1..s4), area (bool), height (px).
     *
     * @param list<float|int> $values
     */
    public static function sparkline(array $values, string $label, array $o = []): string
    {
        $values = array_values($values);
        $n = count($values);
        if ($n < 2) {
            return '<span class="block text-[12px] text-subtle">' . e(t('No data yet')) . '</span>';
        }
        $max = max(1e-9, (float) max($values));
        $min = min(0.0, (float) min($values));
        $s = self::cls($o['series'] ?? 's1');
        $var = self::colour($s);
        $h = (int) ($o['height'] ?? 28);
        $y = fn(float $v) => round(100 - ($v - $min) / ($max - $min) * 92 - 4, 2);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i / ($n - 1) * 100, 2) . ',' . $y((float) $v);
        }
        $path = 'M' . implode(' L', $pts);
        return '<span class="relative block w-full" style="height:' . $h . 'px" role="img" aria-label="' . e($label) . '">'
            . '<svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">'
            . (!empty($o['area']) ? '<path d="' . $path . ' L100,100 L0,100 Z" style="fill:' . $var . ';fill-opacity:var(--jp-chart-wash)"/>' : '')
            . '<path d="' . $path . '" fill="none" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" style="stroke:' . $var . '"/>'
            . '</svg>'
            . '<span class="absolute h-[7px] w-[7px] -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-surface ' . $s . '" style="left:100%;top:' . $y((float) $values[$n - 1]) . '%"></span>'
            . '</span>';
    }

    /**
     * Lines over time with a y-axis, x labels and a crosshair (one series can
     * be an area). $o: height (px), area (bool), fmt (FORMATS), xEvery (label
     * every n points), max (fixed axis top, e.g. 100 for percent), ref
     * ([value, label]: a dashed limit line), table (heading of the first
     * column, or false for no table view).
     *
     * @param list<array{name:string, values:list<float|int>, series?:string}> $series
     * @param list<string> $labels
     */
    public static function line(array $series, array $labels, string $label, array $o = []): string
    {
        $labels = array_values($labels);
        $n = count($labels);
        $h = (int) ($o['height'] ?? 220);
        if ($n === 0 || $series === []) {
            return self::none($h);
        }
        View::script('charts');
        $fmt = self::fmtOf($o);
        $series = self::withClasses($series);
        $all = array_merge(...array_map(fn($s) => array_map('floatval', $s['values']), $series));
        $top = max(array_merge($all, [0.0, isset($o['ref'][0]) ? (float) $o['ref'][0] * 1.08 : 0.0]));
        [$max, $ticks] = isset($o['max']) ? [(float) $o['max'], array_map(fn($i) => $o['max'] * $i / 4, range(0, 4))] : self::scaleFor((float) $top, $fmt);
        $yOf = fn(float $v) => round(100 - min($v, $max) / $max * 100, 3);

        $svg = '';
        foreach ($series as $s) {
            $pts = [];
            foreach (array_values($s['values']) as $i => $v) {
                $pts[] = round($i / max(1, $n - 1) * 1000, 2) . ',' . $yOf((float) $v);
            }
            $d = 'M' . implode(' L', $pts);
            $var = self::colour($s['series']);
            if (!empty($o['area']) && count($series) === 1) {
                $svg .= '<path d="' . $d . ' L1000,100 L0,100 Z" style="fill:' . $var . ';fill-opacity:var(--jp-chart-wash)"/>';
            }
            $svg .= '<path d="' . $d . '" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" style="stroke:' . $var . '"/>';
        }
        $data = ['kind' => 'line', 'labels' => $labels, 'series' => array_map(fn($s) => [
            'name' => $s['name'],
            'series' => $s['series'],
            'values' => array_map(fn($v) => self::format((float) $v, $fmt), array_values($s['values'])),
            'y' => array_map(fn($v) => $yOf((float) $v), array_values($s['values'])),
        ], $series)];

        $html = self::open($label, $h, $data, $fmt) . self::grid($ticks, $max, $fmt);
        $every = (int) ($o['xEvery'] ?? max(1, (int) ceil($n / 6)));
        $html .= self::xLabels($labels, $every, fn($i) => $i / max(1, $n - 1) * 100);
        if (isset($o['ref'][0], $o['ref'][1])) {
            // A limit to compare against: a dashed line with its name (not colour alone).
            $html .= '<div class="chart-ref" style="top:' . $yOf((float) $o['ref'][0]) . '%"><span>' . e((string) $o['ref'][1]) . '</span></div>';
        }
        $html .= '<svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true">' . $svg . '</svg>';
        foreach ($series as $s) {
            $last = (float) (array_values($s['values'])[$n - 1] ?? 0);
            $html .= '<span class="absolute h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-surface ' . $s['series'] . '" style="left:100%;top:' . $yOf($last) . '%"></span>';
        }
        $html .= '</div></div>';
        if (($o['table'] ?? true) !== false) {
            $html .= self::table($series, $labels, ['fmt' => $fmt, 'first' => is_string($o['table'] ?? null) ? $o['table'] : t('Time')]);
        }
        return $html;
    }

    /**
     * Stacked columns, one per time bucket. $series: [name, series] in stack
     * order (bottom first); $stacks: per column, one value per series. $o:
     * height, fmt, xEvery, annotate => [column index, text], table.
     *
     * @param list<array{name:string, series?:string}> $series
     * @param list<list<float|int>> $stacks
     * @param list<string> $labels
     */
    public static function columns(array $series, array $stacks, array $labels, string $label, array $o = []): string
    {
        $stacks = array_values(array_map('array_values', $stacks));
        $labels = array_values($labels);
        $h = (int) ($o['height'] ?? 240);
        $n = count($stacks);
        if ($n === 0 || $series === []) {
            return self::none($h);
        }
        View::script('charts');
        $fmt = self::fmtOf($o);
        $series = self::withClasses($series);
        [$max, $ticks] = self::scaleFor((float) max(array_map(fn($c) => array_sum(array_map('floatval', $c)), $stacks)), $fmt);
        $data = [
            'kind' => 'columns',
            'labels' => $labels,
            'series' => array_map(fn($s, $k) => [
                'name' => $s['name'],
                'series' => $s['series'],
                'values' => array_map(fn($col) => self::format((float) ($col[$k] ?? 0), $fmt), $stacks),
            ], $series, array_keys($series)),
            'totals' => count($series) > 1 ? array_map(fn($col) => self::format((float) array_sum($col), $fmt), $stacks) : null,
        ];

        $html = self::open($label, $h, $data, $fmt) . self::grid($ticks, $max, $fmt);
        $html .= '<div class="absolute inset-0 flex items-stretch gap-[2px]">';
        foreach ($stacks as $i => $col) {
            $html .= '<div class="chart-col" data-i="' . $i . '">';
            foreach ($series as $k => $s) {
                $v = (float) ($col[$k] ?? 0);
                if ($v > 0) {
                    $html .= '<span class="' . $s['series'] . '" style="height:' . round($v / $max * 100, 3) . '%"></span>';
                }
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $every = (int) ($o['xEvery'] ?? max(1, (int) ceil($n / 7)));
        $html .= self::xLabels($labels, $every, fn($i) => ($i + 0.5) / $n * 100);
        if (isset($o['annotate'][0], $stacks[$o['annotate'][0]])) {
            [$ai, $text] = $o['annotate'];
            $top = 100 - array_sum($stacks[$ai]) / $max * 100;
            $html .= '<div class="pointer-events-none absolute flex -translate-x-1/2 -translate-y-full flex-col items-center" style="left:' . round(($ai + 0.5) / $n * 100, 3) . '%;top:' . round($top, 3) . '%">'
                . '<span class="whitespace-nowrap rounded-[6px] border border-line bg-surface px-2 py-0.5 text-[12px] font-medium text-fg shadow-sm">' . e((string) $text) . '</span>'
                . '<span class="h-2.5 w-px bg-fg/40"></span></div>';
        }
        $html .= '</div></div>';
        if (($o['table'] ?? true) !== false) {
            $tableSeries = array_map(fn($s, $k) => ['name' => $s['name'], 'values' => array_map(fn($col) => $col[$k] ?? 0, $stacks)], $series, array_keys($series));
            if (count($series) > 1) {
                $tableSeries[] = ['name' => t('Total'), 'values' => array_map('array_sum', $stacks)];
            }
            $html .= self::table($tableSeries, $labels, ['fmt' => $fmt, 'first' => is_string($o['table'] ?? null) ? $o['table'] : t('Time')]);
        }
        return $html;
    }

    /** Legend in series order: a swatch for areas and columns, a line key for lines. */
    public static function legend(array $series, bool $lines = false): string
    {
        $html = '<ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-muted">';
        foreach (self::withClasses($series) as $s) {
            $html .= '<li class="flex items-center gap-1.5"><span class="' . ($lines ? 'chart-key' : 'chart-swatch') . ' ' . $s['series'] . '"></span>' . e($s['name']) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * A ring gauge for one share of a limit. $o: tone (accent|warning|danger),
     * size (px), center (escaped HTML for the middle; default the percentage).
     */
    public static function ring(float $pct, string $label, array $o = []): string
    {
        $pct = max(0, min(100, $pct));
        $size = (int) ($o['size'] ?? 72);
        [$tone, $track] = match ($o['tone'] ?? 'accent') {
            'warning' => ['rgb(var(--jp-warning))', 'rgb(var(--jp-warning) / 0.18)'],
            'danger' => ['rgb(var(--jp-danger))', 'rgb(var(--jp-danger) / 0.18)'],
            default => ['rgb(var(--jp-accent))', 'rgb(var(--jp-accent) / 0.15)'],
        };
        return '<span class="relative inline-flex shrink-0 items-center justify-center" style="width:' . $size . 'px;height:' . $size . 'px" role="img" aria-label="' . e($label) . ': ' . round($pct) . '%">'
            . '<svg class="absolute inset-0 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">'
            . '<circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" style="stroke:' . $track . '"/>'
            . ($pct > 0 ? '<circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" pathLength="100" stroke-dasharray="' . round($pct, 2) . ' 100" style="stroke:' . $tone . '"/>' : '')
            . '</svg><span class="relative text-[13px] font-semibold tabular-nums text-fg" aria-hidden="true">' . ($o['center'] ?? round($pct) . '%') . '</span></span>';
    }

    /**
     * Uptime strip, oldest first: 'up' | 'slow' | 'down' | null (no check).
     * Status colours, so always print the uptime figure beside it.
     *
     * @param list<?string> $checks
     */
    public static function uptime(array $checks, string $label): string
    {
        $html = '<div class="flex h-5 items-stretch gap-[2px]" role="img" aria-label="' . e($label) . '">';
        foreach ($checks as $c) {
            $cls = match ($c) {
                'up' => 'bg-success/70',
                'slow' => 'bg-warning',
                'down' => 'bg-danger',
                default => 'bg-surface-3',
            };
            $html .= '<span class="min-w-0 flex-1 rounded-[2px] ' . $cls . '"></span>';
        }
        return $html . '</div>';
    }

    /**
     * Ranked bars, largest first, one hue: top pages, referrers, accounts.
     * $rows: [name, value, shown text]. $o: mono (names in monospace; default true).
     *
     * @param list<array{0:string,1:float|int,2:string}> $rows
     */
    public static function ranked(array $rows, string $label, array $o = []): string
    {
        if ($rows === []) {
            return '<p class="py-6 text-center text-[13px] text-subtle">' . e(t('Nothing yet.')) . '</p>';
        }
        $max = max(1e-9, max(array_map(fn($r) => (float) $r[1], $rows)));
        $name = ($o['mono'] ?? true) ? 'font-mono text-[12.5px]' : 'text-[13px]';
        $html = '<ol class="space-y-2.5" aria-label="' . e($label) . '">';
        foreach ($rows as [$n, $v, $shown]) {
            $html .= '<li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1">'
                . '<span class="truncate ' . $name . ' text-fg" title="' . e($n) . '">' . e($n) . '</span>'
                . '<span class="text-right text-[12.5px] tabular-nums text-muted">' . e($shown) . '</span>'
                . '<span class="col-span-2 block h-1.5 rounded-full bg-accent/10"><span class="block h-full rounded-full s1" style="width:' . round((float) $v / $max * 100, 2) . '%"></span></span>'
                . '</li>';
        }
        return $html . '</ol>';
    }

    /**
     * Range choice for a chart (24 hours / 7 days / 30 days): links that keep
     * the rest of the query string, so it works without JavaScript; with it,
     * charts.js swaps only the [data-chart-region] around them.
     *
     * @param array<string,string> $ranges value => label
     */
    public static function ranges(array $ranges, string $current, string $label, string $param = 'range'): string
    {
        $query = $_GET;
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $html = '<nav class="segmented" aria-label="' . e($label) . '">';
        foreach ($ranges as $value => $text) {
            $query[$param] = (string) $value;
            $html .= '<a href="' . e($path . '?' . http_build_query($query)) . '" data-chart-range' . ((string) $value === $current ? ' aria-current="true"' : '') . '>' . e($text) . '</a>';
        }
        return $html . '</nav>';
    }

    /** The same numbers as a table, behind a "Show as table" disclosure. $o: fmt, first (first column heading). */
    public static function table(array $series, array $labels, array $o = []): string
    {
        $fmt = self::fmtOf($o);
        $html = '<details class="mt-3"><summary class="inline-flex cursor-pointer list-none items-center gap-1 rounded text-[12.5px] text-muted hover:text-fg [&::-webkit-details-marker]:hidden">'
            . icon('list', 'h-3.5 w-3.5') . e(t('Show as table')) . '</summary>'
            . '<div class="table-wrap mt-2 max-h-72 overflow-y-auto rounded-[8px] border border-line"><table class="table table-tight"><thead><tr><th scope="col">' . e((string) ($o['first'] ?? t('Time'))) . '</th>';
        foreach ($series as $s) {
            $html .= '<th scope="col" class="text-right">' . e($s['name']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_values($labels) as $i => $l) {
            $html .= '<tr><td>' . e($l) . '</td>';
            foreach ($series as $s) {
                $html .= '<td class="text-right">' . e(self::format((float) (array_values($s['values'])[$i] ?? 0), $fmt)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></details>';
    }

    // -----------------------------------------------------------------------

    /** s1..s4 or so; anything else (a 5th series, a typo) folds into Other. */
    private static function cls(mixed $c): string
    {
        return in_array($c, ['s1', 's2', 's3', 's4', 'so'], true) ? $c : 'so';
    }

    private static function colour(string $cls): string
    {
        return $cls === 'so' ? 'var(--jp-series-other)' : 'var(--jp-series-' . substr($cls, 1) . ')';
    }

    /** Series without a class get the next one in order; past the fourth, Other. */
    private static function withClasses(array $series): array
    {
        $out = [];
        foreach (array_values($series) as $k => $s) {
            $s['series'] = self::cls($s['series'] ?? ($k < 4 ? 's' . ($k + 1) : 'so'));
            $out[] = $s;
        }
        return $out;
    }

    private static function fmtOf(array $o): string
    {
        $f = (string) ($o['fmt'] ?? 'compact');
        return in_array($f, self::FORMATS, true) ? $f : 'compact';
    }

    private static function open(string $label, int $h, array $data, string $fmt = 'compact'): string
    {
        // Room for the y-axis labels: "7.5 GB" and "1.2 s" are wider than "20K".
        $left = in_array($fmt, ['bytes', 'ms'], true) ? 60 : 48;
        return '<div class="chart" style="height:' . $h . 'px" role="img" tabindex="0" aria-label="' . e($label . '. ' . t('Use the arrow keys to read values.')) . '" data-chart="'
            . e((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
            . '<div class="chart-plot" style="left:' . $left . 'px;right:4px;top:6px;bottom:26px" data-chart-plot>';
    }

    /** @param list<float> $ticks */
    private static function grid(array $ticks, float $max, string $fmt): string
    {
        $html = '';
        foreach ($ticks as $t) {
            $top = round(100 - $t / $max * 100, 3);
            $html .= '<div class="' . ($t == 0 ? 'chart-base' : 'chart-grid') . '" style="top:' . $top . '%"></div>'
                . '<span class="chart-ytick" style="right:100%;top:' . $top . '%">' . e(self::tick($t, $max, $fmt)) . '</span>';
        }
        return $html;
    }

    /** An axis label: bytes all in the top tick's unit (0.5 GB, 1 GB, not 512 MB, 1 GB). */
    private static function tick(float $t, float $max, string $fmt): string
    {
        if ($fmt !== 'bytes' || $max < 1024) {
            return self::format($t, $fmt);
        }
        $i = min(5, (int) floor(log($max, 1024)));
        $v = $t / 1024 ** $i;
        return ($v == 0 ? '0' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')) . ' ' . ['B', 'KB', 'MB', 'GB', 'TB', 'PB'][$i];
    }

    /** X labels every $every points; every other one drops out on phones, where they would collide. */
    private static function xLabels(array $labels, int $every, callable $left): string
    {
        $html = '';
        $k = 0;
        foreach ($labels as $i => $l) {
            if ($i % max(1, $every) === 0) {
                $html .= '<span class="chart-xtick' . ($k++ % 2 ? ' max-sm:hidden' : '') . '" style="left:' . round($left($i), 3) . '%;top:100%">' . e((string) $l) . '</span>';
            }
        }
        return $html;
    }

    private static function none(int $h): string
    {
        return '<div class="flex items-center justify-center rounded-[8px] border border-dashed border-line text-[13px] text-subtle" style="height:' . $h . 'px">'
            . e(t('No data yet. Numbers appear here once the panel has collected some.')) . '</div>';
    }
}
