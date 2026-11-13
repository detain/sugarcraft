<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * S33 (findings 017, 131, 132, 133, 142): the bin's own prose is a
 * contract — an operator reads the header roster before the manpage
 * exists, migrates env vars from the deprecation notice, and follows
 * README citations from the comments. Every one of those three channels
 * shipped stale in some shape, so each gets a drift guard here.
 *
 * These are documentation pins: they act on SOURCE TEXT (and one bounded
 * child probe for the runtime notice), because the drift is precisely
 * "text no code consults". Where a behavior leg exists (the per-var
 * notice is printed code), it is pinned behaviorally.
 */
final class BinDocTruthTest extends TestCase
{
    private string $bin;
    private string $readme;

    protected function setUp(): void
    {
        parent::setUp();
        $bin = \realpath(__DIR__ . '/../bin/candy-git-server');
        $this->assertNotFalse($bin, 'app binary must exist next to tests/');
        $this->bin = $bin;
        $readme = __DIR__ . '/../README.md';
        $this->assertFileExists($readme);
        $this->readme = (string) \file_get_contents($readme);
    }

    /**
     * 131: the header usage block once shipped a shorter roster than the
     * dispatched commands (user list / user token / repo info / version
     * were missing). The expectation is DERIVED from the dispatch match
     * arms, so the next command added without a header line goes red
     * here, not in an operator's surprise.
     */
    public function testHeaderRosterCoversEveryDispatchedCommand(): void
    {
        $source  = (string) \file_get_contents($this->bin);
        $header  = $this->headerBlock($source);
        $top     = self::matchKeys($source, 'main');
        $userSub = self::matchKeys($source, 'cmdUser');
        $repoSub = self::matchKeys($source, 'cmdRepo');

        $this->assertNotSame([], $top, 'the top-level dispatch must be parseable — the guard itself rotted');
        $this->assertNotSame([], $userSub, 'cmdUser dispatch must be parseable');
        $this->assertNotSame([], $repoSub, 'cmdRepo dispatch must be parseable');

        $missing = [];
        foreach ($top as $command) {
            if (\preg_match('/candy-git-server ' . \preg_quote($command, '/') . '\b/', $header) !== 1) {
                $missing[] = $command;
            }
        }
        foreach (['user' => $userSub, 'repo' => $repoSub] as $group => $subs) {
            foreach ($subs as $sub) {
                // Sub-level 'help' is a usage emitter, not an operator
                // vocabulary word: the header's help line covers it.
                if ($sub === 'help') {
                    continue;
                }

                if (\preg_match('/candy-git-server ' . $group . ' ' . \preg_quote($sub, '/') . '\b/', $header) !== 1) {
                    $missing[] = "{$group} {$sub}";
                }
            }
        }

        $this->assertSame([], $missing, 'dispatched commands missing from the bin header roster (131)');
    }

    /**
     * 142: the rename-history sentence named the WRONG predecessor —
     * this binary was published as sugarcraft/candy-serve (see README
     * line 3 and the rename commit), while `soft-serve` is the upstream
     * Go project, which never renamed anything. "the binary was named
     * soft-serve" reads as a claim about upstream. Pin: the header names
     * this binary's own publication; upstream keeps its project-name
     * sentence.
     */
    public function testHeaderNamesTheBinarysOwnPublicationNotUpstreamsName(): void
    {
        $header = $this->headerBlock((string) \file_get_contents($this->bin));

        $this->assertStringContainsString('port of the charmbracelet/soft-serve Go project', $header);
        $this->assertStringContainsString('published as `candy-serve`', $header, 'the rename sentence must name THIS binary\'s former publication (142)');
        $this->assertStringNotContainsString('named `soft-serve`', $header, 'soft-serve was never this binary\'s name — that is upstream');
    }

