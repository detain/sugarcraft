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
        $productionDirectory = \sys_get_temp_dir() . '/sugar-crush-audit-'
            . (\function_exists('posix_geteuid') ? \posix_geteuid() : 'noposix');

        $before = \is_file($productionDirectory . '/audit.log')
            ? (int) \filesize($productionDirectory . '/audit.log')
            : null;

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

        // POSITIVE: the call really did record something.
        $pinned = (string) AuditHook::defaultLogDirectoryPin() . '/audit.log';
        self::assertFileExists($pinned, 'the hook wrote nothing anywhere, so the negative below is vacuous');
        self::assertStringContainsString(
            $marker,
            (string) \file_get_contents($pinned),
            'the hook wrote, but not this call, so the negative below is still vacuous',
        );

        // NEGATIVE: and none of it went to the production path.
        //
        // Deliberately NOT `assertDirectoryDoesNotExist()`. Several lanes
        // share this box and one of them may be running a suite at an older
        // commit, or a real `sugarcrush` may have run here — the production
        // directory existing is somebody else's business. What this test owns
        // is that THIS process did not add to it, so it compares the size
        // across its own call and never deletes anything it did not create.
        $after = \is_file($productionDirectory . '/audit.log')
            ? (int) \filesize($productionDirectory . '/audit.log')
            : null;

        if ($before === null) {
            self::assertNull($after, 'this suite created the production audit log');

            return;
        }

        self::assertSame($before, $after, 'this suite appended to the production audit log');
    }
}
