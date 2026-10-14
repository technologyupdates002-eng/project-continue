<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PerfSlowReport extends Command
{
    protected $signature = 'perf:slow-report {--file=storage/logs/slow-queries.log}';
    protected $description = 'Aggregate slow-queries.log into a summary JSON report';

    public function handle()
    {
        $path = base_path($this->option('file'));
        if (!file_exists($path)) {
            $this->error("Log file not found: {$path}");
            return 1;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $endpoints = [];
        foreach ($lines as $line) {
            if (strpos($line, 'Slow API request detected') === false) continue;
            // try to extract method, url, duration_ms
            $method = $this->extract($line, "'method' => '", "'");
            $url = $this->extract($line, "'url' => '", "'");
            $duration = $this->extract($line, "'duration_ms' => ", ",");
            if (!$url) continue;
            $key = "{$method} {$url}";
            $durationVal = is_numeric($duration) ? (float)$duration : null;
            if (!isset($endpoints[$key])) {
                $endpoints[$key] = ['count' => 0, 'total_ms' => 0.0, 'samples' => []];
            }
            $endpoints[$key]['count'] += 1;
            if ($durationVal !== null) {
                $endpoints[$key]['total_ms'] += $durationVal;
                $endpoints[$key]['samples'][] = $durationVal;
            }
        }

        // compute averages
        $report = [];
        foreach ($endpoints as $k => $v) {
            $report[] = [
                'endpoint' => $k,
                'count' => $v['count'],
                'avg_ms' => $v['count'] ? round($v['total_ms'] / $v['count'], 2) : null,
                'max_ms' => $v['samples'] ? max($v['samples']) : null,
            ];
        }

        usort($report, function ($a, $b) { return ($b['avg_ms'] ?? 0) <=> ($a['avg_ms'] ?? 0); });

        $outPath = storage_path('logs/perf-slow-report.json');
        file_put_contents($outPath, json_encode(['generated_at' => now()->toDateTimeString(), 'report' => $report], JSON_PRETTY_PRINT));
        $this->info('Perf slow report written to: ' . $outPath);
        return 0;
    }

    private function extract(string $line, string $start, string $end)
    {
        $s = strpos($line, $start);
        if ($s === false) return null;
        $s += strlen($start);
        $e = strpos($line, $end, $s);
        if ($e === false) return substr($line, $s);
        return substr($line, $s, $e - $s);
    }
}
