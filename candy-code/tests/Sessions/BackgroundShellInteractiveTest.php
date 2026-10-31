<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * The interactive surface of {@see BackgroundShellRegistry}:
 * startInteractive()'s refusals and row shape, send()'s doors, the cursor/
 * read() pairing, humanInputs bookkeeping, the stop() teardown parity that
 * must reach BOTH the relay and its recorded pty child, and the corpse-FIFO
 * half of the sweep. The relay process itself is {@see PtyRelayTest}'s
 * subject; nothing here re-tests it — every pin here rides the registry's
 * own entry points.
 *
 * THIS FILE NEVER SKIPS. The host that ships the phar carries ffi/posix/
 * pcntl and a ptmx, so every live leg EXECUTES; the refusal polarity here is
 * produced by memo-injecting ProcessContainment::interactiveAvailable(),
 * never by a skip on a whim. A substrate regression turns these pins red,
 * which is the honest answer (the same all-live discipline the 6.j gates
 * ran against).
 *
 * The one static-scan pin ({@see
 * testTheInteractiveHostDoorCarriesTheThreeSubstrateScans}) is labelled
 * UNCONFIRMED-static per the program's EXEC-or-label law: it proves the
 * source carries the three function_exists clauses, not that each clause
 * fires on a stripped host (this host has all three, so those refusals
 * cannot EXEC here).
 */
final class BackgroundShellInteractiveTest extends TestCase
{
    private string $root;

