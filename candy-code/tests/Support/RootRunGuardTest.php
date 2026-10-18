<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\RootRunGuard;

/**
 * Both polarities of the root-launch refusal — the half of `tests/bootstrap.php`'s
 * first block that NO suite run can take from the inside.
 *
 * The bootstrap's `euid === 0` arm is unreachable-by-construction here: this
 * suite pins that it refuses root, and a test that verified the refusal by
 * RUNNING as root would be the thing the refusal exists to stop. So the
 * decision was extracted to {@see RootRunGuard::refusalReason()} as a pure
 * static and is driven directly, with the launch's real inputs named:
 *
 *  - an euid of 0 without the hatch → a refusal (the arm the bootstrap can
 *    never exercise on a normal runner);
 *  - an euid of 0 WITH `CANDY_CODE_ALLOW_ROOT_TESTS=1` → null, and the
 *    strictness of that `1` pinned at both wrong-value ends (`'0'`, `'true'`
 *    — the same exact-word posture as `CANDY_CODE_MCP_DISABLE`);
 *  - an unprivileged euid → null for any temp directory EXCEPT `/`;
 *  - a temp directory of `/` → a refusal at every euid, hatch included. The
 *    hatch says "I understand uid 0"; it does not say "root my sandbox at the
 *    top level", and pinning the non-root `/` case is what keeps that a
 *    decision the code makes rather than an artefact of one branch masking
 *    another.
 *
 * The wiring of the decision INTO the bootstrap — that the call runs, runs
 * FIRST, and exits on a non-null answer — is
 * {@see \SugarCraft\Code\Tests\SuiteRootRunRefusalTest}'s subject, not this file's.
 */
final class RootRunGuardTest extends TestCase
{
    public function testARootLaunchWithoutTheHatchIsRefused(): void
    {
        $reason = RootRunGuard::refusalReason(0, '/tmp', null);

        self::assertIsString($reason, 'euid 0 with no hatch must be refused, not warned');
        self::assertStringContainsString('refuses to run as root', $reason);
        self::assertStringContainsString(RootRunGuard::ALLOW_ENV, $reason, 'the refusal must name its own escape hatch');
        self::assertNull(RootRunGuard::refusalReason(0, '/tmp', '1'), 'the documented hatch value releases the root arm');
    }

    public function testTheHatchIsTheExactWordOneAndNothingElse(): void
    {
        // The MCP_DISABLE precedent: a near-miss value is an unset value, because
        // the hatch existing to be taken DELIBERATELY means a typo must not take
        // it. Both polarities of every wrong spelling: refusal stays.
        foreach (['0', 'true', 'yes', '', '11', ' 1'] as $notTheWord) {
            self::assertIsString(
                RootRunGuard::refusalReason(0, '/tmp', $notTheWord),
                'a launch escaped the root refusal with ' . var_export($notTheWord, true),
            );
        }

        self::assertNull(RootRunGuard::refusalReason(0, '/tmp', '1'));
    }

    public function testAnUnprivilegedLaunchNeedsNoHatch(): void
    {
        self::assertNull(RootRunGuard::refusalReason(1000, '/tmp', null));
        self::assertNull(RootRunGuard::refusalReason(1000, '/tmp', '0'));
        // A platform without posix uids reads as -1, which is not 0.
        self::assertNull(RootRunGuard::refusalReason(-1, '/tmp', null));
    }

    public function testATempDirectoryOfRootIsRefusedAtEveryEuidAndTheHatchDoesNotReachIt(): void
    {
        foreach ([0, 1000, -1] as $euid) {
            $reason = RootRunGuard::refusalReason($euid, '/', null);
            self::assertIsString($reason, "TMPDIR=/ must be refused at euid {$euid}");
            self::assertStringContainsString('TMPDIR=/', $reason, 'the refusal must name the temp root, not the uid');
            self::assertIsString(
                RootRunGuard::refusalReason($euid, '/', '1'),
                "the root-tests hatch must not release the TMPDIR=/ arm (euid {$euid})",
            );
        }
    }

    public function testTheTempRootArmIsDecidedBeforeTheRootArm(): void
    {
        // A root launch with TMPDIR=/ is a TWO-hazard launch, and the message
        // that comes back must be the non-escapable one — answering the
        // escapable half would invite the operator to set the hatch and land
        // in the hazard the hatch cannot authorise.
        $reason = RootRunGuard::refusalReason(0, '/', '1');
        self::assertIsString($reason);
        self::assertStringContainsString('TMPDIR=/', $reason);

        $unhatched = RootRunGuard::refusalReason(0, '/', null);
        self::assertIsString($unhatched);
        self::assertStringStartsWith('candy-code test suite refuses to run with TMPDIR=/', $unhatched);
    }

    public function testAnEmptyTempNameIsNotATempRoot(): void
    {
        // '' is "the caller had nothing to say", which must not be dressed up
        // as the one value the temp arm refuses; the euid arm decides alone.
        self::assertNull(RootRunGuard::refusalReason(1000, '', null));
        self::assertIsString(RootRunGuard::refusalReason(0, '', null));
    }

    public function testTheLiveProcessPassesTheGuardItIsWrittenFor(): void
    {
        // The wrapper's own wiring: real getenv reads, real posix_geteuid.
        // This suite runs unprivileged against a real sandboxed TMPDIR, so the
        // honest polarity here is "answers null". A root runner WITH the hatch
        // set also answers null; what this cannot answer non-null without
        // becoming the incident is pinned by the pure static above.
        self::assertNull(RootRunGuard::suiteRefusal());
    }

    public function testTheWrapperReadsTheHatchThroughTheDocumentedName(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/RootRunGuard.php');

        self::assertSame(
            1,
            substr_count($source, "public const ALLOW_ENV = 'CANDY_CODE_ALLOW_ROOT_TESTS';"),
            'the roster name is read via the constant exactly once — a second inline spelling would drift from the docs row',
        );
        self::assertStringContainsString('getenv(self::ALLOW_ENV)', $source, 'the roster oracle only follows the getenv read, so it must exist');

        // No temp-directory resolution may sit in executable code BEFORE the
        // wrapper runs: the bootstrap's resolve-then-export ordering contract
        // owns the first sys_get_temp_dir() call, and one made at autoload or
        // in refusalReason would take it. Token positions, not substrings —
        // the doc-blocks legitimately NAME the function.
        $wrapperLine = 0;
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_STRING && $token[1] === 'suiteRefusal') {
                $wrapperLine = $token[2];

                break;
            }
        }

        self::assertGreaterThan(0, $wrapperLine, 'the wrapper itself vanished from the file this test just read');
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_STRING && $token[1] === 'sys_get_temp_dir') {
                self::assertGreaterThanOrEqual(
                    $wrapperLine,
                    $token[2],
                    "a sys_get_temp_dir() call at line {$token[2]} precedes the wrapper at line {$wrapperLine} — it would steal the bootstrap's first resolution",
                );
            }
        }
    }
}
