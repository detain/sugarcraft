<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Message;

/**
 * The two shell-out tiers of {@see Bootstrap::backend()}, and the reason there
 * are two of them.
 *
 * crush_code.md Phase 2 item 8 asked for `$SUGARCRUSH_BACKEND_CMD` to select
 * {@see StreamingCommandBackend} instead of {@see CommandBackend}. Taken
 * literally that is destructive, and this file is the measurement that says so:
 * the streaming backend's stdout contract is one token per TERMINATED line, so
 * it `rtrim`s every line, turns an empty one into a single `\n`, and joins the
 * pieces with the EMPTY STRING. The wrapper `CommandBackend`'s own docblock
 * recommends — `curl … | jq -r '.content[0].text'`, which emits the model's
 * prose — therefore comes back through it with every single newline deleted and
 * every paragraph break collapsed to one newline, so no list and no code fence
 * survives. So the item shipped as a SECOND variable,
 * `$SUGARCRUSH_BACKEND_CMD_STREAM`, and the first test below is the one that
 * fails the moment anyone points the original variable at the streaming class.
 *
 * HOME is redirected and the whole selection chain cleared for every test, same
 * convention as {@see BootstrapContextWindowTest}: `backend()` consults
 * $SUGARCRUSH_PROVIDER, both shell-out variables and a persisted provider in
 * `~/.sugar-crush/config.json`, and an ambient value for any of them would
 * decide these tests instead of the fixtures.
 */
final class BootstrapShellOutTierTest extends TestCase
{
    /**
     * Two lines of prose, a blank line, then a third — i.e. one hard line break
     * and one paragraph break. Through the streaming contract the hard line
     * break vanishes entirely (a single newline is framing) and the paragraph
     * break survives as ONE newline rather than two, which is still not a
     * paragraph break to any markdown renderer.
     */
    private const PROSE = "Para one line one.\nPara one line two.\n\nPara two.";

