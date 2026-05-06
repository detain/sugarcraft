<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\ColorProfileMsg;
use CandyCore\Core\Msg\EnvMsg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\Msg\WindowSizeMsg;
use CandyCore\Core\Cursor;
use CandyCore\Core\CursorShape;
use CandyCore\Core\MouseMode;
use CandyCore\Core\PrintMsg;
use CandyCore\Core\Progress;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Core\ProgressBarState;
use CandyCore\Core\RawMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Color;
use CandyCore\Core\View;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

/**
 * Recording model: appends each Msg it sees, optionally returns a Cmd, and
 * quits when {@see $quitAfter} messages have been processed.
 */
final class RecordingModel implements Model
{
    /** @var list<Msg> */
    public array $log = [];

    public function __construct(
        public readonly int $quitAfter = PHP_INT_MAX,
        public readonly ?\Closure $initCmd = null,
    ) {}

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->log = [...$this->log, $msg];
        $cmd = count($next->log) >= $this->quitAfter ? Cmd::quit() : null;
        return [$next, $cmd];
    }

    public function view(): string
    {
        return 'frames: ' . count($this->log);
    }
}

final class ProgramTest extends TestCase
{
    /** @return array{0:resource, 1:resource, 2:resource} input, output, inputWriter */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function makeOptions($in, $out, $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    public function testInitialWindowSizeAndQuit(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // Startup emits 3 msgs (WindowSize, Env, ColorProfile) before
        // the queued KeyMsg, so quit after the 4th.
        $model = new RecordingModel(quitAfter: 4);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->send(new KeyMsg(\CandyCore\Core\KeyType::Char, 'q'));

        // Safety net: stop the loop after 2s if our quit logic is broken.
        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();
        $this->assertInstanceOf(RecordingModel::class, $final);
        $this->assertCount(4, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class,  $final->log[0]);
        $this->assertInstanceOf(EnvMsg::class,         $final->log[1]);
        $this->assertInstanceOf(ColorProfileMsg::class, $final->log[2]);
        $this->assertInstanceOf(KeyMsg::class,         $final->log[3]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testInputStreamFeedsKeyMsg(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // 3 startup msgs + 2 input bytes = 5.
        $model = new RecordingModel(quitAfter: 5);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(0.01, static function () use ($writer) {
            fwrite($writer, "ab");
            fclose($writer);
        });
        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();

        $this->assertCount(5, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);
        $this->assertSame('a', $final->log[3]->rune);
        $this->assertSame('b', $final->log[4]->rune);

        fclose($in);
        fclose($out);
    }

    public function testInitCmdRuns(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $marker = new class implements Msg {};
        $model = new RecordingModel(
            // 3 startup msgs + the init-Cmd marker = 4.
            quitAfter: 4,
            initCmd: static fn() => $marker,
        );
        $program = new Program($model, $this->makeOptions($in, $out, $loop));

        $loop->addTimer(2.0, static fn() => $loop->stop());

        $final = $program->run();

        $this->assertCount(4, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class, $final->log[0]);
        $this->assertSame($marker, $final->log[3]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testUnicodeModeToggledAroundRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->quit();
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString(Ansi::unicodeOn(),  $written);
        $this->assertStringContainsString(Ansi::unicodeOff(), $written);
        // Enable must come before disable.
        $this->assertLessThan(
            strrpos($written, Ansi::unicodeOff()),
            strpos($written, Ansi::unicodeOn()),
        );

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testUnicodeModeCanBeDisabled(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            unicodeMode: false,
        );
        $program = new Program(new RecordingModel(quitAfter: PHP_INT_MAX), $opts);
        $program->quit();
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringNotContainsString(Ansi::unicodeOn(),  $written);
        $this->assertStringNotContainsString(Ansi::unicodeOff(), $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testRawMsgWritesBytesVerbatim(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $payload = "\x1b]0;hi\x07";
        $model = new RecordingModel(
            quitAfter: 2,
            initCmd: Cmd::raw($payload),
        );
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $final = $program->run();

        // RawMsg must NOT reach the model — it's intercepted by Program.
        foreach ($final->log as $msg) {
            $this->assertNotInstanceOf(RawMsg::class, $msg);
        }

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString($payload, $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testPrintMsgWritesLineAndDoesNotReachModel(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(
            quitAfter: 2,
            initCmd: Cmd::println('side-channel'),
        );
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $final = $program->run();

        foreach ($final->log as $msg) {
            $this->assertNotInstanceOf(PrintMsg::class, $msg);
        }

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString("side-channel\n", $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testQuitMsgStopsLoopBeforeRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->quit();

        $final = $program->run();
        // QuitMsg short-circuits before the loop, so only the 3
        // startup msgs hit update().
        $this->assertCount(3, $final->log);
        $this->assertInstanceOf(WindowSizeMsg::class,   $final->log[0]);
        $this->assertInstanceOf(EnvMsg::class,          $final->log[1]);
        $this->assertInstanceOf(ColorProfileMsg::class, $final->log[2]);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testEnvMsgCarriesProcessEnv(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // Inject something distinctive into the env so we can assert
        // it round-tripped into EnvMsg::vars.
        putenv('CANDYCORE_TEST_ENV_FLAG=42');

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->quit();
        $final = $program->run();

        putenv('CANDYCORE_TEST_ENV_FLAG'); // unset

        /** @var EnvMsg $env */
        $env = $final->log[1];
        $this->assertInstanceOf(EnvMsg::class, $env);
        $this->assertSame('42', $env->get('CANDYCORE_TEST_ENV_FLAG'));
        $this->assertNull($env->get('NOT_SET_DEFAULT_NULL'));
        $this->assertSame('fallback', $env->get('NOT_SET_DEFAULT_VALUE', 'fallback'));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testColorProfileMsgEmittedAtStartup(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $program->quit();
        $final = $program->run();

        $msg = $final->log[2];
        $this->assertInstanceOf(ColorProfileMsg::class, $msg);
        $this->assertInstanceOf(\CandyCore\Core\Util\ColorProfile::class, $msg->profile);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testViewStructEmitsTitleAndCursorEscapes(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $view = new View(
            body: 'hello',
            cursor: new Cursor(row: 5, col: 7, shape: CursorShape::Bar, blink: true),
            windowTitle: 'demo',
        );
        $model = new class($view) implements \CandyCore\Core\Model {
            public function __construct(private readonly View $v) {}
            public function init(): ?\Closure { return null; }
            public function update(\CandyCore\Core\Msg $msg): array { return [$this, null]; }
            public function view(): View { return $this->v; }
        };

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString(Ansi::setWindowTitle('demo'), $written);
        // Bar shape, blinking: DECSCUSR code 5 ("CSI 5 q").
        $this->assertStringContainsString("\x1b[5 q", $written);
        $this->assertStringContainsString(Ansi::cursorTo(5, 7), $written);
        $this->assertStringContainsString('hello', $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testViewProgressAndColorsEmitOscEscapes(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $view = new View(
            body: 'x',
            progressBar: new Progress(ProgressBarState::Normal, 33),
            foregroundColor: Color::hex('#ff0000'),
            backgroundColor: Color::hex('#00ff00'),
        );
        $model = new class($view) implements \CandyCore\Core\Model {
            public function __construct(private readonly View $v) {}
            public function init(): ?\Closure { return null; }
            public function update(\CandyCore\Core\Msg $msg): array { return [$this, null]; }
            public function view(): View { return $this->v; }
        };

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString("\x1b]9;4;1;33\x07",     $written);
        $this->assertStringContainsString("\x1b]10;rgb:ff/00/00\x07", $written);
        $this->assertStringContainsString("\x1b]11;rgb:00/ff/00\x07", $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testViewActivatesMouseFocusAndPaste(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $view = new View(
            body: 'x',
            mouseMode: MouseMode::CellMotion,
            reportFocus: true,
            bracketedPaste: true,
        );
        $model = new class($view) implements \CandyCore\Core\Model {
            public function __construct(private readonly View $v) {}
            public function init(): ?\Closure { return null; }
            public function update(\CandyCore\Core\Msg $msg): array { return [$this, null]; }
            public function view(): View { return $this->v; }
        };

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString(Ansi::mouseCellMotionOn(),   $written);
        $this->assertStringContainsString(Ansi::focusReportingOn(),    $written);
        $this->assertStringContainsString(Ansi::bracketedPasteOn(),    $written);
        // Teardown should disable everything we activated.
        $this->assertStringContainsString(Ansi::mouseCellMotionOff(),  $written);
        $this->assertStringContainsString(Ansi::focusReportingOff(),   $written);
        $this->assertStringContainsString(Ansi::bracketedPasteOff(),   $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testEnvironmentOverrideReplacesGetenv(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            environment: ['CANDYCORE_TEST_ONLY' => 'yes'],
        );
        $program = new Program(new RecordingModel(quitAfter: PHP_INT_MAX), $opts);
        $program->quit();
        $final = $program->run();

        /** @var EnvMsg $env */
        $env = $final->log[1];
        $this->assertSame('yes', $env->get('CANDYCORE_TEST_ONLY'));
        // The override REPLACES (doesn't merge with) the live env.
        $this->assertNull($env->get('PATH'));

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testWindowSizeOverrideReplacesTtyQuery(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            windowSize: ['cols' => 132, 'rows' => 50],
        );
        $program = new Program(new RecordingModel(quitAfter: PHP_INT_MAX), $opts);
        $program->quit();
        $final = $program->run();

        $size = $final->log[0];
        $this->assertInstanceOf(WindowSizeMsg::class, $size);
        $this->assertSame(132, $size->cols);
        $this->assertSame(50,  $size->rows);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testColorProfileOverrideReplacesAutoDetect(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            colorProfile: \CandyCore\Core\Util\ColorProfile::Ansi,
        );
        $program = new Program(new RecordingModel(quitAfter: PHP_INT_MAX), $opts);
        $program->quit();
        $final = $program->run();

        $msg = $final->log[2];
        $this->assertInstanceOf(ColorProfileMsg::class, $msg);
        $this->assertSame(\CandyCore\Core\Util\ColorProfile::Ansi, $msg->profile);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testViewWithNullCursorHides(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $view = new View(body: 'x', cursor: null);
        $model = new class($view) implements \CandyCore\Core\Model {
            public function __construct(private readonly View $v) {}
            public function init(): ?\Closure { return null; }
            public function update(\CandyCore\Core\Msg $msg): array { return [$this, null]; }
            public function view(): View { return $this->v; }
        };

        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false, // Don't pre-hide so we can observe the View doing it.
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString(Ansi::cursorHide(), $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testFilterDropsMsgs(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: 5);
        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
            // Drop every WindowSizeMsg (and any other Msg if you wanted).
            filter: static fn(Model $m, Msg $msg): ?Msg
                => $msg instanceof WindowSizeMsg ? null : $msg,
        );
        $program = new Program($model, $opts);
        // Inject a few extra non-WindowSize messages.
        $loop->addTimer(0.02, static fn() => $program->send(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, 'a')));
        $loop->addTimer(0.04, static fn() => $program->send(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, 'b')));
        $loop->addTimer(0.06, static fn() => $program->send(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, 'c')));
        $loop->addTimer(0.08, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $finalModel = $program->run();

        $this->assertInstanceOf(RecordingModel::class, $finalModel);
        // Filter dropped WindowSizeMsg, so the model never saw it.
        foreach ($finalModel->log as $msg) {
            $this->assertNotInstanceOf(WindowSizeMsg::class, $msg);
        }

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testWithoutRendererSkipsOutput(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: 1);
        $opts = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 240.0,
            input: $in,
            output: $out,
            loop: $loop,
            withoutRenderer: true,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.05, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());
        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        // Renderer-emitted body content should be absent.
        $this->assertStringNotContainsString('frames:', $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testSequenceCmdDispatchesInOrder(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $marker1 = new class implements Msg {};
        $marker2 = new class implements Msg {};
        $marker3 = new class implements Msg {};

        $cmd = Cmd::sequence(
            Cmd::send($marker1),
            Cmd::send($marker2),
            Cmd::send($marker3),
        );
        $model = new RecordingModel(quitAfter: 6, initCmd: $cmd);
        $opts = $this->makeOptions($in, $out, $loop);
        $program = new Program($model, $opts);
        $loop->addTimer(0.5, static fn() => $program->quit());
        $loop->addTimer(2.0, static fn() => $loop->stop());
        $finalModel = $program->run();
        $this->assertInstanceOf(RecordingModel::class, $finalModel);
        // Find the marker indices.
        $idx1 = -1; $idx2 = -1; $idx3 = -1;
        foreach ($finalModel->log as $i => $m) {
            if ($m === $marker1) $idx1 = $i;
            if ($m === $marker2) $idx2 = $i;
            if ($m === $marker3) $idx3 = $i;
        }
        $this->assertGreaterThanOrEqual(0, $idx1);
        $this->assertGreaterThan($idx1, $idx2);
        $this->assertGreaterThan($idx2, $idx3);
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testKillStopsLoopWithoutNotifyingModel(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $loop->addTimer(0.05, static fn() => $program->kill());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $final = $program->run();

        // kill() bypasses the message queue: model only sees the 3
        // startup msgs (WindowSize / Env / ColorProfile).
        $this->assertCount(3, $final->log);
        $this->assertTrue($program->wait());

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testWaitReturnsTrueAfterRun(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        // wait() before run() returns true (program not running yet).
        $this->assertTrue($program->wait());

        $program->quit();
        $program->run();
        $this->assertTrue($program->wait());

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testPrintlnConvenienceWritesAboveProgram(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $loop->addTimer(0.05, static fn() => $program->println('hello', 'world'));
        $loop->addTimer(0.10, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $final = $program->run();
        foreach ($final->log as $msg) {
            $this->assertNotInstanceOf(PrintMsg::class, $msg);
        }

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString("hello world\n", $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testPrintfConvenienceFormatsAndWrites(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $program = new Program($model, $this->makeOptions($in, $out, $loop));
        $loop->addTimer(0.05, static fn() => $program->printf('count=%d ratio=%0.2f', 42, 0.5));
        $loop->addTimer(0.10, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        $this->assertStringContainsString("count=42 ratio=0.50\n", $written);

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testReleaseAndRestoreTerminalAreReentrant(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $model = new RecordingModel(quitAfter: PHP_INT_MAX);
        $opts = new ProgramOptions(
            useAltScreen: true,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
        $program = new Program($model, $opts);
        $loop->addTimer(0.03, function () use ($program): void {
            // round-trip: release -> restore -> release -> restore
            $program->releaseTerminal();
            $program->restoreTerminal();
            $program->releaseTerminal();
            $program->restoreTerminal();
        });
        $loop->addTimer(0.08, static fn() => $program->quit());
        $loop->addTimer(2.0,  static fn() => $loop->stop());

        $program->run();

        rewind($out);
        $written = (string) stream_get_contents($out);
        // Alt-screen should have been entered + exited at least three
        // times (once at startup + once per release/restore pair).
        $this->assertGreaterThanOrEqual(3, substr_count($written, Ansi::altScreenEnter()));
        $this->assertGreaterThanOrEqual(2, substr_count($written, Ansi::altScreenLeave()));

        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
