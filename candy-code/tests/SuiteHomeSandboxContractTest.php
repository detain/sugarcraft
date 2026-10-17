<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * ISS-125: THE SUITE MUST NEVER WRITE THE DEVELOPER'S REAL HOME.
 *
 * The report that filed it: `~/.candy-code/exports` held 174 zero-byte
 * `session-…Z.md` files dated over twelve days — the palette sweep's two
 * `Share session` writes per idle run, landing through
 * {@see ShareCommand}::exportsDirectory()'s NULL-default arm, which resolves
 * `$HOME/.candy-code/exports` through {@see HomeDirectory::owned()}. ShareCommand
 * was the reporter, not the disease: everything home-rooted in `src/` reads
 * the same environment variable, and the suite sandboxes TMPDIR for exactly
 * this shape of reason (see the block above the pin in `tests/bootstrap.php`).
 *
 * `tests/bootstrap.php` now points HOME at `<suite tmp sandbox>/home` in both
 * spellings. This file is what asserts the pin did its job, because deleting
 * it otherwise fails as litter quietly reappearing under a real home rather
 * than as a red — the same failure shape `AuditHookProductionPathIsolationTest`
 * records for the audit pin two blocks above the one this test reads.
 *
 * THE HONEST LIMIT, stated rather than left to be rediscovered: this is a
 * sandbox-and-her-witnesses, not a whole-suite proof. Nothing here can show
 * that no test in the tree ever opened a path under the launch home — that
 * would require interposing on every writer. What is proven is the three
 * things that make the property enforceable anyway: the pin is installed and
 * live (tests below), the exact historical leak (a default `ShareCommand`)
 * lands inside the sandbox and outside the launch home (the sweep-shaped
 * regression), and SPAWNED children inherit the pin rather than reading the
 * ambient home through `bin/` (the half no in-process assertion can reach).
 * Leaks that remain after this are leaks whose reader bypassed
 * `HomeDirectory`/`$HOME` and addressed the machine by absolute path — the
 * `OneSidedHomeSandboxTest` census is that family's guard, not this file.
 *
 * ## Fixture discipline
 *
 * The needles below are built by CONCATENATION, and that is load-bearing
 * twice over. {@see OneSidedHomeSandboxTest} classifies EVERY `.php` under
 * `tests/` by regex, and a literal write spelling in this file would land it
 * on that census's migration roster (this file uses neither
 * `HomeSandboxTrait` nor a paired both-spellings write, so it cannot be
 * exempt and cannot be correct at once). And `SuiteTempSandboxContractTest`'s
 * comment above the `TMPDIR` fixtures records the same trap for that
 * variable's spellings. {@see testThisFileDoesNotAppearInTheOneSidedCensus()}
 * pins the discipline against itself.
 */
final class SuiteHomeSandboxContractTest extends TestCase
{
    /**
     * The bootstrap installed a pin, the pin is live in BOTH spellings, it
     * satisfies the ownership policy `owned()` applies to it, and it is not
     * the directory the suite was launched against.
     */
    public function testTheBootstrapPointsHomeAtAPrivateOwnedSandbox(): void
    {
        $sandbox = $GLOBALS['__candyCodeHomeSandbox'] ?? null;
        self::assertIsString($sandbox, 'tests/bootstrap.php stopped pinning HOME at all');
        self::assertSame(
            $sandbox,
            getenv('HOME'),
            'the environment spelling of HOME is not the sandbox the bootstrap published',
        );
        self::assertSame(
            $sandbox,
            $_SERVER['HOME'] ?? null,
            'the superglobal spelling of HOME does not follow the pin — half a sandbox is not a sandbox',
        );
        self::assertSame(
            $sandbox,
            HomeDirectory::owned(),
            'the sandbox does not satisfy HomeDirectory::owned(); policy readers get null instead of the pin '
                . 'and the suite takes a different code path than production',
        );

        $tempRoot = (string) ($GLOBALS['__candyCodeRealTempDir'] ?? '');
        self::assertNotSame('', $tempRoot, 'fixture: bootstrap stopped publishing the real temp root');
        self::assertStringStartsWith(
            $tempRoot . '/sc_suite_tmp_',
            (string) $sandbox,
            'the HOME sandbox escaped the per-uid temp sandbox it is built under',
        );

        self::assertDirectoryExists($sandbox);
        $perms = @fileperms($sandbox);
        self::assertIsInt($perms);
        self::assertSame(0, $perms & 0o002, 'a world-writable home fails HomeDirectory::owned() by design');

        // The pin must have MOVED something. A runner that started with no
        // HOME at all is legal (the sandbox then invents one, which is still
        // the improvement), but wherever a launch home was namable, the
        // sandbox must not be it — a pin that resolves back to the developer's
        // own home would keep every assertion above green and fix nothing.
        $launchEnv = $GLOBALS['__candyCodeLaunchHomeEnv'] ?? false;
        $launchServer = $GLOBALS['__candyCodeLaunchHomeServer'] ?? null;
        if (\is_string($launchEnv) && $launchEnv !== '') {
            self::assertNotSame(
                rtrim($launchEnv, '/'),
                rtrim((string) $sandbox, '/'),
                'the sandbox IS the launch home — the pin is decoration',
            );
        }
        if (\is_string($launchServer) && $launchServer !== '') {
            self::assertNotSame(
                rtrim($launchServer, '/'),
                rtrim((string) $sandbox, '/'),
                'the sandbox IS the launch superglobal home — the pin is decoration',
            );
        }
    }

