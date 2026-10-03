<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Crush\Cli\Bootstrap;

/**
 * Audit CLI-3: {@see Bootstrap}'s launch-notice cap is sized against a ROSTER
 * of the sources that raise notices, and this file is what keeps the roster
 * true.
 *
 * The cap used to be argued in prose, and the prose fell behind: by wave 9 the
 * bounded sources could raise 26 rows against a cap of 24, so a launch that
 * tripped all of them showed an "and 2 more" row in place of two real
 * warnings. Three things are checked here, all by reflection over the class:
 *
 *  - every method that reaches the transcript seam — directly, or through the
 *    `warnLaunchRows()` helper — is on the roster, so a new source cannot
 *    arrive uncounted;
 *  - every roster entry is still a method of the class, so a rename cannot
 *    leave a stale row behind;
 *  - the roster's sum is BELOW the cap, so a launch that trips every bounded
 *    source at once seats each of their rows and no overflow row.
 */
final class BootstrapLaunchNoticeCapCensusTest extends TestCase
{
    /** The two seam entry points; a method calling either raises launch rows. */
    private const SEAM_CALLS = ['self::warnPermissionConfigInTranscript(', 'self::warnLaunchRows('];

    /** The seam itself and its row helper are the plumbing, not sources. */
    private const PLUMBING = ['warnPermissionConfigInTranscript', 'warnLaunchRows'];

    public function testEveryMethodThatRaisesLaunchNoticesIsOnTheRoster(): void
    {
        $roster = self::roster();
        $missing = array_values(array_diff(self::seamCallers(), array_keys($roster)));

        self::assertSame(
            [],
            $missing,
            'a Bootstrap method raises launch notices but is not on LAUNCH_NOTICE_BOUNDED_SOURCES — '
            . 'count its rows there so LAUNCH_NOTICE_LIMIT is checked against them',
        );
    }

    public function testEveryRosterEntryIsAMethodThatStillRaisesNotices(): void
    {
        $callers = self::seamCallers();

        foreach (self::roster() as $method => $rows) {
            self::assertTrue(method_exists(Bootstrap::class, $method), "roster names no method {$method}()");
            self::assertContains($method, $callers, "{$method}() no longer raises launch notices");
            self::assertGreaterThan(0, $rows, "{$method}() is listed with no rows");
        }
    }

    public function testTheCapSeatsEveryBoundedSourceAtOnceWithRoomToSpare(): void
    {
        $limit = (new ReflectionClass(Bootstrap::class))->getConstant('LAUNCH_NOTICE_LIMIT');
        $worstCase = array_sum(self::roster());

        self::assertIsInt($limit);
        // Strictly below: at equality a full-house launch fits exactly, and the
        // next source added would overflow before anyone recounted.
        self::assertLessThan(
            $limit,
            $worstCase,
            "the bounded launch-notice sources can raise {$worstCase} rows, but the cap is {$limit}",
        );
    }

    /**
     * The roster sums to what the cap's doc-block says it does — the figure a
     * reader checks the cap against.
     */
    public function testTheRosterSumsToTheFigureTheCapDocBlockQuotes(): void
    {
        self::assertSame(35, array_sum(self::roster()));
    }

    /**
     * @return array<string, int>
     */
    private static function roster(): array
    {
        $roster = (new ReflectionClass(Bootstrap::class))->getConstant('LAUNCH_NOTICE_BOUNDED_SOURCES');
        self::assertIsArray($roster);

        return $roster;
    }

    /**
     * Every Bootstrap method whose body calls one of {@see SEAM_CALLS}.
     *
     * @return list<string>
     */
    private static function seamCallers(): array
    {
        $class = new ReflectionClass(Bootstrap::class);

        $callers = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== Bootstrap::class
                || \in_array($method->getName(), self::PLUMBING, true)
            ) {
                continue;
            }

            $body = self::codeOf($method);
            foreach (self::SEAM_CALLS as $call) {
                if (str_contains($body, $call)) {
                    $callers[] = $method->getName();
                    break;
                }
            }
        }

        self::assertNotSame([], $callers, 'the scan found no seam callers at all — the call form changed');

        return $callers;
    }

    /**
     * $method's source with comments removed, so a doc-block or a `//` line
     * QUOTING a call form is not mistaken for one. Sliced out of the file the
     * method is DECLARED in — reflection's line numbers belong to that file,
     * which for a trait method is not the class's.
     */
    private static function codeOf(ReflectionMethod $method): string
    {
        /** @var array<string, list<string>> $files read once per file, not once per method */
        static $files = [];
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = $files[$file] ??= (array) file($file);

        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $source = '<?php ' . implode('', \array_slice($lines, $start - 1, $end - $start + 1));

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $code .= $token[1];
                }
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
