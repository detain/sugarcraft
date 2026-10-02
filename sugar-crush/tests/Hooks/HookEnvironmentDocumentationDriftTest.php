<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;

/**
 * Audit F-E1, the documentation half: docs/HOOKS.md's "what a hook actually
 * sees" table and its `env | sort` listing are RE-MEASURED here, not
 * proof-read.
 *
 * The page promised 8-9 variables (`CRUSH_*` + `PWD`) and no `PATH` long after
 * the hook had started inheriting the whole launch environment; the audit
 * counted 129 lines, provider keys included. A count that depends on the
 * operator's shell cannot be pinned against the test runner's own
 * environment, so the page fixes the launch environment by name ("launched
 * under `env -i` with exactly …") and this test reproduces exactly that: it
 * reads those names off the page, starts a child PHP under `env -i` with each
 * set to a fake value, runs a real {@see \SugarCraft\Crush\Hooks\ScriptHook}
 * with `env | sort` twice (empty and non-empty `toolOutput`), and compares the
 * two outputs with the table's line counts and the listing's names and fixed
 * values. {@see \SugarCraft\Crush\Tests\Config\DocFigureProseDriftTest} keeps
 * the arithmetic between the table, the listing and the code constants.
 */
final class HookEnvironmentDocumentationDriftTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hook_env_doc_' . uniqid((string) getmypid(), true);
        mkdir($this->dir . '/project', 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/measure.php');
        @rmdir($this->dir . '/project');
        @rmdir($this->dir);
    }

    public function testTheMeasuredTableAndListingMatchALiveRun(): void
    {
        if (!is_executable('/usr/bin/env')) {
            self::markTestSkipped('the documented measurement needs env(1) at /usr/bin/env');
        }

        $doc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');

        self::assertSame(
            1,
            preg_match('/launched under `env -i` with\s+exactly ((?:`[A-Z_]+`,?\s+(?:and\s+)?)+)set/', $doc, $m),
            'HOOKS.md no longer names the exact launch environment its table was measured under',
        );
        preg_match_all('/`([A-Z_]+)`/', $m[1], $launchNames);
        $launch = [];
        foreach ($launchNames[1] as $name) {
            $launch[$name] = match ($name) {
                'PATH' => (string) getenv('PATH'),
                'HOME' => $this->dir,
                default => 'fake-' . strtolower($name),
            };
        }
        self::assertArrayHasKey('PATH', $launch, 'the measured launch environment must carry PATH or the child cannot find env(1)');

        self::assertSame(1, preg_match("/\\| `''` \\(empty\\) \\| \\*\\*(\\d+)\\*\\* \\|/", $doc, $emptyRow), 'the empty-toolOutput row moved');
        self::assertSame(1, preg_match("/\\| `'RESULT-TEXT'` \\| \\*\\*(\\d+)\\*\\* \\|/", $doc, $fullRow), 'the full-payload row moved');
        self::assertSame(
            1,
            preg_match('/```\n((?:[A-Z_]+=[^\n]*\n)+)```/', $doc, $listing, 0, (int) strpos($doc, "| `'RESULT-TEXT'` |")),
            'the env listing no longer follows the table',
        );
        preg_match_all('/^([A-Z_]+)=(.*)$/m', $listing[1], $listed, PREG_SET_ORDER);

        [$emptyRun, $fullRun] = $this->measure($launch);

        self::assertCount((int) $emptyRow[1], $emptyRun, "the empty-toolOutput run no longer prints the table's line count:\n" . implode("\n", array_keys($emptyRun)));
        self::assertCount((int) $fullRow[1], $fullRun, "the full-payload run no longer prints the table's line count:\n" . implode("\n", array_keys($fullRun)));
        self::assertSame(array_column($listed, 1), array_keys($fullRun), 'the listing no longer names what a live hook sees, in env | sort order');

        foreach ($listed as [, $name, $value]) {
            if (!str_contains($value, '…')) {
                self::assertSame($value, $fullRun[$name], "the listing's value for {$name} is not what a live hook sees");
            }
        }

        foreach ($launch as $name => $value) {
            if (!array_key_exists($name, $fullRun)) {
                self::assertStringContainsString('`' . $name . '`', $this->paragraphAfterListing($doc), "{$name} was dropped but the page does not say why");
            }
        }
    }

    /**
     * The sentence under the listing that accounts for every launch variable
     * the hook does not see.
     */
    private function paragraphAfterListing(string $doc): string
    {
        $start = (int) strpos($doc, 'The three inherited lines are');
        self::assertGreaterThan(0, $start, 'the paragraph naming the inherited and dropped lines moved');

        return substr($doc, $start, (int) strpos($doc, "\n\n", $start) - $start);
    }

    /**
     * Run the documented probe in a child PHP under `env -i`, once per
     * `toolOutput`, and return each `env | sort` output as name => value.
     *
     * @param array<string, string> $launch
     * @return array{array<string, string>, array<string, string>}
     */
    private function measure(array $launch): array
    {
        $script = $this->dir . '/measure.php';
        file_put_contents($script, sprintf(
            <<<'PHP'
            <?php
            declare(strict_types=1);
            require %s;
            use SugarCraft\Crush\Hooks\{HookContext, HookEvent, ScriptHook};
            $hook = new ScriptHook('env_probe', HookEvent::PreToolUse, '.*', 'env | sort', 'measure');
            foreach (['', 'RESULT-TEXT'] as $output) {
                $result = $hook->execute(new HookContext(
                    sessionId: 's', toolName: 'Bash', toolArgs: [], toolInput: '{"command":"ls"}',
                    toolOutput: $output, model: 'm', provider: 'p', projectRoot: %s,
                ));
                echo $result->additionalContext, "\n@@RUN@@\n";
            }
            PHP,
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->dir . '/project', true),
        ));

        $argv = ['/usr/bin/env', '-i'];
        foreach ($launch as $name => $value) {
            $argv[] = $name . '=' . $value;
        }
        $argv[] = PHP_BINARY;
        $argv[] = $script;

        $pipes = [];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "the measurement child failed:\n" . $stderr);

        $runs = explode("@@RUN@@\n", $stdout);
        self::assertCount(3, $runs, "the measurement child did not report two runs:\n" . $stdout);

        $parsed = [];
        foreach ([$runs[0], $runs[1]] as $run) {
            $vars = [];
            foreach (explode("\n", trim($run)) as $line) {
                [$name, $value] = explode('=', $line, 2) + [1 => ''];
                $vars[$name] = $value;
            }
            $parsed[] = $vars;
        }

        return [$parsed[0], $parsed[1]];
    }
}
