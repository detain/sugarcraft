<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * THE tests/-side SwallowingCatchCensusTest polices test files: a catch wide
 * enough to eat PHPUnit's own failures, wrapped around an asserting try, is a
 * test that passes while asserting nothing. Its scope line walks `tests/` only —
 * production code was always outside it. This file is the src-side counterpart
 * for the NARROWER, production shape of the same disease: a catch clause in
 * candy-code/src whose body contains NOTHING — no statement, not even a comment.
 *
 * A bare-empty catch is not automatically a bug. Every one of the eleven sites
 * below is defensible on inspection (teardown close, best-effort listener,
 * rethrow-after-rollback, fail-open probe ladder — each carries its one-line
 * justification on its roster row). What is NOT acceptable is for the tree to
 * hold eleven such sites with no census, because the shape's whole danger is
 * that it is silent by construction: the twelfth one, planted by the next
 * lane in a hot path, will be invisible until the exception it ate matters.
 *
 * POLITY (mirrors the house site-keyed roster shape; keys are
 * `<relative-file>::<enclosing-method>` with a per-key count, because three of
 * the eleven share CapabilityDiscoverer::probe):
 *
 *   - a bare-empty catch NOT on the roster → RED, named (fail closed: growth
 *     is exactly what this guard is for);
 *   - a roster row whose site no longer swallows silently → RED with a
 *     "delete the row" message (a roster that keeps licences for repaired code
 *     is a lie; rule 33: exemption rows are queued repair work, not trophies);
 *   - a roster key whose live count differs from its row count → RED (splitting
 *     or copy-pasting a rostered method changes the count consciously or not;
 *     either way the census demands the row be re-derived in the same commit).
 *
 * THE SOFTER TIER, deliberately NOT rostered: catch bodies that hold ONLY a
 * comment ("// ignore", "// best effort"). Sixty-seven of them at mint time.
 * Each already self-justifies in prose; a per-file roster would be 67 rows of
 * noise punishing the very act of documenting a swallow. The soft tier is
 * policed only by an ADVISORY LOWER BOUND below — if that count collapses, the
 * scanner has gone blind (tokenizer-shape change, relocated source root) and
 * the hard roster arm rides the same walk, so it would be silently vacuous too.
 * Growth of the soft tier stays silent by design.
 *
 * The scanner is ONE code path shared by the tree walk and the both-polarities
 * fixtures (house anti-vacuity discipline: a "zero findings" claim is not
 * evidence unless the same eyes demonstrably see a planted finding).
 *
 * Method attribution is textual (nearest preceding function declaration, closing
 * up through anonymous functions to their enclosing named method). A catch that
 * sits outside every method keys to `<top>` — none exists today, and the
 * bidirectional roster would force a conscious row for one.
 */
final class SwallowingCatchSrcCensusTest extends TestCase
{
    /**
     * The eleven live sites, each re-verified against the tree at mint time
     * (line numbers drift; file plus method identity is the durable key). The
     * §ROSTER of the source inventory carried file:line only; method names here
     * are the scanner's own attribution, checked against the file layouts.
     *
     * @var array<string, array{0: int, 1: string}> site => [count, justification]
     */
    private const BARE_EMPTY_CATCH_ROSTER = [
        'src/Agents/SuspendedDelegations.php::forget' => [
            1,
            'unlink of a verified-context artifact already wrapped in @; an undeletable temp file is not a forget failure',
        ],
        'src/Cli/Bootstrap.php::mcpClient' => [
            1,
            'a trust-pin record that failed to write costs only a re-record on the next launch (its own comment above the try says so)',
        ],
        'src/Cli/Bootstrap.php::sessionStore' => [
            1,
            'session-artifact pruning is best-effort housekeeping at store construction time',
        ],
        'src/Host/RemoteSessionHost.php::gone' => [
            1,
            'teardown listeners run best-effort when the host goes away; one listener failing must not strand the stream close that follows',
        ],
        'src/MCP/SseMcpServer.php::assertSuccess' => [
            1,
            'close() on a response body that was consumed or read-errored already; the status verdict is taken from the response object, not the stream',
        ],
        'src/Media/Capability/CapabilityDiscoverer.php::probe' => [
            3,
            'fail-open dial ladder: a refused or unreachable endpoint family is absence of capability, not an error (three rungs: comfyui, sglang, openai-images)',
        ],
        'src/Memory/EmbeddingCache.php::store' => [
            1,
            'ROLLBACK after a failed COMMIT must not replace the original error, which is handed to discardIfDamaged() one statement later',
        ],
        'src/Server/Workspace/WorkspaceHostClient.php::exited' => [
            1,
            'exit listeners run best-effort when the host reports exit; identical polity to the gone row above',
        ],
        'src/Session/SessionStore.php::immediateTransaction' => [
            1,
            'swallows the ROLLBACK "no transaction is active" so the original PDOException is the one rethrown on the next line',
        ],
    ];

