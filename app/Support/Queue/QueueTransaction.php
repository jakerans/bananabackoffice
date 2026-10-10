<?php

namespace App\Support\Queue;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs one short piece of queue work in a database transaction, trying again
 * when the database reports a deadlock or says it is busy.
 *
 * The work must read everything it decides on inside the transaction, so a
 * second try starts from the rows as they then are. After three more tries
 * the failure is passed on, to be reported as "try again".
 */
class QueueTransaction
{
    use DetectsConcurrencyErrors;

    public const int RETRIES = 3;

    /** The longest wait before the first retry, in milliseconds. Each later one may wait twice as long. */
    private const int FIRST_WAIT = 200;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws Throwable
     */
    public function run(Closure $work): mixed
    {
        return $this->retry(fn (): mixed => DB::transaction($work));
    }

    /**
     * The same patience for a read made outside a transaction.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws Throwable
     */
    public function retry(Closure $work): mixed
    {
        for ($retry = 0; ; $retry++) {
            try {
                return $work();
            } catch (Throwable $exception) {
                if ($retry >= self::RETRIES || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                // A random wait, so callers that collided do not collide again.
                Sleep::for(random_int(1, self::FIRST_WAIT * (2 ** $retry)))->milliseconds();
            }
        }
    }

    /**
     * Runs a whole action that has its own transaction, and runs it again from
     * the start when the database reports a deadlock or says it is busy.
     *
     * For the existing actions that also end a queue hold or a cadence. They
     * change the models they were given, so before each new try `$putBack`
     * must set those models to what the database now holds: a failed try was
     * rolled back in the database, never in memory.
     *
     * Only the outermost caller may try again. Inside someone else's
     * transaction a deadlock has undone that transaction as well, so the
     * action runs once and the failure is theirs to handle.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $action
     * @param  (Closure(): void)|null  $putBack
     * @return TReturn
     *
     * @throws Throwable
     */
    public function attempt(Closure $action, ?Closure $putBack = null): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $action();
        }

        for ($retry = 0; ; $retry++) {
            try {
                return $action();
            } catch (Throwable $exception) {
                if ($retry >= self::RETRIES || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                // A random wait, so callers that collided do not collide again.
                Sleep::for(random_int(1, self::FIRST_WAIT * (2 ** $retry)))->milliseconds();

                if ($putBack !== null) {
                    $putBack();
                }
            }
        }
    }

    public function isBusy(Throwable $exception): bool
    {
        return $this->causedByConcurrencyError($exception);
    }
}