    /**
     * THE HISTORICAL LEAK ITSELF, driven the way the sweep drives it: a
     * `ShareCommand` built with no `$exportsDir` against a bare Chat.
     *
     * Positive and negative halves, rule 15: the export must land inside the
     * sandbox (a command that wrote NOTHING anywhere would also satisfy the
     * negative), and the launch home's exports directory must not gain it.
     */
    public function testAShareCommandWithNoExportsDirWritesIntoTheSandboxAndNotTheLaunchHome(): void
    {
        $sandbox = (string) ($GLOBALS['__candyCodeHomeSandbox'] ?? '');
        self::assertNotSame('', $sandbox, 'fixture: bootstrap published no HOME sandbox');

        $exports = $sandbox . '/' . ShareCommand::EXPORTS_SUBDIR;

        $chat = new Chat([
            Message::system('You are a helpful assistant.'),
            Message::user('ISS-125 contract probe.'),
            Message::assistant('Acknowledged.'),
        ]);

        ob_start();
        $exitCode = (new ShareCommand())->execute($chat, []);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode, $output);

        // The command names its target in backticks in the transcript line;
        // parsing it is what makes the positive half name THIS file rather
        // than whatever else happens to live in a directory shared across
        // concurrent suite processes.
        self::assertSame(
            1,
            preg_match('/to `([^`]+)`/', $output, $match),
            'the export line no longer names its target, so this test cannot prove where the write landed: ' . $output,
        );
        $target = $match[1];

        self::assertStringStartsWith(
            $exports . '/',
            $target,
            'the default ShareCommand resolved its exports directory outside the HOME sandbox — ISS-125 again',
        );
        self::assertFileExists($target, 'the command reported an export it did not write');
        self::assertGreaterThan(
            0,
            (int) @filesize($target),
            'the export exists but is empty, so the reporter path is not exercised the way the sweep drives it '
                . '— keep this in step with the fixture if the serializer ever emits zero bytes',
        );