    /**
     * Advisory floor for the comment-only tier, measured at mint time. A real
     * count BELOW it means the scanner stopped seeing bodies, never that the
     * tree improved; growth above it is silent by design.
     */
    private const COMMENT_ONLY_TIER_FLOOR = 67;

    public function testTheBareEmptySrcCatchesAreExactlyTheRosterRows(): void
    {
        $live = self::bareCountsBySite(self::scanTree());
        $unrecorded = [];
        foreach ($live as $site => $count) {
            if (!isset(self::BARE_EMPTY_CATCH_ROSTER[$site])) {
                $unrecorded[] = $site.' ('.$count.' bare-empty catch'.($count === 1 ? '' : 'es').')';
            }
        }

        $stale = [];
        foreach (self::BARE_EMPTY_CATCH_ROSTER as $site => [$expected, $justification]) {
            $actual = $live[$site] ?? 0;
            if ($actual === 0) {
                $stale[] = $site.' (no longer swallowing silently - delete the row)';
            } elseif ($actual !== $expected) {
                $stale[] = $site.' (roster row carries '.$expected.', the tree holds '.$actual.' - re-derive the row)';
            }
        }

        $this->assertSame(
            [],
            $unrecorded,
            "bare-empty catches outside the roster - roster a row with a one-line justification, or fix the ".
            'swallow; existing rows look like \'src/Session/SessionStore.php::immediateTransaction\' => [1, ...]: '
            .implode('; ', $unrecorded)
        );
        $this->assertSame(
            [],
            $stale,
            'roster rows that no longer match the tree - the site stopped swallowing silently (delete the row) '
            .'or changed count (re-derive it in the same commit): '.implode('; ', $stale)
        );
    }

    /**
     * The exact catch shapes the tree contains, pushed through the SAME scanner
     * the tree walk uses: bare-empty seen — as a sole clause, as the SECOND
     * clause of a multi-catch chain, and inside an anonymous function (where the
     * key climbs to the enclosing named method) — and the near-misses NOT seen:
     * statement body, comment-only body, comment-then-statement body. A finally
     * block is not a catch at all.
     */
    public function testTheScannerSeesTheShapeInBothPolarities(): void
    {
        $bare = <<<'PHP'
            <?php
            class Probe {
                public function outer(): void {
                    $handler = function (): void {
                        try { $this->inner(); } catch (\Throwable) {
                        }
                    };
                    try {
                        $handler();
                    } catch (\LogicException $e) {
                        $this->log($e);
                    } catch (\Throwable) {
                    }
                }
                public function inner(): void {}
                private function log(\Throwable $e): void {}
            }
            PHP;
        $this->assertSame(
            ['probe::outer' => 2],
            self::bareCountsBySite(self::scanSource($bare, 'probe')),
            'the scanner missed a bare-empty catch (inside a closure, or the second clause of a chain)'
        );
        $this->assertSame(
            0,
            self::commentCountsBySite(self::scanSource($bare, 'probe'))['probe::outer'] ?? 0,
            'a body holding only whitespace is not the comment-only tier'
        );

        $notBare = <<<'PHP'
            <?php
            class Probe {
                public function outer(): void {
                    try { one(); } catch (\Throwable) { $this->seen = true; }
                    try { two(); } catch (\Throwable) {
                        // self-justified in prose
                    }
                    try { three(); } catch (\Throwable) {
                        // comment first,
                        $this->seen = true; // then code
                    } finally {
                        $this->seen = true;
                    }
                }
            }
            PHP;
        $this->assertSame(
            [],
            self::bareCountsBySite(self::scanSource($notBare, 'probe')),
            'the scanner accused a catch that has a statement, a comment, or both'
        );
        $this->assertSame(
            1,
            self::commentCountsBySite(self::scanSource($notBare, 'probe'))['probe::outer'] ?? 0,
            'the comment-only tier is not being counted, so its floor arm is blind'
        );
    }

    public function testTheCommentOnlyTierKeepsItsAdvisoryFloor(): void
    {
        $commentOnly = array_sum(self::commentCountsBySite(self::scanTree()));
        $this->assertGreaterThanOrEqual(
            self::COMMENT_ONLY_TIER_FLOOR,
            $commentOnly,
            'the comment-only tier holds fewer bodies than at mint time - the scanner has gone blind to a '
            .'shape it used to see, and the hard roster arm rides this same walk'
        );
    }

