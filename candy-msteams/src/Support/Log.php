<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Support;

/**
 * Structured one-line-per-event logger riding the channel context sink.
 *
 * The bundle lives INSIDE the candy-code process, so its log law is the
 * ChannelContext contract verbatim: one pre-formatted line per event, never
 * a secret. This port keeps the bridge's JSON event/field shape (session
 * ids, seqs, key handles, byte counts, error names) and drops the bridge's
 * private file handle — the sink IS the channel log, and the channel log
 * belongs to the host. Message bodies never enter a line; callers pass
 * event names and scalar fields only. For this bundle that law extends to
 * JWTs: an inbound token never rides a field, only its claim verdicts.
 *
 * Level filtering stays here (debug lines cost a sink call otherwise); the
 * host decides where lines land.
 */
final class Log
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    /** @var \Closure(string):void */
    private readonly \Closure $sink;

    /** @param \Closure(string):void $sink */
    public function __construct(\Closure $sink, private readonly string $level = 'info')
    {
        $this->sink = $sink;
    }

    /** A logger that drops everything — the shape tests and quiet paths want. */
    public static function silent(): self
    {
        return new self(static function (string $line): void {
        });
    }

    /** @param array<string, scalar|null> $fields */
    public function info(string $event, array $fields = []): void
    {
        $this->write('info', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function warn(string $event, array $fields = []): void
    {
        $this->write('warn', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function error(string $event, array $fields = []): void
    {
        $this->write('error', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function debug(string $event, array $fields = []): void
    {
        $this->write('debug', $event, $fields);
    }

    private function write(string $level, string $event, array $fields): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->level] ?? 1)) {
            return;
        }
        $line = \json_encode(
            ['ts' => \date('c'), 'level' => $level, 'event' => $event] + $fields,
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($line === false) {
            $line = '{"level":"error","event":"log_encode_failed"}';
        }
        ($this->sink)($line);
    }
}