    /**
     * 133: the legacy-env migration notice interpolated a fixed
     * CANDY_GIT_SERVER_DATA_PATH for EVERY imported CANDY_SERVE_* var,
     * so CANDY_SERVE_PORT was told to migrate to the data-path knob.
     * Bounded child probe: two different legacy vars, each successor
     * named correctly, DATA_PATH nowhere near the PORT/HOST lines.
     */
    public function testLegacyEnvNoticeNamesThePerVarSuccessor(): void
    {
        $r = $this->runBin(['version'], [
            'CANDY_SERVE_PORT' => '9999',
            'CANDY_SERVE_HOST' => 'legacy.example',
        ]);

        $this->assertSame(0, $r['rc'], 'version still succeeds under legacy env: ' . $r['err']);
        $this->assertStringContainsString(
            'CANDY_SERVE_PORT is deprecated; set CANDY_GIT_SERVER_PORT instead.',
            $r['err'],
            'the PORT notice must name the PORT successor (133)'
        );
        $this->assertStringContainsString(
            'CANDY_SERVE_HOST is deprecated; set CANDY_GIT_SERVER_HOST instead.',
            $r['err'],
            'the HOST notice must name the HOST successor (133)'
        );
        foreach (\explode("\n", $r['err']) as $line) {
            if (\str_contains($line, 'CANDY_SERVE_PORT') || \str_contains($line, 'CANDY_SERVE_HOST')) {
                $this->assertStringNotContainsString(
                    'CANDY_GIT_SERVER_DATA_PATH',
                    $line,
                    'a per-var migration must not send the operator to the data-path knob'
                );
            }
        }
    }

    /**
     * 132: the data-path comment cited a README section ("Data
     * directory") that never existed. General guard: every
     * README "Section" citation in the bin resolves to a real heading,
     * so re-pointing cannot rot into the next phantom anchor.
     */
    public function testReadmeCitationsResolveToRealSections(): void
    {
        $source = (string) \file_get_contents($this->bin);
        $this->assertGreaterThan(
            0,
            \preg_match_all('/README "([^"]+)"/', $source, $citations),
            'the bin must carry at least one README citation for this guard to mean anything'
        );
        // The 132 phantom itself: never allow it back under any spelling.
        $this->assertStringNotContainsString('README "Data directory"', $source);

        $headings = [];
        foreach (\explode("\n", $this->readme) as $line) {
            if (\preg_match('/^#{1,6} (.*)$/', $line, $m) === 1) {
                $headings[] = $m[1];
            }
        }
        $this->assertNotSame([], $headings);

        foreach ($citations[1] as $cited) {
            $resolved = false;
            foreach ($headings as $heading) {
                if (\str_contains($heading, $cited)) {
                    $resolved = true;

                    break;
                }
            }
            $this->assertTrue($resolved, "bin cites README section \"{$cited}\" that no heading answers");
        }
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /** Everything above the first statement — the usage header block. */
    private function headerBlock(string $source): string
    {
        $end = \strpos($source, 'const VERSION');
        $this->assertNotFalse($end, 'the header guard must stop at a recognizable first statement');

        return \substr($source, 0, (int) $end);
    }

    /**
     * Canonical dispatch words from the match arms of the named function.
     * Flag-shaped aliases (--help, -h) are excluded: they are spellings,
     * not extra commands.
     *
     * @return list<string>
     */
    private static function matchKeys(string $source, string $function): array
    {
        $at = \strpos($source, "function {$function}(");
        if ($at === false) {
            return [];
        }
        $window = \substr($source, $at, 4000);
        if (\preg_match('/return match \(\$\w+\) \{(.*?)\n    \};/s', $window, $m) !== 1) {
            return [];
        }

        $keys = [];
        foreach (\preg_split('/,/', $m[1]) as $chunk) {
            if (\preg_match("/'([a-z-]+)'/", $chunk, $k) === 1 && !\str_starts_with($k[1], '-')) {
                $keys[] = $k[1];
            }
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * Bounded child probe (house law: argv-array proc_open under
     * `timeout -s KILL`, explicit environment — the parent's real env
     * never leaks into legacy-var assertions).
     *
     * @param list<string>        $args
     * @param array<string, string> $extraEnv
     *
     * @return array{rc: int, out: string, err: string}
     */
    private function runBin(array $args, array $extraEnv = []): array
    {
        $env = ['PATH' => (string) \getenv('PATH'), 'LANG' => 'C'] + $extraEnv;
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $this->bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);

        return ['rc' => $rc, 'out' => $out, 'err' => $err];
    }
}