    private string $tempDir;
    private string $home;
    private string $project;
    private string $originalHome;
    private mixed $originalServerHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var list<string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_shellout_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->home, 0700, true);
        mkdir($this->project, 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        foreach ([
            'SUGARCRUSH_PROVIDER',
            'SUGARCRUSH_MODEL',
            'SUGARCRUSH_BACKEND_CMD',
            'SUGARCRUSH_BACKEND_CMD_STREAM',
        ] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }

        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        foreach ($this->originalEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE REGRESSION THE WHOLE DESIGN EXISTS FOR. A prose wrapper on the
     * original variable keeps every newline and every blank line, asserted as
     * exact bytes rather than "contains".
     *
     * Point `$SUGARCRUSH_BACKEND_CMD` at {@see StreamingCommandBackend} — the
     * literal reading of Phase 2 item 8 — and this test reports
     * "Para one line one.Para one line two.\nPara two." No hedge, no
     * "contains", no substring that survives the mutation.
     */
    public function testTheOriginalVariableReturnsProseVerbatimNewlinesAndBlankLinesIncluded(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD=' . $this->proseScript());

        $backend = Bootstrap::backend($this->project);

        // Bytes first, deliberately: a mutation that swaps the class in
        // reports the FLATTENED STRING here rather than only an instanceof
        // mismatch, so the diff names the damage instead of the type.
        $this->assertSame(
            self::PROSE,
            $backend->complete([Message::user('hi')])->content,
            'the verbatim-stdout contract of $SUGARCRUSH_BACKEND_CMD',
        );
        $this->assertInstanceOf(CommandBackend::class, $backend);
    }

    /**
     * The same script through the streaming variable, measured rather than
     * described — this is what makes the two contracts demonstrably
     * incompatible rather than merely differently documented.
     */
    public function testTheStreamingVariableFlattensThatSameProseWhichIsWhyItIsASeparateVariable(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());

        $backend = Bootstrap::backend($this->project);

        $this->assertInstanceOf(StreamingCommandBackend::class, $backend);
        $this->assertSame(
            "Para one line one.Para one line two.\nPara two.",
            $backend->complete([Message::user('hi')])->content,
            'one token per line joined with nothing — the single newlines are framing;'
            . ' the BLANK line is the one thing that means a literal newline',
        );
    }

    /**
     * The concrete class, not "a Backend came back": asserting the interface
     * would pass on either tier and is exactly the assertion that would let
     * the two get swapped.
     */
    public function testEachVariableSelectsItsOwnConcreteBackendClass(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD=' . $this->proseScript());
        $plain = Bootstrap::backend($this->project);
        $this->assertInstanceOf(CommandBackend::class, $plain);
        $this->assertNotInstanceOf(StreamingCommandBackend::class, $plain);

        putenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());
        $streaming = Bootstrap::backend($this->project);
        $this->assertInstanceOf(StreamingCommandBackend::class, $streaming);
        $this->assertNotInstanceOf(CommandBackend::class, $streaming);
    }

    /**
     * Tier order, asserted from the ambiguous configuration rather than from
     * the docblock: with both exported, the OLDER documented meaning wins, so
     * nobody's existing wrapper changes behaviour the day the second variable
     * ships.
     */
    public function testTheOriginalVariableOutranksTheStreamingOneWhenBothAreSet(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD=' . $this->proseScript());
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());

        $backend = Bootstrap::backend($this->project);

        $this->assertInstanceOf(CommandBackend::class, $backend);
        $this->assertSame(self::PROSE, $backend->complete([Message::user('hi')])->content);
    }

    /**
     * `$onToken` fires once per terminated line, counted — the capability the
     * streaming tier exists to make reachable at all. Before this variable the
     * class was constructed nowhere in `src/`, so no run could ever see a
     * token arrive early.
     */
    public function testTheStreamingTierDeliversOneTokenPerLineToTheCallback(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());

        $backend = Bootstrap::backend($this->project);
        $tokens = [];
        $backend->complete([Message::user('hi')], function (string $token) use (&$tokens): void {
            $tokens[] = $token;
        });

        // FOUR, and the third is a newline: the blank line is the only way this
        // protocol can express a line break, so it is delivered rather than
        // dropped. Dropping it is what made the tier unable to return a `\n`
        // for any input whatsoever.
        $this->assertSame(
            ['Para one line one.', 'Para one line two.', "\n", 'Para two.'],
            $tokens,
        );
    }

    /**
     * The CALL SITE's construction arguments, not the class's defaults.
     *
     * `new StreamingCommandBackend($streamCmd, 1)` — a 1-second idle cap on
     * every live streaming run — left this whole file and
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushDispatchTest}
     * green, because the only thing pinning "no deadline" was a reflection test
     * on the CONSTRUCTOR DEFAULT plus a comment at the call site. A comment does
     * not fail. So this reads the constructed object.
     *
     * Why it matters at all: the tier's contract is that a completion may run
     * as long as the model needs, and a wrapper that thinks for two seconds
     * between tokens is normal for a reasoning model.
     */
    public function testTheStreamingTierIsConstructedWithNoIdleDeadline(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());

        $backend = Bootstrap::backend($this->project);

        $this->assertInstanceOf(StreamingCommandBackend::class, $backend);
        $this->assertSame(
            0,
            (new \ReflectionProperty($backend, 'idleTimeout'))->getValue($backend),
            'the live path must pass no idle deadline — see StreamingCommandBackend::__construct()',
        );
    }

    /**
     * Both shell-out tiers have to answer the two selection helpers the same
     * way, because {@see \SugarCraft\Crush\Cli\NonInteractive} asks them
     * whether to announce "no provider configured". A streaming run that
     * reported a persisted provider name here would ALSO be a run whose
     * `-p` output was attributed to a provider that never ran.
     */
    public function testTheStreamingTierIsLabelledAShellOutAndOutranksAPersistedProvider(): void
    {
        Bootstrap::writeUserConfig(['provider' => 'definitely-not-a-real-provider']);
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $this->proseScript());

        $this->assertNull(
            Bootstrap::selectedProviderName(),
            'a shell-out run is on no provider, whatever an earlier Ctrl+P choice persisted',
        );
        $this->assertSame(
            'command',
            Bootstrap::selectedProviderLabel()[0],
            'one label for both shell-out tiers — see selectedProviderLabel()',
        );
        $this->assertInstanceOf(
            StreamingCommandBackend::class,
            Bootstrap::backend($this->project),
            'the stale persisted name must not outrank an exported shell-out',
        );
    }

    /**
     * The negative half of the label assertion above: with neither variable
     * set the label is still 'echo', so the test above cannot be passing
     * because everything is labelled 'command'.
     */
    public function testNeitherVariableSetIsStillLabelledEcho(): void
    {
        $this->assertSame('echo', Bootstrap::selectedProviderLabel()[0]);
    }

    /**
     * An empty value is absence, on the new variable as on the old one —
     * otherwise `export SUGARCRUSH_BACKEND_CMD_STREAM=` would select a
     * shell-out with no command to run and spawn-failure text as the reply.
     */
    public function testAnEmptyStreamingVariableCountsAsUnset(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM=');

        $this->assertNotInstanceOf(StreamingCommandBackend::class, Bootstrap::backend($this->project));
        $this->assertSame('echo', Bootstrap::selectedProviderLabel()[0]);
    }

    /**
     * And so is a WHITESPACE-ONLY value, on BOTH variables — the case `!== ''`
     * let through. `export SUGARCRUSH_BACKEND_CMD='   '` used to select the
     * shell-out tier, spawn `sh -c '   '`, exit 0 and return an EMPTY assistant
     * message, with the run labelled 'command' so nothing warned: no model, no
     * answer, no complaint, and the next tier unreachable.
     *
     * Asserted on the BACKEND and on the LABEL together, because they are two
     * different readers of the same question and the bug is only fully gone
     * when they agree — a fix in `backend()` alone would leave
     * `selectedProviderLabel()` calling it a shell-out run.
     *
     * @dataProvider whitespaceOnlyCommandValues
     */
    public function testAWhitespaceOnlyValueCountsAsUnsetOnEitherVariable(string $var, string $value): void
    {
        putenv($var . '=' . $value);

        $backend = Bootstrap::backend($this->project);

        $this->assertNotInstanceOf(CommandBackend::class, $backend, 'a string of blanks is not a command');
        $this->assertNotInstanceOf(StreamingCommandBackend::class, $backend);
        $this->assertSame('echo', Bootstrap::selectedProviderLabel()[0]);
        $this->assertNull(Bootstrap::selectedProviderName());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function whitespaceOnlyCommandValues(): array
    {
        return [
            'spaces on the original variable' => ['SUGARCRUSH_BACKEND_CMD', '   '],
            'spaces on the streaming variable' => ['SUGARCRUSH_BACKEND_CMD_STREAM', '   '],
            'a tab on the original variable' => ['SUGARCRUSH_BACKEND_CMD', "\t"],
            'a newline on the streaming variable' => ['SUGARCRUSH_BACKEND_CMD_STREAM', "\n"],
        ];
    }

    /**
     * A wrapper that prints {@see self::PROSE} and consumes the history on
     * stdin. `cat > /dev/null` is not decoration: without it the child exits
     * while the parent is still writing the JSON payload, and the resulting
     * EPIPE warning is a failure under phpunit.xml's failOnWarning="true".
     */
    private function proseScript(): string
    {
        $script = $this->tempDir . '/prose_' . count($this->scripts) . '.sh';
        file_put_contents(
            $script,
            "#!/usr/bin/env bash\ncat > /dev/null\nprintf '%s\\n' \"" . self::PROSE . "\"\n",
        );
        chmod($script, 0755);
        $this->scripts[] = $script;

        return $script;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
