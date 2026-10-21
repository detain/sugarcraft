<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;

/**
 * 1021 v1:INFO-4 — a second `-p`/`--prompt` value still wins (unchanged), but
 * the parser no longer does it silently: exactly one stderr line per parse.
 *
 * WHY A CHILD RUN: the notice is a `fwrite(STDERR, …)` inside `parse()`, and
 * STDERR cannot be redirected in-process without touching every sibling
 * test's error channel. The child runs the REAL autoloader and the REAL
 * parser, and its stderr arrives in a pipe the parent owns — the same shape
 * `bin/candy-code` gives the notice in production.
 *
 * The negative arms earn their place beside the keystone: a single prompt —
 * in any spelling — must stay silent (the warning is about OVERRIDE, not
 * about `-p` itself), and because three arms can assign, the notice must
 * fire ONCE even when a triple override crosses all three spellings.
 * Last-wins precedence is pinned too: the fix promised to change the noise,
 * not the order.
 */
final class ArgvParserDuplicatePromptTest extends TestCase
{
    private const NOTICE = 'more than one -p/--prompt';

    private string $script;

    protected function setUp(): void
    {
        $autoload = \dirname((new \ReflectionClass(ArgvParser::class))->getFileName(), 3) . '/vendor/autoload.php';
        self::assertFileExists($autoload, 'the child needs the lib autoload, resolved through the loaded class');

        $dir = sys_get_temp_dir() . '/candy_dup_prompt_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $this->script = $dir . '/parse-child.php';

        // NOWDOC on purpose: the body's $argv must survive into the child
        // file verbatim, not interpolate in setUp. The autoload path crosses
        // as a single var_export'd token replacement.
        $template = <<<'PHP'
        <?php
        require __AUTOLOAD__;
        $argvs = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        $parsed = \SugarCraft\Code\Cli\ArgvParser::parse($argvs);
        echo json_encode(['prompt' => $parsed->prompt, 'requested' => $parsed->promptRequested]);

        PHP;
        file_put_contents($this->script, str_replace('__AUTOLOAD__', var_export($autoload, true), $template));
    }

    protected function tearDown(): void
    {
        $dir = \dirname($this->script);
        @unlink($this->script);
        @rmdir($dir);
    }

    public function testTwoShortPromptsWarnOnceAndTheLastStillWins(): void
    {
        [$stdout, $stderr] = $this->parseChild(['candy-code', '-p', 'one', '-p', 'two']);

        self::assertSame('two', $this->decodedPrompt($stdout), 'last-wins precedence is deliberately unchanged');
        self::assertSame(1, substr_count($stderr, self::NOTICE), 'the operator must hear about the override exactly once');
    }

    public function testASinglePromptStaysSilent(): void
    {
        [$stdout, $stderr] = $this->parseChild(['candy-code', '-p', 'only']);

        self::assertSame('only', $this->decodedPrompt($stdout));
        self::assertSame('', $stderr, 'one prompt is not an override; no line may appear');
    }

    public function testTheEqualsFormOverridingTheShortFormWarnsOnce(): void
    {
        [$stdout, $stderr] = $this->parseChild(['candy-code', '-p', 'first', '--prompt=second']);

        self::assertSame('second', $this->decodedPrompt($stdout));
        self::assertSame(1, substr_count($stderr, self::NOTICE));
    }

    public function testATripleOverrideAcrossAllThreeSpellingsStillWarnsOnce(): void
    {
        [$stdout, $stderr] = $this->parseChild(['candy-code', '-p', 'a', '--prompt=b', '--prompt', 'c']);

        self::assertSame('c', $this->decodedPrompt($stdout));
        self::assertSame(1, substr_count($stderr, self::NOTICE), 'once per parse is the contract, not once per arm');
    }

    public function testTheNoticeCarriesTheAppLabelOnce(): void
    {
        [, $stderr] = $this->parseChild(['candy-code', '-p', 'one', '-p', 'two']);

        self::assertSame(1, substr_count($stderr, 'candy-code: more than one'), 'the F-7 choke: stderr lines carry the label exactly once');
    }

    /**
     * @param array<int, string> $argv
     * @return array{0: string, 1: string} stdout and stderr
     */
    private function parseChild(array $argv): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->script, json_encode($argv, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [(string) $stdout, (string) $stderr];
    }

    private function decodedPrompt(string $stdout): ?string
    {
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'child produced no parse result: ' . var_export($stdout, true));

        return $decoded['prompt'];
    }
}
