<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Documentation-executes guard: every fenced php block in README.md is
 * transcribed into one child process and run against the real library, and
 * examples/sidebar.php must print the exact frames the README quotes.
 *
 * The quickstart used to import Constraint as a top-level class, which fatals
 * on copy-paste because it lives in the Constraint\ namespace — prose fixes
 * rot, executed snippets do not.
 */
final class ReadmeSnippetsTest extends TestCase
{
    public function testEveryReadmePhpBlockRunsAndReproducesItsDocumentedWidths(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $blocks = [];
        preg_match_all('/^```php\R(.*?)^```/ms', $readme, $blocks);
        $phpBlocks = $blocks[1] ?? [];

        $this->assertGreaterThanOrEqual(3, count($phpBlocks), 'README quickstart, type tour, and dock example must stay fenced as php');

        $uses = [];
        $seenAliases = [];
        $body = '';
        foreach ($phpBlocks as $block) {
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (preg_match('/^use\h([^;]+);\h*(\/\/.*)?$/', $line, $m) !== 1) {
                    $body .= $line . "\n";
                    continue;
                }
                // Dedupe per ALIAS (blocks repeat the Region import; grouped
                // use lines count as several aliases at once).
                $aliases = [];
                if (preg_match('/^([^{}]+)\{([^}]*)\}$/', trim($m[1]), $g) === 1) {
                    foreach (explode(',', $g[2]) as $item) {
                        $item = trim($item);
                        if ($item !== '') {
                            $parts = explode('\\', $item);
                            $aliases[] = end($parts);
                        }
                    }
                } else {
                    $parts = explode('\\', trim($m[1]));
                    $aliases[] = end($parts);
                }
                if (array_intersect($aliases, $seenAliases) !== []) {
                    continue;
                }
                $seenAliases = [...$seenAliases, ...$aliases];
                $uses[$line] = true;
            }
        }

        // The last $rects assignment in document order is the six-type tour;
        // $geometry comes from the dock block. Probe appended by the harness,
        // not part of the README.
        $probe = "\n\$tour = array_map(static fn(\$r): int => \$r->width, \$rects);\n"
            . "echo implode(',', \$tour), \"\n\";\n"
            . "echo \$geometry->regionFor('files')->width, ',', \$geometry->regionFor('chat')->width, \"\n\";\n";

        $script = "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n"
            . implode("\n", array_keys($uses)) . "\n" . $body . $probe;

        [$code, $stdout, $stderr] = $this->runChild($script, dirname(__DIR__));

        $this->assertSame(0, $code, "README blocks exited $code; stderr: $stderr");
        $this->assertSame('', $stderr, 'README blocks must run warning-free');
        $this->assertSame(
            "20,10,1,30,33,6\n32,34\n",
            $stdout,
            'documented widths drifted (README tour sums to 100; dock quote keeps files=32 and chat=34 columns)',
        );
    }

    public function testSidebarExamplePrintsTheFramesTheReadmeDescribes(): void
    {
        [$code, $stdout, $stderr] = $this->runChild(
            ['php', dirname(__DIR__) . '/examples/sidebar.php'],
            dirname(__DIR__),
        );

        $this->assertSame(0, $code, "example exited $code; stderr: $stderr");
        $this->assertSame(
            "width 100: [x=0 w=24]  [x=24 w=76]\n"
            . "width  10: [x=0 w=10]  [x=10 w=0]\n"
            . "4-pane  : [x=0 w=24]  [x=24 w=10]  [x=34 w=44]  [x=78 w=22]\n",
            $stdout,
        );
    }

    /**
     * @param string|list<string> $command
     * @return array{int, string, string}
     */
    private function runChild(string|array $command, string $cwd): array
    {
        $temp = null;
        if (is_string($command)) {
            $temp = tempnam(sys_get_temp_dir(), 'constr-snippet-') . '.php';
            file_put_contents($temp, $command);
            $command = ['php', '-d', 'display_errors=stderr', $temp];
        }

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            return [$code, $stdout, $stderr];
        } finally {
            if ($temp !== null) {
                unlink($temp);
            }
        }
    }
}
