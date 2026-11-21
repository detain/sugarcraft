<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

/**
 * In-process master stub for the structural tests. Records writes and
 * doles out preloaded read chunks, so the expect/timeout/EOF paths can
 * be exercised without spawning a real child.
 *
 * Lives in its own autoloadable file because ExpectTest and
 * ExpectRecorderIntegrationTest both use it. Declared inside ExpectTest.php it
 * resolved in the recorder test only when ExpectTest.php had already been
 * loaded: always under serial phpunit, which loads every test file before
 * running any, and not in a ParaTest worker, which loads one file per
 * dispatch ("Class not found"). Expect/ExpectShortWriteTest keeps its own
 * richer FixtureMaster in the Expect sub-namespace.
 */
final class FixtureMaster implements \SugarCraft\Pty\Contract\MasterPty
{
    /** @var list<string> */
    public array $writes = [];

    /**
     * @param list<string|null> $chunks  null = simulate timeout (read returns null)
     *                                   ''   = simulate EOF      (read returns '')
     *                                   str  = bytes available   (read returns the string)
     */
    public function __construct(
        private string $initial,
        private array $chunks = [],
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->initial !== '') {
            $out = $this->initial;
            $this->initial = '';
            return $out;
        }
        if ($this->chunks === []) {
            // No script left — emulate a quiet master so the
            // surrounding loop falls through to its timeout check.
            if ($timeout !== null) {
                \usleep((int) ($timeout * 1_000_000));
            }
            return null;
        }
        $chunk = \array_shift($this->chunks);
        if ($chunk === null && $timeout !== null) {
            // Honour the timeout so the deadline math in Expect makes
            // forward progress against wall-clock time.
            \usleep((int) ($timeout * 1_000_000));
        }
        return $chunk;
    }

    public function write(string $bytes): int
    {
        $this->writes[] = $bytes;
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { throw new \LogicException('FixtureMaster has no real stream'); }
    public function setBlocking(bool $blocking): void {}
    public function close(): void {}
    public function isClosed(): bool { return false; }
    public function fd(): int { return -1; }
}
