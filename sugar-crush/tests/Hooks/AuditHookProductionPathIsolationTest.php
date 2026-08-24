<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;

/**
 * E351: A SUITE RUN MUST NOT CREATE OR WRITE THE PRODUCTION AUDIT DIRECTORY.
 *
 * `HookManager::registerBuiltIns()` constructs `new BuiltIn\AuditHook()` with
 * no argument and several suites reach it, so a plain `vendor/bin/phpunit`
 * used to create and populate `sys_get_temp_dir() . '/sugar-crush-audit-<uid>/'`
 * on the developer's own machine — observed at round 49 with an `audit.log`
 * grown to 29165 bytes by a suite run and by no real `sugarcrush`. `AuditHookTest`
 * was rewritten at E298 specifically to stop driving writes at that path and
 * no longer does; the leak was every OTHER suite, which is why the guard that
 * existed covered the one file already behaving.
 *
 * `tests/bootstrap.php` now spends {@see AuditHook::pinDefaultLogDirectory()}
 * once, and this file is what asserts it did — deleting that line otherwise
 * fails as a file quietly appearing under the temp root rather than as a red.
 *
 * @see AuditHook::pinDefaultLogDirectory()
 */
final class AuditHookProductionPathIsolationTest extends TestCase
{
    /**
     * The bootstrap installed a pin, and it is not the production directory.
     */
    public function testTheBootstrapPinnedTheDefaultDirectoryAwayFromProduction(): void
    {
        $pin = AuditHook::defaultLogDirectoryPin();

        self::assertIsString($pin, 'tests/bootstrap.php stopped pinning the audit directory');
        self::assertSame($pin, AuditHook::defaultLogDirectory());
        self::assertStringStartsNotWith(
            \sys_get_temp_dir() . '/sugar-crush-audit-',
            $pin,
            'the pin points back at the production directory, which is the thing it exists to avoid',
        );
    }

    /**
     * THE PROPERTY ITSELF, not the wiring: an unconfigured hook driven right
     * here writes, and what it writes does NOT land in the production tree.
     *
     * The positive half matters as much as the negative (rule 15/E228).
     * "The production directory was not written" is satisfied by a hook that
     * has stopped writing anywhere at all, so the same call is asserted to
     * have produced a real record somewhere first.
     */
    public function testAnUnconfiguredHookWritesToThePinAndNotToTheProductionTree(): void
    {
        $productionLog = \sys_get_temp_dir() . '/sugar-crush-audit-'
            . (\function_exists('posix_geteuid') ? \posix_geteuid() : 'noposix')
            . '/audit.log';

        $marker = 'r49b-isolation-' . \bin2hex(\random_bytes(8));
        (new AuditHook())->execute(new HookContext(
            sessionId: $marker,
            toolName: 'probe',
            toolArgs: [],
            toolInput: '{}',
            toolOutput: 'ok',
            model: 'probe-model',
            provider: 'probe',
            projectRoot: '/',
        ));

        // POSITIVE: the call really did record something, READ BACK THROUGH
        // THE SAME READER the negative below uses (rule 25). An absence
        // asserted with a reader that answers '' for everything is not
        // evidence, and `readIfPresent()` answering '' is exactly what the
        // negative expects — so the one thing that proves the reader is alive
        // is finding this marker with it.
        $pinned = (string) AuditHook::defaultLogDirectoryPin() . '/audit.log';
        self::assertFileExists($pinned, 'the hook wrote nothing anywhere, so the negative below is vacuous');
        self::assertStringContainsString(
            $marker,
            self::readIfPresent($pinned),
            'the hook wrote, but not this call, so the negative below is still vacuous',
        );

        // NEGATIVE: and none of it went to the production path.
        //
        // Deliberately NOT `assertDirectoryDoesNotExist()`. Several lanes
        // share this box and one of them may be running a suite at an older
        // commit, or a real `sugarcrush` may have run here — the production
        // directory existing is somebody else's business, and this test never
        // deletes anything it did not create.
        //
        // AND DELIBERATELY NOT A SIZE COMPARISON EITHER, WHICH IS WHAT THIS
        // ARM DID WHEN IT LANDED. It took `filesize()` before its own call and
        // again after, and asserted the two matched. WHAT IS TRUE NOW: that is
        // the same cross-lane hazard one level down. Another lane's suite,
        // running at a commit from before the pin, appends to this exact file
        // WHILE this test is between its two reads — round 44 lost two lanes
        // to that shape over `/tmp/sc_runtime_tool_*` — and this lane then
        // goes red for a write it did not make. WHY THE PROPERTY SURVIVES THE
        // CHANGE: the question was never "did the file grow", it was "is any
        // of OUR output in it", and a marker only this process could have
        // written answers that one exactly. Nothing another writer does can
        // make it true, and nothing another writer does can make it false.
        self::assertStringNotContainsString(
            $marker,
            self::readIfPresent($productionLog),
            'this suite wrote to the production audit log at ' . $productionLog,
        );
    }

    /**
     * $path's bytes, or the empty string when nothing is there.
     *
     * The absent case is ORDINARY here and not an error: on a box where no
     * suite and no real `sugarcrush` has ever run, the production log simply
     * does not exist, and that is the outcome this file wants. The read is
     * `@`-suppressed for the same reason — a file another user owns is
     * unreadable to this process, which is again not this test's business.
     * What keeps that from becoming rule 14's silent skip is the positive
     * assertion above: the same reader has to find this call's marker in the
     * pinned log, in this same test, or the empty string below proves nothing.
     */
    private static function readIfPresent(string $path): string
    {
        if (!\is_file($path)) {
            return '';
        }

        return (string) @\file_get_contents($path);
    }
}
