<?php

namespace App\Console\Commands;

use App\Services\HealthCheckService;
use Illuminate\Console\Command;

/**
 * Run before launch and after every deploy. Read-only, no network calls, never prints secrets.
 * Exit code 1 when any check fails, so a deploy script can stop.
 */
class Preflight extends Command
{
    protected $signature = 'swa:preflight';

    protected $description = 'Check the configuration and background services before going live';

    public function handle(HealthCheckService $health): int
    {
        $checks = $health->all();
        $group = null;

        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $this->newLine();
                $this->line("<options=bold>{$group}</>");
            }

            $mark = match ($check['status']) {
                HealthCheckService::OK => '<fg=green>PASS</>',
                HealthCheckService::WARN => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->line("  {$mark}  {$check['label']}: {$check['detail']}");

            if ($check['fix'] && $check['status'] !== HealthCheckService::OK) {
                $this->line("        → {$check['fix']}");
            }
        }

        $counts = collect($checks)->countBy('status');
        $this->newLine();
        $this->line(sprintf('%d passed, %d warnings, %d failed.', $counts[HealthCheckService::OK] ?? 0, $counts[HealthCheckService::WARN] ?? 0, $counts[HealthCheckService::FAIL] ?? 0));

        return $health->hasFailures($checks) ? self::FAILURE : self::SUCCESS;
    }
}
