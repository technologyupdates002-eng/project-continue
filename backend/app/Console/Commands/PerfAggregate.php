<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PerfAggregate extends Command
{
    protected $signature = 'perf:aggregate {--profile=storage/logs/request-profile.log} {--slow=storage/logs/slow-queries.log}';
    protected $description = 'Aggregate request_profile and slow-queries into a combined report';

    public function handle()
    {
        $profilePath = base_path($this->option('profile'));
        $slowPath = base_path($this->option('slow'));

        $profiles = [];
        if (file_exists($profilePath)) {
            $lines = file($profilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $l) {
                // try decode JSON or parse log line
                $decoded = $this->tryJson($l);
                if ($decoded) $profiles[] = $decoded;
            }
        }

        $slow = [];
        if (file_exists($slowPath)) {
            $lines = file($slowPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $l) {
                if (strpos($l, 'Slow API request detected') !== false) {
                    $m = $this->extract($l, "'method' => '", "'");
                    $u = $this->extract($l, "'url' => '", "'");
                    $d = $this->extract($l, "'duration_ms' => ", ",");
                    $slow[] = ['method' => $m, 'url' => $u, 'duration_ms' => $d];
                }
            }
        }

        $out = ['generated_at' => now()->toDateTimeString(), 'profiles_count' => count($profiles), 'slow_count' => count($slow), 'profiles' => $profiles, 'slow' => $slow];
        $outPath = storage_path('logs/perf-aggregate-' . time() . '.json');
        file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT));
        $this->info('Wrote aggregated perf report to ' . $outPath);
        return 0;
    }

    private function tryJson($line)
    {
        $p = strpos($line, '{');
        if ($p === false) return null;
        $json = substr($line, $p);
        $decoded = json_decode($json, true);
        return $decoded ?: null;
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