    /** @var list<BackgroundShellRegistry> */
    private array $registries = [];

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/c6j-live-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        if (!\mkdir($this->root, 0o700) && !\is_dir($this->root)) {
            self::fail('the interactive test root could not be created');
        }
    }

    protected function tearDown(): void
    {
        // The launch's teardown guarantee runs FIRST: no relay or pty shell
        // of this test survives into the file sweep below (unlinking under
        // a live writer is not cleaning, and a stranded relay would pin the
        // FIFO against every later poll).
        foreach ($this->registries as $registry) {
            $registry->teardown();
        }
        $this->registries = [];
        ProcessContainment::resetInteractiveAvailabilityForTesting();

        foreach ((@\scandir($this->root) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root . '/' . $entry;
            if (\is_dir($path)) {
                foreach ((@\scandir($path) ?: []) as $inner) {
                    if ($inner !== '.' && $inner !== '..') {
                        @\unlink($path . '/' . $inner);
                    }
                }
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($this->root);
    }

    /**
     * A refused host answers ok:false with the mechanism sentence, and the
     * refusal mints NOTHING: the test root looks exactly the same before
     * and after (no launch directory, no sinks, no FIFO, no relay).
     */
    public function testRefusalWithoutThePtyMechanismMintsNothing(): void
    {
        $registry = $this->liveRegistry('c6j-refuse');
        $memo = new \ReflectionProperty(ProcessContainment::class, 'interactiveAvailableMemo');
        // Capture the host's own answer BEFORE the memo injection: the
        // post-reset assertion below must compare the re-derivation against
        // this hosts reality, never a hard-coded true (F-B r1).
        $hostCapable = ProcessContainment::interactiveAvailable();
        $before = \scandir($this->root);

        try {
            $memo->setValue(null, false);
            $answer = $registry->startInteractive('true', 'refused probe');

            self::assertFalse($answer['ok']);
            self::assertStringContainsString('pty mechanism unavailable', (string) $answer['error']);
            self::assertSame($before, \scandir($this->root), 'a refused interactive start must not mint the launch directory or any sink');
        } finally {
            ProcessContainment::resetInteractiveAvailabilityForTesting();
        }

        // The reset re-derives from the same STATs: the host's real answer
        // comes back, proving the injection was a memo ride and not a
        // permanent dowsing of the probe. Compared against the captured
        // pre-injection answer, so a pty-less host stays honest (green)
        // while the dowsing failure mode still reddens.
        self::assertSame($hostCapable, ProcessContainment::interactiveAvailable(), 'the reset must re-derive the hosts own answer');
    }

    /**
     * UNCONFIRMED-STATIC scan pin (EXEC-or-label law): the startInteractive
     * body carries all three substrate clauses (posix_mkfifo, pcntl_signal,
     * pcntl_fork) in its refusal door. This host HAS all three, so the
     * per-function refusals cannot be EXEC-proven here; the scan proves the
     * door is spelled as designed and a future narrowing of it reddens.
     */
    public function testTheInteractiveHostDoorCarriesTheThreeSubstrateScans(): void
    {
        $source = @\file_get_contents(__DIR__ . '/../../src/Background/BackgroundShellRegistry.php');
        self::assertIsString($source, 'the registry source must be readable for the scan pin');
        $door = \strpos($source, 'public function startInteractive');
        self::assertIsInt($door);
        $sendAt = \strpos($source, 'public function send(', $door);
        self::assertIsInt($sendAt, 'the scan window needs the send() boundary');
        $body = \substr($source, $door, $sendAt - $door);

        foreach (['posix_mkfifo', 'pcntl_signal', 'pcntl_fork'] as $substrate) {
            self::assertStringContainsString(
                "function_exists('" . $substrate . "')",
                $body,
                'the interactive refusal door must scan ' . $substrate,
            );
        }
    }

    /**
     * The row an interactive launch records carries its terminal shape:
     * the interactive flag, the geometry it asked for, and the pty-pid and
     * FIFO paths exactly as pathFor() derives them (one spelling for both
     * sides of the process boundary).
     */
    public function testAnInteractiveRowCarriesItsTerminalShape(): void
    {
        $registry = $this->liveRegistry('c6j-shape');
        $started = $registry->startInteractive('cat', 'shape probe', 100, 30);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $paths = BackgroundShellRegistry::pathFor(\dirname((string) $started['shell']['stdoutPath']), $id);
        $row = $registry->toArray()[$id];
        self::assertTrue($row['interactive']);
        self::assertSame(100, $row['cols']);
        self::assertSame(30, $row['rows']);
        self::assertSame($paths['ptyPid'], $row['ptyPidPath']);
        self::assertSame($paths['in'], $row['inPath']);
        self::assertNull($row['lastInputAt']);
        self::assertSame(0, $row['humanInputs']);
    }

    /**
     * The fork round trip: the row exported by toArray() merges into a
     * fresh registry over the same directory (a different process view of
     * the same launch), and typing through THAT instance reaches the
     * relay's FIFO — the token comes back in the transcript. send() and
     * stop() may legitimately run on a merged copy, so the interactive
     * fields must survive parseRow().
     */
    public function testTheRowCrossesTheForkBoundaryAndStillTakesInput(): void
    {
        $first = $this->liveRegistry('c6j-round');
        $started = $first->startInteractive('cat', 'round trip', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $second = $this->liveRegistry('c6j-round');
        $second->merge($first->toArray());
        self::assertArrayHasKey($id, $second->toArray(), 'the merged table must carry the interactive row');

        $token = \bin2hex(\random_bytes(6));
        $answer = $this->driveSend($second, $id, $token . "\x0d");
        self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));

        $stdoutPath = (string) $second->toArray()[$id]['stdoutPath'];
        self::assertTrue(
            $this->pollUntilTrue(static function () use ($stdoutPath, $token): bool {
                $seen = @\file_get_contents($stdoutPath);

                return \is_string($seen) && \str_contains($seen, $token);
            }, 10.0),
            'a send through the merged row never reached the transcript',
        );
    }

    /**
     * A plain background shell has no terminal to type into: its input
     * closed with the command, and send() says so instead of writing into
     * a FIFO that no relay is reading.
     */
    public function testSendRefusesAPlainShell(): void
    {
        $registry = $this->liveRegistry('c6j-plain');
        $started = $registry->start('sleep 40', 'plain row');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $answer = $registry->send($id, 'x', 'model');
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('not an interactive (pty) shell', (string) $answer['error']);
    }

    /**
     * Typing into a settled shell is refused — the relay is gone, the FIFO
     * has no reader, and the honest answer is "start a new one".
     */
    public function testSendRefusesASettledShell(): void
    {
        $registry = $this->liveRegistry('c6j-settled');
        $started = $registry->startInteractive('exit 0', 'settling', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        self::assertTrue(
            $this->pollUntilTrue(
                static fn (): bool => ($registry->status($id)['state'] ?? '') === 'finished',
                15.0,
            ),
            'the interactive shell never settled',
        );
        $answer = $registry->send($id, 'x', 'model');
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('has already finished', (string) $answer['error']);
    }

    /**
     * One send carries at most SEND_MAX_BYTES (4096) — a caller that
     * pastes a megabyte into a REPL gets a refusal, not a pty write of an
     * unbounded blob.
     */
    public function testSendRefusesMoreThanTheCapCarries(): void
    {
        $registry = $this->liveRegistry('c6j-cap');
        $started = $registry->startInteractive('cat', 'cap probe', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $warm = $this->driveSend($registry, $id, 'warm', 'model');
        self::assertTrue($warm['ok'], (string) ($warm['error'] ?? ''));

        $answer = $registry->send($id, \str_repeat('a', 4097), 'model');
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('a send is capped at', (string) $answer['error']);
    }

    /**
     * The visibility law answers a foreign owner with the identical
     * no-trace refusal every other call gives — the row does not exist for
     * that caller — while the rowless caller (null, the tool-internal
     * view) still sees and types into it ($caller === null is the
     * rowVisible pass-through).
     */
    public function testAForeignOwnerSeesNoTraceAndTheRowlessCallerStillTypes(): void
    {
        $registry = $this->liveRegistry('c6j-owner');
        $started = $registry->startInteractive('cat', 'owned row', 80, 24, [], 'sess-A');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $hidden = $registry->send($id, 'x', 'model', 'sess-B');
        self::assertFalse($hidden['ok']);
        self::assertStringContainsString('no background shell ' . $id . ' is tracked by this launch', (string) $hidden['error']);

        $token = \bin2hex(\random_bytes(6));
        $seen = $this->driveSend($registry, $id, $token . "\x0d");
        self::assertTrue($seen['ok'], (string) ($seen['error'] ?? ''));
    }

    /**
     * The cursor-before-write is what makes send→read race-free: the
     * answer carries the stdout size BEFORE the bytes went in, and a
     * read() threaded from that cursor (fed as a string, the shape the
     * composite cursor tokens use) grows to contain exactly what the
     * keystroke produced once the relay flushed.
     */
    public function testTheSendCursorPairsWithRead(): void
    {
        $registry = $this->liveRegistry('c6j-cursor');
        $started = $registry->startInteractive('cat', 'cursor probe', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $token = \bin2hex(\random_bytes(6));
        $answer = $this->driveSend($registry, $id, $token . "\x0d");
        self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));
        self::assertIsInt($answer['cursor']);

        $cursor = (string) $answer['cursor'];
        self::assertTrue(
            $this->pollUntilTrue(static function () use ($registry, $id, $cursor, $token): bool {
                $slice = $registry->read($id, $cursor);

                return ($slice['ok'] ?? false) === true && \str_contains((string) $slice['stdout'], $token);
            }, 10.0),
            'the bytes after the returned cursor never carried the typed token',
        );
    }

    /**
     * Human-origin sends are auditable: two of them bump humanInputs to 2
     * (a model send rides the same stamp of lastInputAt WITHOUT the
     * counter), and the clock stamp is a float — ShellOutput's
     * "the user typed N times" narration reads exactly these fields.
     */
    public function testHumanOriginInputIsCountedAndTheClockStamped(): void
    {
        $registry = $this->liveRegistry('c6j-human');
        $started = $registry->startInteractive('cat', 'human probe', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $seed = \bin2hex(\random_bytes(6));
        foreach ([['h1', 'human'], ['h2', 'human'], ['m1', 'model']] as [$tag, $origin]) {
            $answer = $this->driveSend($registry, $id, $tag . '-' . $seed . "\x0d", $origin);
            self::assertTrue($answer['ok'], (string) ($answer['error'] ?? ''));
        }

        $row = $registry->toArray()[$id];
        self::assertSame(2, $row['humanInputs']);
        self::assertIsFloat($row['lastInputAt']);
    }

    /**
     * stop() reaches BOTH processes: the relay (the row's pid) and the
     * recorded pty child from <id>.pid — the backstop rung that a
     * SIGHUP-ignoring shell makes load-bearing. Standing is proven by the
     * kernel's own view (state Z/X is down), and the whole point of the
     * interactive ladder is that neither pid survives it.
     */
    public function testStopKillsTheRelayAndTheRecordedPtyChild(): void
    {
        $registry = $this->liveRegistry('c6j-stop');
        $started = $registry->startInteractive('cat', 'stop probe', 80, 24);
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $paths = BackgroundShellRegistry::pathFor(\dirname((string) $started['shell']['stdoutPath']), $id);
        self::assertTrue(
            $this->pollUntilTrue(static fn (): bool => \is_file($paths['ptyPid']), 10.0),
            'the relay never recorded its shell pid',
        );
        $ptyPid = (int) \trim((string) @\file_get_contents($paths['ptyPid']));
        $relayPid = (int) $started['shell']['pid'];
        self::assertGreaterThan(0, $ptyPid);
        self::assertTrue(self::interactiveProcessUp($relayPid), 'the relay should be standing before the stop');
        self::assertTrue(self::interactiveProcessUp($ptyPid), 'the pty shell should be standing before the stop');

        $stopped = $registry->stop($id);
        self::assertTrue($stopped['found']);
        self::assertTrue($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed']);

        self::assertTrue(
            $this->pollUntilTrue(static function () use ($relayPid, $ptyPid): bool {
                return !self::interactiveProcessUp($relayPid) && !self::interactiveProcessUp($ptyPid);
            }, 8.0),
            'stop() left the relay or its recorded pty child standing',
        );
    }

    /**
     * The sweep learned the interactive suffixes: an aged corpse FIFO (the
     * shape only 6.j files leave behind) with its trio of plain sinks goes
     * with the next launch, exactly like .out/.err/.exit did before —
     * posix_mkfifo plants the real thing, with a plain-file fallback that
     * the aged-mtime rule treats the same.
     */
    public function testTheNextLaunchSweepsAnAgedCorpseFifoAndItsNames(): void
    {
        $registry = $this->liveRegistry('c6j-sweep');
        $directory = $this->root . '/c6j-sweep';
        self::assertTrue(\mkdir($directory, 0o700, true) || \is_dir($directory));

        $ancient = \time() - BackgroundShellRegistry::CORPSE_RETENTION_SECONDS - 600;
        $fifo = $directory . '/sh-deadbee1.in';
        if (!@\posix_mkfifo($fifo, 0o600)) {
            @\touch($fifo); // the fallback rides the same name-shaped sweep
        }
        $names = ['sh-deadbee1.out', 'sh-deadbee1.err', 'sh-deadbee1.exit'];
        foreach ($names as $name) {
            \file_put_contents($directory . '/' . $name, 'x');
        }
        foreach (\array_merge($names, ['sh-deadbee1.in']) as $corpse) {
            self::assertNotFalse(@\touch($directory . '/' . $corpse, $ancient));
        }

        $swept = $registry->start('printf sweep-proof', 'sweep run');
        self::assertTrue($swept['ok'], (string) ($swept['error'] ?? ''));
        $sweptId = (string) $swept['shell']['id'];
        self::assertTrue(
            $this->pollUntilTrue(
                static fn (): bool => ($registry->status($sweptId)['state'] ?? '') === 'finished',
                15.0,
            ),
            'the sweeping launch never finished',
        );

        foreach (['sh-deadbee1.out', 'sh-deadbee1.err', 'sh-deadbee1.exit', 'sh-deadbee1.in'] as $corpse) {
            self::assertFalse(
                \file_exists($directory . '/' . $corpse),
                'an aged corpse named ' . $corpse . ' must go with the next launch (the sweep learned in|cmd|pid)',
            );
        }
        self::assertSame('sweep-proof', (string) $registry->read($sweptId, 0)['stdout']);
    }

    /** @return BackgroundShellRegistry a launch tracked for teardown */
    private function liveRegistry(string $session): BackgroundShellRegistry
    {
        $registry = BackgroundShellRegistry::forSession($session, $this->root);
        $this->registries[] = $registry;

        return $registry;
    }

    /**
     * send() until the relay is actually reading the FIFO. The readiness
     * handle is the row's <id>.pid record: the pump writes it and opens its
     * sinks two statements apart (PtyRelay), so waiting on it closes the
     * only window where a write is real data loss — an r+ open before the
     * pump attaches succeeds against the sender's OWN fd, and the bytes die
     * when that fd closes (measured). Once the pump is attached, the
     * non-blocking write answers 'not reading input' inside its own small
     * budget — retrying on that refusal is the caller's shape; every other
     * answer (a real door) returns immediately for the pin to assert.
     *
     * @return array{ok: bool, error?: string, cursor?: int}
     */
    private function driveSend(BackgroundShellRegistry $registry, string $id, string $bytes, string $origin = 'model', ?string $owner = null): array
    {
        $row = $registry->toArray()[$id] ?? null;
        $pidPath = \is_array($row) ? ($row['ptyPidPath'] ?? null) : null;
        if (\is_string($pidPath)) {
            self::assertTrue(
                $this->pollUntilTrue(static fn (): bool => \is_file($pidPath), 12.0),
                'the relay never recorded its shell pid — no send window is safe here',
            );
        }

        $deadline = \microtime(true) + 10.0;
        $answer = $registry->send($id, $bytes, $origin, $owner);
        while (
            $answer['ok'] !== true
            && \str_contains((string) ($answer['error'] ?? ''), 'not reading input')
            && \microtime(true) < $deadline
        ) {
            \usleep(20_000);
            $answer = $registry->send($id, $bytes, $origin, $owner);
        }

        return $answer;
    }

    private function pollUntilTrue(Closure $probe, float $budgetSeconds): bool
    {
        $deadline = \microtime(true) + $budgetSeconds;
        while (\microtime(true) < $deadline) {
            if ($probe() === true) {
                return true;
            }
            \usleep(20_000);
        }

        return $probe() === true;
    }

    /** The single-letter process state from /proc's own record (null: unseen). */
    private static function procStateChar(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if (!\is_string($stat)) {
            return null;
        }
        $close = \strrpos($stat, ')');
        if ($close === false || $close + 2 >= \strlen($stat)) {
            return null;
        }

        return \substr($stat, $close + 2, 1);
    }

    /** Standing means a live kernel record: a zombie (leader not yet reaped) is down. */
    private static function interactiveProcessUp(int $pid): bool
    {
        $state = self::procStateChar($pid);

        return $state !== null && $state !== 'Z' && $state !== 'X';
    }
}
