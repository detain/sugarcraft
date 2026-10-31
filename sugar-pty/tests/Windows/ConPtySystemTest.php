<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Windows\ConPtyApi;
use SugarCraft\Pty\Windows\ConPtyMasterPty;
use SugarCraft\Pty\Windows\ConPtyPair;
use SugarCraft\Pty\Windows\ConPtySlavePty;
use SugarCraft\Pty\Windows\ConPtySystem;

/**
 * The system-level adapter: construction law, open() wiring, refusal paths,
 * and the spawn orchestration the slave performs on the pair's behalf.
 *
 * Everything here runs on Linux through the transcript double; the one
 * Windows-shaped claim it pins is the NEGATIVE — that a POSIX host refuses the
 * ConPTY backend naming its reason before any FFI is touched (the sibling
 * {@see ConPtyLiveTest} carries the positive, Windows-only).
 */
final class ConPtySystemTest extends TestCase
{
    public function testConstructionIsDependencyFreeEvenOnThisHost(): void
    {
        // The factory law: resolving the backend never loads FFI, so a host
        // that cannot run ConPTY still gets an instance — refusal is open()'s
        // job. On Linux this construction must be silent and total.
        $system = new ConPtySystem();

        self::assertInstanceOf(ConPtySystem::class, $system);
    }

    public function testCapabilitiesHonestAboutWhatConPTYCannotDo(): void
    {
        self::assertSame([
            'conpty' => true,
            'controllingTerminal' => false,
            'signals' => false,
            'fd' => false,
        ], (new ConPtySystem())->capabilities());
    }

    public function testOpenMintsTwoPipesThenThePseudoConsoleInOrder(): void
    {
        $api = new FakeConPtyApi();

        $pair = (new ConPtySystem($api))->open(80, 24);

        self::assertInstanceOf(ConPtyPair::class, $pair);
        self::assertInstanceOf(ConPtyMasterPty::class, $pair->master());

        $issued = $api->transcript;
        self::assertSame('createPipe(size=0 read) -> 100', $issued[0]);
        self::assertSame('createPipe(size=0 write) -> 101', $issued[1]);
        self::assertSame('createPipe(size=0 read) -> 102', $issued[2]);
        self::assertSame('createPipe(size=0 write) -> 103', $issued[3]);
        self::assertSame('createPseudoConsole(80x24) -> 104', $issued[4]);
    }

    public function testMasterFeedsTheInputWriteEndAndReadsTheOutputReadEnd(): void
    {
        // The direction law from the ConPtyApi docblock: the pseudo console's
        // input is the WRITE end of the first pipe, its output the READ end of
        // the second. Swapping either end deadlocks a session against itself,
        // and only the handle numbers in the transcript expose the mistake.
        $api = new FakeConPtyApi();

        $master = (new ConPtySystem($api))->open(80, 24)->master();

        self::assertSame(3, $master->write('abc'));
        self::assertSame(['writeFile(101,3 bytes)'], $api->callsMatching('writeFile'));

        $api->readQueue[] = 'ok';

        self::assertSame('ok', $master->read(8192, 1.0));
        self::assertSame(['peekNamedPipe(102)'], $api->callsMatching('peekNamedPipe(102)'));
    }

    public function testSlavePathNamesThePseudoConsoleHandle(): void
    {
        $api = new FakeConPtyApi();

        $pair = (new ConPtySystem($api))->open(80, 24);

        self::assertSame('conpty:104', $pair->slave()->path());
        self::assertInstanceOf(ConPtySlavePty::class, $pair->slave());
        // Mints a fresh slave per call, mirroring PosixPtyPair: the handle is
        // stable, the wrapper is not an identity.
        self::assertNotSame($pair->slave(), $pair->slave());
    }