    /**
     * @param array<string, array{0: int, 1: int}> $counts
     *
     * @return array<string, int>
     */
    private static function bareCountsBySite(array $counts): array
    {
        return array_filter(array_map(static fn(array $pair): int => $pair[0], $counts));
    }

    /**
     * @param array<string, array{0: int, 1: int}> $counts
     *
     * @return array<string, int>
     */
    private static function commentCountsBySite(array $counts): array
    {
        return array_filter(array_map(static fn(array $pair): int => $pair[1], $counts));
    }

    /**
     * Every PHP file under candy-code/src, walked by ONE scan.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function scanTree(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        if (!is_dir($root)) {
            throw new AssertionFailedError('the census lost its subject: '.$root.' is not a directory');
        }
        $files = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($walker as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $tree = [];
        foreach ($files as $path) {
            $source = @file_get_contents($path);
            if ($source === false) {
                throw new AssertionFailedError('unreadable source file in the walked tree: '.$path);
            }
            $label = 'src/'.substr($path, strlen($root) + 1);
            foreach (self::scanSource($source, $label) as $site => $pair) {
                $tree[$site][0] = ($tree[$site][0] ?? 0) + $pair[0];
                $tree[$site][1] = ($tree[$site][1] ?? 0) + $pair[1];
            }
        }

        return $tree;
    }

    /**
     * The scan itself: every catch clause in one source string, classified into
     * bare-empty / comment-only / has-code, tallied per enclosing named method.
     * Unclosed constructs fail LOUD - a walk that gives up quietly would report
     * the tree as clean precisely when parsing broke.
     *
     * @return array<string, array{0: int, 1: int}> site => [bare-empty, comment-only]
     */
    private static function scanSource(string $source, string $label): array
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        // Declaration walk: remember every T_FUNCTION by index with its OWN name
        // ('{closure}' for anonymous ones). Attribution of any later token uses
        // the nearest preceding declaration, closing up through anonymous
        // functions to the last named one before them.
        $declaredAt = [];
        for ($k = 0; $k < $count; $k++) {
            if (!$tokens[$k]->is(T_FUNCTION)) {
                continue;
            }
            $name = '{closure}';
            for ($m = $k + 1; $m < $count; $m++) {
                if ($tokens[$m]->is(T_WHITESPACE)) {
                    continue;
                }
                if ($tokens[$m]->is(T_STRING)) {
                    $name = $tokens[$m]->text;
                }
                break;
            }
            $declaredAt[] = [$k, $name];
        }
        $methodFor = static function (int $index) use ($declaredAt): string {
            $named = '<top>';
            foreach ($declaredAt as [$at, $name]) {
                if ($at >= $index) {
                    break;
                }
                if ($name !== '{closure}') {
                    $named = $name;
                }
            }

            return $named;
        };

        $out = [];
        for ($k = 0; $k < $count; $k++) {
            if (!$tokens[$k]->is(T_CATCH)) {
                continue;
            }
            // Past the catch header (balanced parentheses), to the body brace.
            $depth = 0;
            $j = $k;
            for (; $j < $count; $j++) {
                $text = $tokens[$j]->text;
                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            while ($j < $count && $tokens[$j]->text !== '{') {
                $j++;
            }
            if ($j >= $count) {
                throw new AssertionFailedError('catch without a body brace in '.$label.' at line '.$tokens[$k]->line);
            }
            $open = $j;
            $depth = 0;
            $close = null;
            for (; $j < $count; $j++) {
                $text = $tokens[$j]->text;
                // String-interpolation openers are array tokens; `${` never
                // equals the bare `{`, and both close with a bare `}`.
                if ($text === '{' || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $close = $j;
                        break;
                    }
                }
            }
            if ($close === null) {
                throw new AssertionFailedError('unbalanced catch body in '.$label.' at line '.$tokens[$k]->line);
            }

            $hasComment = false;
            $hasCode = false;
            for ($b = $open + 1; $b < $close; $b++) {
                if ($tokens[$b]->is([T_WHITESPACE])) {
                    continue;
                }
                if ($tokens[$b]->is([T_COMMENT, T_DOC_COMMENT])) {
                    $hasComment = true;
                    continue;
                }
                $hasCode = true;
            }

            $site = $label.'::'.$methodFor($k);
            $out[$site][0] = ($out[$site][0] ?? 0) + (!$hasCode && !$hasComment ? 1 : 0);
            $out[$site][1] = ($out[$site][1] ?? 0) + (!$hasCode && $hasComment ? 1 : 0);
        }

        return $out;
    }
}