        // NEGATIVE: the same relative path under the launch home did not gain
        // the file. Deliberately not `assertDirectoryDoesNotExist` on the
        // parent: the developer's real exports tree may legitimately exist
        // (from real `/share` runs), and a concurrent suite process that
        // started BEFORE this pin existed is still entitled to its own litter —
        // what may not happen is THIS write landing THERE.
        $relative = substr($target, \strlen($exports) + 1);
        foreach ([$GLOBALS['__candyCodeLaunchHomeEnv'] ?? false, $GLOBALS['__candyCodeLaunchHomeServer'] ?? null] as $launch) {
            if (!\is_string($launch) || $launch === '') {
                continue;
            }
            self::assertFileDoesNotExist(
                rtrim($launch, '/') . '/' . ShareCommand::EXPORTS_SUBDIR . '/' . $relative,
                "a suite write reached the developer's real home ({$relative}) — this is ISS-125 again",
            );
        }
    }

    /**
     * The half an in-process assertion cannot reach: `tests/` spawns real
     * `bin/candy-code` children, and children read `HOME` from the inherited
     * ENVIRONMENT after this process set it — the same inheritance argument
     * the `TMPDIR` block makes two pins up. If the export were a
     * `$_SERVER`-only write, every claim above would still pass and every
     * spawned binary would still write the developer's home.
     */
    public function testSpawnedChildrenInheritTheSandboxHome(): void
    {
        $sandbox = (string) ($GLOBALS['__candyCodeHomeSandbox'] ?? '');
        self::assertNotSame('', $sandbox, 'fixture: bootstrap published no HOME sandbox');

        $process = proc_open(
            [PHP_BINARY, '-r', 'echo json_encode(["home" => getenv("HOME"), "server" => $_SERVER["HOME"] ?? null]);'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded, "the probe reported nothing usable:\n{$out}{$err}");
        self::assertSame(
            $sandbox,
            $decoded['home'] ?? null,
            'a child process did not inherit the sandbox HOME — spawned bin/candy-code still sees the real home',
        );
        self::assertSame(
            $sandbox,
            $decoded['server'] ?? null,
            "a child's \$_SERVER['HOME'] did not follow the pin; the environment moved but the "
                . 'startup superglobal copy still names the launch home for direct readers',
        );
    }

    /**
     * The pin's ORDERING in bootstrap's own source: the launch home must be
     * captured before the pin, and the pin must come after the temp sandbox
     * exists (it is built under it) and moves both spellings. A reorder that
     * published the capture after the redirect would silently make every
     * "not the launch home" assertion above vacuous — the captured value
     * would already BE the sandbox.
     *
     * Needle discipline: see the class doc-block. These strings are assembled
     * by concatenation so this file never matches them itself.
     */
    public function testTheBootstrapPinsHomeAfterCapturingTheLaunchHome(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/bootstrap.php');
        self::assertNotSame('', $source, 'fixture: the bootstrap could not be read, so this scan is void');

        $captureNeedle = "\$GLOBALS['__candyCodeLaunchHomeEnv'] = getenv";
        $sandboxBuildNeedle = '$sandbox = $realTempDir';
        $envPinNeedle = 'putenv' . "('HOME=' . \$homeSandbox)";
        $serverPinNeedle = '$_SERVER' . "['HOME'] = \$homeSandbox";

        $capture = strpos($source, $captureNeedle);
        $sandboxBuild = strpos($source, $sandboxBuildNeedle);
        $envPin = strpos($source, $envPinNeedle);
        $serverPin = strpos($source, $serverPinNeedle);

        self::assertIsInt($capture, 'the launch-home capture left tests/bootstrap.php');
        self::assertIsInt($sandboxBuild, 'the temp sandbox moved its build line; re-anchor this scan and the pin it orders');
        self::assertIsInt($envPin, 'the environment spelling of the HOME pin left tests/bootstrap.php');
        self::assertIsInt($serverPin, "the \$_SERVER spelling of the HOME pin left tests/bootstrap.php");

        self::assertLessThan($envPin, $capture, 'the launch home is captured after the pin — the negative assertions are vacuous');
        self::assertLessThan($serverPin, $capture, 'same, second spelling');
        self::assertLessThan($envPin, $sandboxBuild, 'the HOME pin runs before the temp sandbox it lives under exists');
    }

    /**
     * SELF-CENSUS (the fixture-discipline tripwire). `OneSidedHomeSandboxTest`
     * classifies every `.php` under `tests/` by regex and files this test
     * would join its migration roster for the same reason that guard's own
     * fixtures must stay concatenated: a literal write spelling anywhere in
     * this file is a write as far as the census is concerned, even inside a
     * needle. If someone "cleans up" a concatenation below or into a doc-block
     * example, this reds naming the census, rather than the census redening
     * with a confusing "add both spellings" message about a file that only
     * ever READS HOME.
     */
    public function testThisFileDoesNotAppearInTheOneSidedCensus(): void
    {
        $self = (string) file_get_contents(__FILE__);

        $envShape = 'putenv' . "('HOME=";
        $serverShape = '$_SERVER' . "['HOME'] =";

        self::assertStringNotContainsString(
            $envShape,
            $self,
            'this file now spells an environment write the way OneSidedHomeSandboxTest detects one; '
                . 'a needle was un-concatenated and this file landed on that census without sandboxing anything',
        );
        self::assertStringNotContainsString(
            $serverShape,
            $self,
            'this file now spells a superglobal write the way OneSidedHomeSandboxTest detects one — '
                . 'same hazard, second spelling',
        );
    }
}
