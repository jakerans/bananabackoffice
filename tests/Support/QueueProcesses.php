<?php

namespace Tests\Support;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Starts the separate PHP processes of the real-concurrency queue tests and
 * gives them a database they genuinely share.
 *
 * On MySQL that is the database the test suite itself runs on: the rows are
 * committed by the processes, because the test's own transaction is invisible
 * to them, and every one of them is removed again afterwards. On SQLite the
 * suite's database lives in memory and cannot be shared, so a temporary
 * file-backed database is migrated for the processes and deleted afterwards.
 *
 * The test itself never reads or writes that data on its own connection.
 */
final class QueueProcesses
{
    /** The fixed clock every process uses: Wednesday 7 October 2026, 10:00 am Eastern, 4:00 am in Honolulu. */
    public const string NOW = '2026-10-07 14:00:00';

    /** @var array<string, string> */
    private array $environment = [];

    /** @var array<string, int> */
    private array $baseline = [];

    private ?string $sqliteFile = null;

    private string $line;

    private bool $finished = false;

    private function __construct()
    {
        $this->line = sys_get_temp_dir().'/backoffice-queue-line-'.bin2hex(random_bytes(6));
    }

    public static function start(): self
    {
        $processes = new self;

        if (DB::connection()->getDriverName() === 'sqlite') {
            $processes->sqliteFile = sys_get_temp_dir().'/backoffice-queue-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($processes->sqliteFile);

            $processes->environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $processes->sqliteFile, 'DB_URL' => ''];
            $processes->run('migrate');
        }

        $processes->baseline = $processes->run('baseline')['highest'];

        return $processes;
    }

    public function engine(): string
    {
        return $this->sqliteFile === null ? 'mysql' : 'sqlite';
    }

    /**
     * @return array<string, int>
     */
    public function baseline(): array
    {
        return $this->baseline;
    }

    /**
     * Run one operation in one process and wait for it.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(string $operation, array $arguments = []): array
    {
        $result = Process::path(base_path())
            ->env($this->environment)
            ->timeout(120)
            ->run($this->command($operation, $arguments + ['now' => self::NOW]));

        return $this->decode($operation, $result->output(), $result->errorOutput(), $result->exitCode());
    }

    /**
     * Start several processes and release them at the same instant. Each waits
     * at the starting line until all of them are ready; a job may then be held
     * back by its own "delay_ms".
     *
     * @param  list<array{0: string, 1?: array<string, mixed>}>  $jobs
     * @param  bool  $queueSqliteWrites  For a crowd of processes on SQLite: write transactions wait their turn instead of being refused. Ignored on MySQL.
     * @return list<array<string, mixed>> In the order the jobs were given.
     */
    public function together(array $jobs, bool $queueSqliteWrites = false): array
    {
        File::ensureDirectoryExists($this->line);
        File::cleanDirectory($this->line);

        $pool = Process::pool(function (Pool $pool) use ($jobs, $queueSqliteWrites): void {
            foreach ($jobs as $runner => [$operation, $arguments]) {
                $pool->as((string) $runner)
                    ->path(base_path())
                    ->env($this->environment)
                    ->timeout(120)
                    ->command($this->command($operation, ($arguments ?? []) + ['now' => self::NOW, 'line' => $this->line, 'runner' => $runner, 'queue_sqlite_writes' => $queueSqliteWrites]));
            }
        })->start();

        $deadline = microtime(true) + 60;

        while (count(File::glob($this->line.'/ready-*')) < count($jobs)) {
            if (microtime(true) > $deadline || ! $pool->running()->count()) {
                break;
            }

            usleep(1000);
        }

        // Written under another name and renamed, so no process reads half a file.
        file_put_contents($this->line.'/go.tmp', (string) (microtime(true) + 0.05));
        rename($this->line.'/go.tmp', $this->line.'/go');

        $results = $pool->wait();
        $decoded = [];

        foreach ($jobs as $runner => [$operation]) {
            $result = $results[(string) $runner];
            $decoded[] = $this->decode($operation, $result->output(), $result->errorOutput(), $result->exitCode());
        }

        return $decoded;
    }

    /**
     * Remove everything the processes committed, and the temporary database.
     *
     * @return array<string, int> Tables that still hold rows from this test: empty when clean.
     */
    public function finish(): array
    {
        if ($this->finished) {
            return [];
        }

        $this->finished = true;

        try {
            return $this->sqliteFile === null ? $this->run('cleanup', ['highest' => $this->baseline])['left'] : [];
        } finally {
            File::deleteDirectory($this->line);

            if ($this->sqliteFile !== null) {
                File::delete([$this->sqliteFile, $this->sqliteFile.'-journal', $this->sqliteFile.'-wal', $this->sqliteFile.'-shm']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function command(string $operation, array $arguments): array
    {
        return [PHP_BINARY, base_path('tests/Support/queue-process.php'), $operation, base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR))];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $operation, string $output, string $errorOutput, ?int $exitCode): array
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output))));
        $decoded = json_decode((string) end($lines), true);

        if ($exitCode !== 0 || ! is_array($decoded)) {
            throw new RuntimeException("The [{$operation}] process failed (exit {$exitCode}): ".substr($output.$errorOutput, 0, 600));
        }

        return $decoded;
    }
}
