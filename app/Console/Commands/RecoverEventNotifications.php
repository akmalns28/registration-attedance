<?php

namespace App\Console\Commands;

use App\Services\EventNotificationRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:recover {--dry-run : Preview without writes or dispatch} {--limit= : Maximum deliveries to recover (1-10000)}')]
#[Description('Recover abandoned registration email deliveries using the existing database queue')]
class RecoverEventNotifications extends Command
{
    public function handle(EventNotificationRecoveryService $recovery): int
    {
        $limit = filter_var($this->option('limit') ?? config('event_notifications.recovery_limit', 100), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10000],
        ]);

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 10000.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $recovery->recover($dryRun, $limit);

        $this->line(($dryRun ? 'Dry run. ' : '').sprintf(
            'Checked: %d; Eligible: %d; Recovered: %d; Skipped: %d; Ineligible: %d; Exhausted: %d; Errors: %d.',
            $counts['checked'], $counts['eligible'], $counts['recovered'], $counts['skipped'],
            $counts['ineligible'], $counts['exhausted'], $counts['errors'],
        ));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
