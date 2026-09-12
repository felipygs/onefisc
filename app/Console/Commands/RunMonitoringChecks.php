<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\MonitoringService;
use Illuminate\Console\Command;

class RunMonitoringChecks extends Command
{
    protected $signature = 'monitoring:run';

    protected $description = 'Run scheduled fiscal monitoring for all clients, counting toward plan volume';

    public function handle(MonitoringService $monitoring): int
    {
        $ran = 0;
        $skipped = 0;

        Client::query()->withoutGlobalScopes()->chunkById(100, function ($clients) use ($monitoring, &$ran, &$skipped): void {
            foreach ($clients as $client) {
                try {
                    $monitoring->run($client);
                    $ran++;
                } catch (\Throwable) {
                    $skipped++;
                }
            }
        });

        $this->info("Monitoring: {$ran} ok, {$skipped} suspensos.");

        return self::SUCCESS;
    }
}
