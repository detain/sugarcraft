<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use SugarCraft\Pty\PtyException;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Process\RealProcess;
use Symfony\Component\Console\Command\Command;
use PHPUnit\Framework\TestCase;

/**
 * scriptui-08 (candy-scriptui-021): `spin` on native Windows must REFUSE
 * with a clean localized sentence and a real exit code — never the old
 * matrix lie, where the suite looked green there only because every real
 * spawn test self-skipped and the command tests injected FakeProcess
 * while the actual spawn half-failed on unopenable /dev/null stdio and
 * terminate() threw Libc::kill unconditionally.
 *
 * The Windows legs run in the existing CI matrix (candy-scriptui is in
 * scripts/affected-libs.php WINDOWS_LIBS); off-Windows they self-skip
 * honestly, and the cross-platform pins below keep the guard wired even
 * where no Windows runner exists.
 */
final class SpinWindowsRefusalTest extends TestCase
{
    private const KEY      = 'spinner.windows_unsupported';
    private const BIN      = __DIR__ . '/../../bin/candy-scriptui';
    private const BUDGET   = 30;

    public function testNativeWindowsSpawnRefusesWithLocalizedSentence(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('native Windows refusal leg — runs on Windows runners only');
        }

        try {
            RealProcess::spawn(['cmd.exe', '/c', 'echo', 'must-never-spawn']);
            self::fail('RealProcess::spawn accepted a command on native Windows');
        } catch (PtyException $e) {
            $sentence = Lang::t(self::KEY);
            self::assertSame($sentence, $e->getMessage(), 'refusal must be the localized sentence');
            self::assertNotSame(self::KEY, $e->getMessage(), 'refusal must not fall back to the raw key');
            self::assertStringNotContainsString("\n", $e->getMessage(), 'refusal must be ONE clean sentence');
        }
    }

    /**
     * The acceptance sentence for the matrix: on a Windows runner
     * `spin -- cmd` exits Command::FAILURE with exactly the localized
     * refusal on stderr, nothing on stdout, no exception frame.
     */
    public function testBinSpinRefusesCleanlyOnNativeWindows(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('native Windows refusal leg — runs on Windows runners only');
        }

        $env = self::minimalWindowsEnv();
        if ($env === null) {
            self::markTestSkipped('Windows runner lacks the SystemRoot/PATH layout this probe needs');
        }

        $pipes = [];
        $proc  = @\proc_open(
            [PHP_BINARY, self::BIN, 'spin', '--', 'cmd.exe', '/c', 'echo', 'must-never-spawn'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc, 'probe child (php bin/candy-scriptui) must start');
        fclose($pipes[0]);

        // Bounded pump: no timeout(1) on Windows, so the read ends carry
        // their own deadline — a refusal that regresses into a HANG fails
        // loudly here instead of wedging the suite.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $end    = microtime(true) + self::BUDGET;
        $open   = 2;
        while ($open > 0 && microtime(true) < $end) {
            $stdout .= (string) fread($pipes[1], 65536);
            $stderr .= (string) fread($pipes[2], 65536);
            $open = (feof($pipes[1]) ? 0 : 1) + (feof($pipes[2]) ? 0 : 1);
            if ($open > 0) {
                usleep(10_000);
            }
        }
        if ($open > 0 || proc_get_status($proc)['running']) {
            // Windows proc_terminate ignores the signal arg (TerminateProcess);
            // literal 9 keeps this path free of a hard ext-pcntl dependency.
            proc_terminate($proc, 9);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            self::fail('spin on Windows did not settle within ' . self::BUDGET . 's — refusal regressed into a hang');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        self::assertSame(
            Command::FAILURE,
            $exitCode,
            'refusal must exit with Command::FAILURE, got ' . var_export($exitCode, true) . " stderr:\n$stderr",
        );
        self::assertSame('', $stdout, 'stdout must stay clean on refusal');
        self::assertSame(
            (string) (require __DIR__ . '/../../lang/en.php')[self::KEY],
            trim($stderr),
            'stderr must carry EXACTLY the localized refusal sentence (no exception frame)',
        );
    }

    /**
     * Cross-platform pin: every shipped locale carries the refusal
     * sentence (LocaleParityTest proves shape/order; this proves the
     * key EXISTS in all 16 before the first Windows runner asks).
     */
    public function testRefusalKeyIsCarriedByEveryLocale(): void
    {
        $files = glob(__DIR__ . '/../../lang/*.php');
        self::assertGreaterThanOrEqual(16, count($files), 'the shipped locale set shrank');

        foreach ($files as $file) {
            /** @var array<string,string> $locale */
            $locale = require $file;
            $name   = basename($file, '.php');
            self::assertArrayHasKey(self::KEY, $locale, "$name lacks the refusal sentence");
            self::assertNotSame('', trim($locale[self::KEY]), "$name refusal sentence is empty");
            self::assertStringNotContainsString("\n", $locale[self::KEY], "$name refusal must be one line");
        }
    }

    /**
     * Cross-platform pin of the guard SHAPE (the Windows legs above
     * self-skip elsewhere, so the cure itself must be greppable):
     * spawn() refuses BEFORE any proc_open, and SpinCommand turns a
     * spawn-stage PtyException into a clean sentence + FAILURE rather
     * than letting the frame escape.
     */
    public function testGuardAndSurfaceAreWiredInSource(): void
    {
        $real = (string) file_get_contents(__DIR__ . '/../../src/Process/RealProcess.php');
        $refusal = strpos($real, "'" . self::KEY . "'");
        $spawnOpen = strpos($real, '@\\proc_open');
        self::assertIsInt($refusal, 'spawn() must throw the localized refusal');
        self::assertIsInt($spawnOpen, 'proc_open call site vanished');
        self::assertLessThan($spawnOpen, $refusal, 'the Windows guard must precede any spawn attempt');
        // Exact live-guard text (not a bare token search): a mutation that
        // parks the branch behind a constant-false condition drops this
        // needle while leaving both strings above findable.
        $guard = strpos($real, "if (\\DIRECTORY_SEPARATOR === '\\\\') {");
        self::assertIsInt($guard, 'the spawn() Windows guard line drifted from its pinned shape');
        self::assertLessThan($refusal, $guard + 10, 'the refusal must sit after (inside) the DIRECTORY_SEPARATOR guard');
        self::assertLessThan(1600, $refusal - $guard, 'the guard must open immediately around the refusal throw');

        $cmd = (string) file_get_contents(__DIR__ . '/../../src/Command/SpinCommand.php');
        $factory = strpos($cmd, '($this->processFactory)(');
        $catch   = strpos($cmd, 'catch (PtyException');
        self::assertIsInt($factory);
        self::assertIsInt($catch, 'SpinCommand must catch spawn-stage refusals');
        self::assertLessThan($catch, $factory, 'the catch must wrap the spawn call');
        self::assertStringContainsString('return Command::FAILURE', substr($cmd, $catch, 400));
    }

    /**
     * @return ?array<string,string> env WITHOUT any LANG/LC_* so the bin
     *         child resolves the documented 'en' fallback deterministically
     */
    private static function minimalWindowsEnv(): ?array
    {
        $need = ['SystemRoot', 'PATH', 'COMSPEC'];
        foreach ($need as $k) {
            if (getenv($k) === false) {
                return null;
            }
        }
        $env = [];
        foreach (array_merge($need, ['windir', 'TEMP', 'TMP', 'USERPROFILE']) as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        return $env;
    }
}
