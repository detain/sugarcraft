<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;

/**
 * The with*() and send()/expect*() clones ride the shared Mutable
 * trait's mutate(), which rebuilds via get_object_vars() — so every
 * constructor field is carried without any hand-spelled argument list
 * that a future parameter could silently drift out of. These pins hold
 * that contract: a behavioral carry across each of the eight promoted
 * fields, plus a structural count proving the hand-spelled clones are
 * gone (only the on() factory may construct from literals).
 */
final class ExpectMutateCarryTest extends TestCase
{
    public function testSendCarriesEveryConstructionField(): void
    {
        $master = self::silentMaster();
        $recorder = self::nullRecorder();
        // Pre-load three fields send() must NOT touch: buffer state is
        // whatever was already accumulated, not the echoed write.
        $loaded = new Expect(
            $master,
            buffer: 'tail',
            lastMatch: 'ok',
            before: 'pre',
            readChunk: 64,
            recorder: $recorder,
            maxBuffer: 2048,
            searchWindow: 512,
        );
        $after = $loaded->send('ls');

        self::assertSame($master, $after->master);
        self::assertSame($recorder, $after->recorder);
        self::assertSame(64, $after->readChunk);
        self::assertSame(2048, $after->maxBuffer);
        self::assertSame(512, $after->searchWindow);
        self::assertSame('tail', $after->buffer);
        self::assertSame('pre', $after->before);
        self::assertSame('ok', $after->lastMatch);
    }

    public function testFluentSettersPreserveEachOtherFields(): void
    {
        $master = self::silentMaster();
        $recorder = self::nullRecorder();
        $e = (new Expect($master, readChunk: 64, recorder: $recorder, maxBuffer: 4096))
            ->withSearchWindow(128)
            ->withMaxBuffer(8192);

        self::assertSame($master, $e->master);
        self::assertSame($recorder, $e->recorder);
        self::assertSame(64, $e->readChunk);
        self::assertSame(8192, $e->maxBuffer);
        self::assertSame(128, $e->searchWindow);
    }

    public function testHandSpelledClonesAreGoneFromExpect(): void
    {
        // Exactly one literal construction survives in CODE: the on()
        // factory. Docblock mentions of constructor spellings are
        // stripped (prose like `new self()` must stay free to teach).
        $stripped = self::codeOnly(\dirname(__DIR__, 2) . '/src/Expect.php');

        self::assertSame(1, \substr_count($stripped, 'new self('), 'Expect must clone via mutate()');
    }

    public function testPumpOptionsCarriesEveryFieldWithoutReSpelling(): void
    {
        $stripped = self::codeOnly(\dirname(__DIR__, 2) . '/src/PumpOptions.php');

        // The identity preset keeps its bare `new self()` (zero literals
        // to drift); every with* clone is a mutate() call.
        self::assertSame(1, \substr_count($stripped, 'new self('));
        self::assertSame(0, \substr_count($stripped, 'new PumpOptions('));

        $opts = (new \SugarCraft\Pty\PumpOptions(chunkBytes: 4096))
            ->withSelectTimeoutUs(1234)
            ->withFlushDeadlineSec(2.5);

        self::assertSame(4096, $opts->chunkBytes);
        self::assertSame(1234, $opts->selectTimeoutUs);
        self::assertSame(2.5, $opts->flushDeadlineSec);
    }

    /** Source text with doc-comment and line-comment carriers removed. */
    private static function codeOnly(string $file): string
    {
        $code = '';
        foreach (\token_get_all((string) \file_get_contents($file)) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_DOC_COMMENT, \T_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private static function silentMaster(): MasterPty
    {
        return new class implements MasterPty
        {
            public function read(int $len = 8192, ?float $timeout = 0.05): ?string { return null; }
            public function write(string $bytes): int { return \strlen($bytes); }
            public function resize(int $cols, int $rows): void {}
            public function size(): array { return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0]; }
            public function stream(): mixed { throw new \LogicException('no stream on this stub'); }
            public function close(): void {}
            public function isClosed(): bool { return false; }
            public function fd(): int { return -1; }
        };
    }

    private static function nullRecorder(): Recorder
    {
        return new class implements Recorder
        {
            public function recordResize(int $cols, int $rows): void {}
            public function recordInputBytes(string $bytes): void {}
            public function recordOutput(string $bytes): void {}
            public function recordQuit(): void {}
            public function close(): void {}
        };
    }
}