    public function testOpenOnThisPosixHostRefusesNamingTheHostReason(): void
    {
        // Gate proof for the whole design: Kernel32ConPty::supported() checks
        // the OS family BEFORE anything FFI-shaped, so a Linux open() fails
        // with the reason in prose — and the refusal message law (point at the
        // host, never the tracker) applies.
        $system = new ConPtySystem();

        try {
            $system->open();
            self::fail('expected the POSIX host to refuse the ConPTY backend at open()');
        } catch (UnsupportedPlatformException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('ConPTY', $message);
        self::assertStringContainsString('Windows API', $message);
        self::assertStringContainsString('SUGARCRAFT_PTY_BACKEND', $message);
        self::assertStringNotContainsString('github.com', $message);
    }

    public function testFirstPipeIsRetiredWhenTheSecondPipeFails(): void
    {
        $api = $this->createMock(ConPtyApi::class);

        $pipesMade = 0;
        $api->method('createPipe')->willReturnCallback(
            static function (int $bufferSize = 0) use (&$pipesMade): array {
                if ($pipesMade === 1) {
                    throw new PtyException('injected: second createPipe fails');
                }

                $pipesMade++;

                return ['read' => 100, 'write' => 101];
            },
        );

        $closed = [];
        $api->method('closeHandle')->willReturnCallback(
            static function (int $handle) use (&$closed): bool {
                $closed[] = $handle;

                return true;
            },
        );

        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('injected: second createPipe fails');

        try {
            (new ConPtySystem($api))->open();
        } finally {
            self::assertSame([100, 101], $closed);
        }
    }

    public function testAllFourPipeEndsAreRetiredWhenTheConsoleFailsToStart(): void
    {
        // "A backend that forgets leaks one pair per session" — the contract
        // docblock names this exact failure. A createPseudoConsole refusal (the
        // real host answers ERROR_INVALID_PARAMETER on odd sizes) must still
        // close every handle minted so far.
        $api = $this->createMock(ConPtyApi::class);

        $api->method('createPipe')->willReturnOnConsecutiveCalls(
            ['read' => 100, 'write' => 101],
            ['read' => 102, 'write' => 103],
        );
        $api->method('createPseudoConsole')->willThrowException(
            new PtyException('injected: pseudo console refused'),
        );

        $closed = [];
        $api->method('closeHandle')->willReturnCallback(
            static function (int $handle) use (&$closed): bool {
                $closed[] = $handle;

                return true;
            },
        );

        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('injected: pseudo console refused');

        try {
            (new ConPtySystem($api))->open();
        } finally {
            self::assertSame([100, 101, 102, 103], $closed);
        }
    }

    public function testSpawnRefusesAControllingTerminalLoudly(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);

        $this->expectException(PtyException::class);
        $this->expectExceptionMessageMatches('/controlling terminal/');

        try {
            $pair->slave()->spawn(['cmd'], controllingTerminal: true);
        } finally {
            // The refusal happens BEFORE the job is minted — nothing leaked.
            self::assertSame([], $api->callsMatching('createJob'));
        }
    }

    public function testSpawnRequiresAtLeastOneArgvElement(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);

        $this->expectException(\InvalidArgumentException::class);

