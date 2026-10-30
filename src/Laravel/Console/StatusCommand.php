<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel\Console;

use Fedoseev\Hindsight\Exceptions\HindsightException;
use Fedoseev\Hindsight\Hindsight;
use Illuminate\Console\Command;

/**
 * Check that the application can reach Hindsight with the configured URL and key.
 */
final class StatusCommand extends Command
{
    protected $signature = 'hindsight:status {--bank= : Also show the stats of this memory bank}';

    protected $description = 'Check the connection to the Hindsight API';

    public function handle(Hindsight $hindsight): int
    {
        $url = config('hindsight.url');

        try {
            $version = $hindsight->version();
            $features = is_array($version['features'] ?? null) ? $version['features'] : [];

            $this->components->twoColumnDetail('API', is_string($url) ? $url : '');
            $this->components->twoColumnDetail('Version', is_string($version['api_version'] ?? null) ? $version['api_version'] : 'unknown');
            $this->components->twoColumnDetail('Enabled features', implode(', ', array_keys(array_filter($features))) ?: 'none');

            $bank = $this->option('bank');

            if (is_string($bank) && $bank !== '') {
                $stats = $hindsight->getBankStats($bank);

                $this->components->twoColumnDetail("Bank {$bank}", sprintf(
                    '%d memories, %d documents, %d pending operations',
                    is_int($stats['total_nodes'] ?? null) ? $stats['total_nodes'] : 0,
                    is_int($stats['total_documents'] ?? null) ? $stats['total_documents'] : 0,
                    is_int($stats['pending_operations'] ?? null) ? $stats['pending_operations'] : 0,
                ));
            }
        } catch (HindsightException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Hindsight is reachable.');

        return self::SUCCESS;
    }
}
