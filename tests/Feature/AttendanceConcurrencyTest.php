<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AttendanceConcurrencyTest extends TestCase
{
    public function test_two_processes_confirming_the_same_registration_create_only_one_attendance(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'attendance-concurrency-');
        $this->assertIsString($database);
        $gate = $database.'.start';
        $originalConnection = DB::getDefaultConnection();
        $processes = [];
        $connection = [
            ...config('database.connections.sqlite'), 'url' => null, 'database' => $database,
            'busy_timeout' => 10000, 'journal_mode' => 'WAL',
        ];
        config(['database.connections.attendance_concurrency' => $connection]);
        DB::setDefaultConnection('attendance_concurrency');

        try {
            $this->artisan('migrate', ['--database' => 'attendance_concurrency', '--no-interaction' => true])->assertSuccessful();
            Queue::fake();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Permission::findOrCreate('attendance.scan', 'web');
            $operator = User::factory()->create(['is_active' => true]);
            $operator->givePermissionTo('attendance.scan');
            $today = now(config('app.timezone'))->toDateString();
            $event = Event::factory()->published()->create(['start_date' => $today, 'end_date' => $today]);
            $day = EventDay::factory()->for($event)->create(['date' => $today]);
            $registration = EventRegistration::factory()->for($event)->create();
            $worker = <<<'PHP'
                require $argv[1].'/vendor/autoload.php';
                $app = require $argv[1].'/bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.attendance_concurrency' => json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR)]);
                Illuminate\Support\Facades\DB::setDefaultConnection('attendance_concurrency');
                file_put_contents($argv[3].'.ready-'.$argv[8], 'ready');
                $deadline = microtime(true) + 15;
                while (!is_file($argv[3])) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent test start gate timed out.');
                    }
                    usleep(10000);
                }
                $result = app(App\Services\AttendanceCheckinService::class)->checkIn(
                    $argv[4], App\Models\Event::findOrFail($argv[5]),
                    App\Models\EventDay::findOrFail($argv[6]), App\Models\User::findOrFail($argv[7]),
                );
                echo json_encode(['created' => $result['created'], 'id' => $result['attendance']->id], JSON_THROW_ON_ERROR);
                PHP;

            for ($index = 0; $index < 2; $index++) {
                $process = new Process([
                    PHP_BINARY, '-r', $worker, base_path(), json_encode($connection, JSON_THROW_ON_ERROR), $gate,
                    'RA1:'.$registration->uuid, (string) $event->id, (string) $day->id, (string) $operator->id, (string) $index,
                ], base_path(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array'], timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($gate.'.ready-0') || ! is_file($gate.'.ready-1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Concurrent workers did not become ready: '.implode("\n", array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
                }
                usleep(10000);
            }
            file_put_contents($gate, 'start');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, array_sum(array_column($results, 'created')));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, Attendance::query()->count());
            $this->assertSame($operator->id, Attendance::query()->sole()->checked_in_by);
            $this->assertSame('registered', $registration->fresh()->status);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::setDefaultConnection($originalConnection);
            DB::purge('attendance_concurrency');
            foreach ([$database, $database.'-wal', $database.'-shm', $gate, $gate.'.ready-0', $gate.'.ready-1'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