        $pair->slave()->spawn([]);
    }

    public function testSpawnAfterCloseRefuses(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);
        $pair->master()->close();

        $this->expectException(PtyException::class);
        $this->expectExceptionMessageMatches('/master is closed/');

        $pair->slave()->spawn(['cmd']);
    }

    public function testSpawnBuildsAttributeListThenProcessThenTearsListDown(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);

        $pair->slave()->spawn(['cmd', '/c', 'echo hi']);

        $order = [];

        foreach ($api->transcript as $line) {
            foreach (['createJob', 'initializeAttributeList', 'setPseudoConsoleAttribute', 'createProcess(', 'deleteAttributeList'] as $needle) {
                if (\str_starts_with($line, $needle)) {
                    $order[] = $needle;
                }
            }
        }

        self::assertSame(
            ['createJob', 'initializeAttributeList', 'setPseudoConsoleAttribute', 'createProcess(', 'deleteAttributeList'],
            $order,
        );

        $created = $api->callsMatching('createProcess(')[0];
        // Empty application name, flags = NEW_PROCESS_GROUP | NO_WINDOW, and the
        // binding's own EXTENDED_STARTUPINFO_PRESENT bit (mirrored by the fake).
        self::assertStringContainsString('flags=0x8080200', $created);
        self::assertStringStartsWith('createProcess(,', $created);
        self::assertStringContainsString('env=0', $created);
        self::assertStringNotContainsString('job=none', $created);
    }

    public function testSpawnPassesAnEnvMapThroughToTheBinding(): void
    {
        $api = new FakeConPtyApi();
        $pair = (new ConPtySystem($api))->open(80, 24);

        $pair->slave()->spawn(['cmd'], ['ONE' => '1', 'TWO' => '2']);

        self::assertStringContainsString('env=2', $api->callsMatching('createProcess(')[0]);
    }

    public function testExplicitGeometryResizesTheConsoleBeforeTheChildStarts(): void
    {
        $api = new FakeConPtyApi();
        $system = new ConPtySystem($api);

        $pair = $system->open(100, 30);
        $master = $pair->master();

        // No geometry given: the console keeps the size it opened at.
        $pair->slave()->spawn(['cmd']);
        self::assertSame(['cols' => 100, 'rows' => 30, 'xpix' => 0, 'ypix' => 0], $master->size());

        // Half geometry: the missing dimension rides the cache, never a silent 80.
        $pair->slave()->spawn(['cmd'], cols: 120);
        self::assertSame(['cols' => 120, 'rows' => 30, 'xpix' => 0, 'ypix' => 0], $master->size());

        $pair->slave()->spawn(['cmd'], rows: 40);
        self::assertSame(['cols' => 120, 'rows' => 40, 'xpix' => 0, 'ypix' => 0], $master->size());
    }

    public function testJobIsRetiredWhenCreateProcessFails(): void
    {
        $api = $this->createMock(ConPtyApi::class);

        $api->method('createPipe')->willReturnOnConsecutiveCalls(
            ['read' => 100, 'write' => 101],
            ['read' => 102, 'write' => 103],
        );
        $api->method('createPseudoConsole')->willReturn(104);
        $api->method('createJob')->willReturn(200);
        $api->method('initializeAttributeList')->willReturn(201);
        $api->method('createProcess')->willThrowException(
            new PtyException('injected: CreateProcess refused'),
        );

        $gone = [];
        $api->method('closeJob')->willReturnCallback(
            static function (int $job) use (&$gone): bool {
                $gone[] = $job;

                return true;
            },
        );

        $deleted = [];
        $api->method('deleteAttributeList')->willReturnCallback(
            static function (int $list) use (&$deleted): void {
                $deleted[] = $list;
            },
        );

        $pair = (new ConPtySystem($api))->open(80, 24);

        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('injected: CreateProcess refused');

        try {
            $pair->slave()->spawn(['cmd']);
        } finally {
            // Both the finally-delete and the catch-close ran: no orphan job,
            // no leaked attribute list.
            self::assertSame([200], $gone);
            self::assertSame([201], $deleted);
        }
    }

    public function testAdaptersReferenceNoFfiSurfaceOfTheirOwn(): void
    {
        // The layering law C-TUI-6.b minted and this step inherits: FFI lives
        // ONLY in Kernel32ConPty. An adapter that reaches for \\FFI or cdef
        // would re-open the intptr_t hazard that law closes, so no adapter
        // file may mention it in code at all (prose in docblocks is fine).
        $files = [
            'ConPtySystem', 'ConPtyPair', 'ConPtyMasterPty', 'ConPtySlavePty', 'ConPtyChild',
        ];

        foreach ($files as $name) {
            $source = \file_get_contents(\dirname(__DIR__, 2) . '/src/Windows/' . $name . '.php');
            self::assertIsString($source);

            $codeText = '';

            foreach (\token_get_all($source) as $token) {
                if (!\is_array($token)) {
                    continue; // punctuation — carries no identifier text
                }

                if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }

                $codeText .= $token[1];
            }

            self::assertSame(
                0,
                \preg_match('/ffi/i', $codeText),
                $name . '.php names the FFI surface in code outside comments and strings',
            );
            self::assertStringNotContainsString('cdef', $codeText);
        }
    }
}
