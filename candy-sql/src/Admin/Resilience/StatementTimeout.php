<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Resilience;

/**
 * Enforces a wall-clock timeout on synchronous DB work.
 *
 * Uses pcntl_alarm() for timeout enforcement when available; when pcntl
 * is missing the wrapper is transparent — the work runs uncapped here
 * (callers cannot distinguish degraded mode by return value). That
 * carve-out is no longer the whole story for the remote PDO transports:
 * MysqlDatabase/PostgresDatabase additionally dial with driver-level
 * network timeouts (SQL-C11: PDO::ATTR_TIMEOUT and the MYSQL read/write
 * attrs), so a stalled server still fails — as a PDOException from the
 * transport rather than a named StatementTimeoutException — on hosts
 * without pcntl (the Windows rider).
 *
 * If the timeout fires, throws StatementTimeoutException.
 *
 * @see StatementTimeoutException
 */
final class StatementTimeout
{
    /**
     * Shared wall-clock budget for every production seam (the sync
     * run/browse legs and the admin connection wrapper). Chosen to match
     * the async transport's QueryTimeout::DEFAULT_SECONDS so one patient
     * user gesture costs the same 30 s whichever transport carries it.
     */
    public const DEFAULT_SECONDS = 30;

    private bool $timeoutOccurred = false;
    private bool $pcntlAvailable;

    /**
     * @param int $timeoutSeconds Wall-clock budget per run()/execute() call
     * @param bool|null $pcntlAvailable Pass false to force the degraded
     *                                  no-timeout path (null = auto-detect);
     *                                  lets tests exercise graceful degradation
     *                                  on hosts where pcntl is loaded. True
     *                                  cannot force-enable on a pcntl-less host.
     * @param \Closure(int, \Closure(int):void): \Closure|null $alarm
     *                                  Test-only alarm transport (SQL-P12/
     *                                  adminpages-027): given $seconds and the
     *                                  production SIGALRM handler, arm and
     *                                  return the disarm closure. When provided
     *                                  (and the alarm layer is active) run()
     *                                  routes arming through it instead of the
     *                                  pcntl_* calls — the REAL handler, throw
     *                                  and didTimeout bookkeeping are exercised
     *                                  with zero wall-clock sleeping. A test
     *                                  that asserted its own mock's throw was
     *                                  the vacuous shape this replaces.
     */
    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_SECONDS,
        ?bool $pcntlAvailable = null,
        private readonly ?\Closure $alarm = null,
    ) {
        $this->pcntlAvailable = ($pcntlAvailable ?? true)
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');

        if ($this->pcntlAvailable) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Run arbitrary synchronous work under the wall-clock budget.
     *
     * The original API could only wrap a raw PDOStatement, which matches
     * nothing in this codebase's actual shape — the synchronous remote legs
     * go through DatabaseInterface::query()/exec()/rows(), whose prepared
     * handles never surface. A callable budget is the seam those legs can
     * reach, so this class now has production consumers (SQL-C11) instead
     * of being the dead safety net its README once claimed it was.
     *
     * @template T
     * @param callable():T $work The blocking database work to bound
     * @return T whatever the work returned
     * @throws StatementTimeoutException If the budget expires mid-work
     */
    public function run(callable $work): mixed
    {
        if (!$this->pcntlAvailable) {
            // Graceful no-pcntl carve-out (Windows rider): pass the work
            // through uncapped. The transport-level network timeouts are
            // the remaining bound in this mode.
            return $work();
        }

        $this->timeoutOccurred = false;

        // Create a closure that captures the timeout seconds for this execution
        $handler = function (int $signo): void {
            if ($signo === SIGALRM) {
                throw new StatementTimeoutException(
                    'Statement execution timed out after ' . $this->timeoutSeconds . ' s',
                );
            }
        };

        $disarm = $this->arm($handler);

        try {
            return $work();
        } catch (StatementTimeoutException $e) {
            $this->timeoutOccurred = true;
            throw $e;
        } finally {
            $disarm();
        }
    }

    /**
     * Install the SIGALRM handler for one bounded execution and return the
     * closure that undoes it. Production wires pcntl_alarm()/pcntl_signal();
     * the SQL-P12 test seam swaps the transport while the handed-in handler —
     * the production one — stays the thing that throws, so the timeout leg
     * exercises the real wiring bookkeeping (arm, mid-work fire, catch,
     * didTimeout) without a sleeping process.
     *
     * @param callable(int):void $handler
     */
    private function arm(callable $handler): callable
    {
        if ($this->alarm !== null) {
            return ($this->alarm)($this->timeoutSeconds, $handler);
        }

        $previousHandler = \pcntl_signal_get_handler(SIGALRM);
        \pcntl_signal(SIGALRM, $handler);
        \pcntl_alarm($this->timeoutSeconds);

        return static function () use ($previousHandler): void {
            \pcntl_alarm(0);
            if ($previousHandler !== SIG_DFL && $previousHandler !== SIG_IGN) {
                \pcntl_signal(SIGALRM, $previousHandler);
            }
        };
    }

    /**
     * Execute a prepared statement with timeout enforcement.
     *
     * @param \PDOStatement $stmt The prepared statement to execute
     * @param list<mixed> $values Parameter values for the statement
     * @return bool True on success, false on failure (caller should check stmt->errorInfo)
     * @throws StatementTimeoutException If timeout fires during execution
     */
    public function execute(\PDOStatement $stmt, array $values = []): bool
    {
        // The statement's own bool is intentionally not propagated here —
        // the house rule is PDO::ERRMODE_EXCEPTION (errors throw), and the
        // pre-C11 contract returned true whenever execute() survived.
        $this->run(static fn (): bool => $stmt->execute($values));
        return true;
    }

    /**
     * Check if the last execution timed out.
     */
    public function didTimeout(): bool
    {
        return $this->timeoutOccurred;
    }

}
