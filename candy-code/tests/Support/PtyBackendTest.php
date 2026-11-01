<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\PtyBackend;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Posix\PosixMasterPty;
use SugarCraft\Pty\Posix\PosixPtyPair;
use SugarCraft\Pty\Posix\PosixSlavePty;

/**
 * C-TUI-6.d: the backend seam that replaced the two direct calls to the
 * deprecated POSIX-only facade. The pair the driver loop and the Bash-
 * interactive capture now speak to comes from here, so two things must be
 * pinned: that on a POSIX host the answer is the SAME canonical PosixPtySystem
 * path the facade always used (behavior parity — this leg runs for real on
 * every Linux box, like the PtyRun tool tests it feeds), and that the seam
 * really delegates to the factory rather than re-hardwiring posix itself
 * (pinned by the override arm: an environment the factory reads and
 * posix-hardwiring could not see).
 *
 * The Windows answer (a ConPtyPair) cannot be produced on this box —
 * Kernel32ConPty::supported() refuses on the OS leg alone — and the system is
 * never forced here; the ConPTY pair types are pinned by the sugar-pty suite
 * (C-TUI-6.b/6.c). What IS pinned here is the contract surface the migrated
 * loops consume from whichever pair arrives — the same surface both systems
 * implement.
 */
final class PtyBackendTest extends TestCase
{
    private const BACKEND_ENV = 'SUGARCRAFT_PTY_BACKEND';

    protected function tearDown(): void
    {
        \putenv(self::BACKEND_ENV);

        parent::tearDown();
    }

    public function testThePosixFactoryAnswerIsALiveCanonicalPair(): void
    {
        $pair = PtyBackend::open(100, 30);

        try {
            self::assertInstanceOf(PosixPtyPair::class, $pair, 'on a POSIX host the factory must answer the same system the facade used');
            self::assertInstanceOf(PosixMasterPty::class, $pair->master());
            self::assertInstanceOf(PosixSlavePty::class, $pair->slave());

            $size = $pair->master()->size();
            self::assertSame(100, $size['cols']);
            self::assertSame(30, $size['rows']);
        } finally {
            $pair->master()->close();
        }

        self::assertTrue($pair->master()->isClosed(), 'close is the contract teardown');
    }

    public function testAnInjectedSystemAnswersThePairItHandsBack(): void
    {
        $pair = new class () implements PtyPair {
            public function master(): MasterPty
            {
                return new FakeMasterPty();
            }

            public function slave(): SlavePty
            {
                return new class () implements SlavePty {
                    public function path(): string
                    {
                        return '/dev/pts/fake-' . \uniqid('', true);
                    }

                    public function spawn(array $cmd, ?array $env = null, int $cols = 80, int $rows = 24, bool $controllingTerminal = false): Child
                    {
                        throw new \LogicException('the injected pair is never spawned from');
                    }
                };
            }
        };
        $system = new class ($pair) implements PtySystem {
            /** @var array{0: int, 1: int}|null */
            public ?array $requested = null;

            public function __construct(private readonly PtyPair $pair)
            {
            }

            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                $this->requested = [$cols, $rows];

                return $this->pair;
            }

            public function capabilities(): array
            {
                return [];
            }
        };

        $returned = PtyBackend::open(132, 43, $system);

        self::assertSame($pair, $returned, 'the seam returns the system answer untouched');
        self::assertSame([132, 43], $system->requested, 'geometry rides the open call');
    }

    public function testTheOverrideEnvProvesTheFactoryPathIsTaken(): void
    {
        // A hand-wired posix opener would ignore this env and happily allocate
        // a POSIX pair; the real factory refuses the conpty override on a host
        // whose ConPTY probe answers "this host is Linux". The refusal is the
        // proof the delegation is live — and it lands BEFORE any allocation,
        // so this test never touches a terminal at all.
        \putenv(self::BACKEND_ENV . '=conpty');

        $this->expectException(UnsupportedPlatformException::class);

        PtyBackend::open();
    }

    public function testAnUnknownOverrideIsRefusedAsNoise(): void
    {
        \putenv(self::BACKEND_ENV . '=not-a-backend-' . \uniqid('', true));

        $this->expectException(\InvalidArgumentException::class);

        PtyBackend::open();
    }

    public function testTheContractSurfaceTheDriverLoopConsumesIsThePairMaster(): void
    {
        // The migrated loops use exactly read(int, ?float): ?string,
        // write(string): int and close() on the master handle. Pinning the
        // surface on a scripted double keeps the driver honest even where no
        // real terminal can be allocated (the Windows ConPTY master carries
        // the SAME contract — C-TUI-6.c — and no PHP stream).
        $master = new FakeMasterPty();

        self::assertSame('chunk', $master->read(8192, 0.05));
        self::assertSame(4, $master->write("ab\nx"));
        self::assertSame(80, $master->size()['cols']);

        $master->close();
        self::assertTrue($master->isClosed());
    }
}

/**
 * The one named double: three of the four legs construct it, and anonymous
 * copies would drift the moment the MasterPty contract grows.
 *
 * @internal
 */
final class FakeMasterPty implements MasterPty
{
    private bool $closed = false;

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        return 'chunk';
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void
    {
    }

    /**
     * @return array{cols: int, rows: int, xpix: int, ypix: int}
     */
    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }

    public function stream(): mixed
    {
        return null;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function fd(): int
    {
        throw new \LogicException('no POSIX fd behind a fake');
    }
}
