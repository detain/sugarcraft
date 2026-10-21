<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpForeignTranslate;

/**
 * E710 — the foreign-config translator behind `candy-code mcp import`.
 *
 * THE PARITY THAT MATTERS: the operator's real opencode config (the exact
 * four-server shape from McpConfigToleranceTest's fixture, the document E708
 * was written against) must come out of `translateDocument` BYTE-EQUAL to the
 * canonical map that test proves the LOADER starts correctly — same renames,
 * same precedence, one vocabulary. If this file and the tolerance test ever
 * disagree about what `local` means, one of these pins goes red; that is the
 * REUSE LAW made mechanical.
 *
 * The notes are half the product: a translation that prints a different file
 * without saying what moved is the silent-drop class E708 closed on the load
 * side, re-opened on the print side. So every rename earns its sentence, and
 * a pass-through earns silence (an empty notes list is asserted, not assumed
 * — the zero-fixture shape DocFigure guards elsewhere in this suite).
 */
final class McpForeignTranslateTest extends TestCase
{
    /**
     * The operator's opencode servers block — structurally identical to
     * McpConfigToleranceTest::OPERATOR_OPENCODE, differing only in the
     * SEARXNG_URL value (hand-copied on purpose: a shared fixture would
     * let one edit move BOTH sides of the parity).
     */
    private const OPENCODE_SERVERS = [
        'searxng' => [
            'type' => 'local',
            'command' => ['npx', '-y', 'mcp-searxng'],
            'environment' => ['SEARXNG_URL' => 'http://127.0.0.1:8888'],
            'enabled' => true,
        ],
        'context7' => ['type' => 'remote', 'url' => 'https://mcp.context7.com/mcp'],
        'exa' => ['type' => 'remote', 'url' => 'https://mcp.exa.ai/mcp'],
        'gh_grep' => ['type' => 'remote', 'url' => 'https://mcp.grep.app'],
    ];

    /**
     * The same four servers in candy-code's canonical shape — structurally
     * identical to McpConfigToleranceTest::OPERATOR_TRANSLATED (same URL
     * deviation as above), the map the loader is proven to build correctly.
     */
    private const CANONICAL_SERVERS = [
        'searxng' => [
            'type' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', 'mcp-searxng'],
            'env' => ['SEARXNG_URL' => 'http://127.0.0.1:8888'],
        ],
        'context7' => ['type' => 'http', 'url' => 'https://mcp.context7.com/mcp'],
        'exa' => ['type' => 'http', 'url' => 'https://mcp.exa.ai/mcp'],
        'gh_grep' => ['type' => 'http', 'url' => 'https://mcp.grep.app'],
    ];

    public function testTheOperatorOpencodeBlockTranslatesToTheCanonicalTheLoaderStarts(): void
    {
        $translated = McpForeignTranslate::translateDocument('opencode', ['mcp' => self::OPENCODE_SERVERS]);

        // assertSame on arrays compares order too — this pins the keys land
        // in canonical emission order AND that nothing extra rode through
        // (no `enabled` flag survived, no `environment` twin lingered).
        self::assertSame(self::CANONICAL_SERVERS, $translated['servers']);
    }

    public function testTheTranslationNarratesEveryRenameItPerformed(): void
    {
        $translated = McpForeignTranslate::translateDocument('opencode', ['mcp' => self::OPENCODE_SERVERS]);

        self::assertSame([
            'moved the "mcp" block to "mcpServers"',
            'searxng: type "local" became "stdio"',
            'searxng: whole-argv "command" array split into "command" + "args"',
            'searxng: "environment" map renamed to "env"',
            'searxng: redundant "enabled": true dropped',
            'context7: type "remote" became "http"',
            'exa: type "remote" became "http"',
            'gh_grep: type "remote" became "http"',
        ], $translated['notes'], 'the note vocabulary drifted — every rename the translator performs owes exactly one sentence, in entry order');
    }

    public function testAClaudeBlockPassesThroughWithNothingToSay(): void
    {
        // candy-code's native shape IS Claude's — a conforming document must
        // come out identical (modulo key ORDER, asserted separately) with an
        // EMPTY notes list. The empty list is the pin: a note line per server
        // that needed nothing would train the reader to skim the real ones.
        // The fixtures therefore carry no `${` and no `sse` — ISS-093(e)'s
        // two warning classes; both have their own rows below.
        $document = ['mcpServers' => ['fs' => self::CANONICAL_SERVERS['searxng']]];

        $translated = McpForeignTranslate::translateDocument('claude', $document);

        self::assertSame(['fs' => self::CANONICAL_SERVERS['searxng']], $translated['servers']);
        self::assertSame([], $translated['notes']);
    }

    /**
     * MCP-4 closed the transport gap ISS-093(e) warned about: `sse` is a
     * BUILT type now ({@see SseMcpServer}), so the import is the clean
     * bottom path it never was — the entry rides no note because there is
     * no longer a lie for the note to pre-empt. The type is preserved as
     * written, exactly as the aliasing siblings preserve theirs.
     */
    public function testAnSseEntryNowImportsWithoutNotes(): void
    {
        $translated = McpForeignTranslate::translateDocument('claude', ['mcpServers' => [
            'legacy' => ['type' => 'sse', 'url' => 'https://old.example/sse'],
        ]]);

        self::assertSame([], $translated['notes']);
        self::assertSame('sse', $translated['servers']['legacy']['type'], 'the type imports as written — no silent rewrite to http');
    }

    /**
     * ISS-093(e): `${VAR}` rides the entry to LAUNCH, where resolveEnv()
     * expands it from this machine — substring occurrences included — so the
     * import is only equivalent on a host carrying the same variables. One
     * sentence per entry at most (three fields carry references here), and
     * the reference itself is written into the block UNTOUCHED.
     */
    public function testEmbeddedVariableReferencesEarnOneLaunchSemanticsWarningPerEntry(): void
    {
        $translated = McpForeignTranslate::translateDocument('claude', ['mcpServers' => [
            'a' => ['type' => 'http', 'url' => 'https://${HOST}/mcp', 'headers' => ['X-K' => 'k-${KEY}'], 'env' => ['T' => '${TOKEN}']],
            'b' => ['type' => 'stdio', 'command' => 'x', 'env' => ['LITERAL' => 'no references here']],
        ]]);

        $warning = 'a: references ${VAR} — expanded from this machine\'s environment at launch; the import is only equivalent where those variables are set';
        self::assertSame([$warning], $translated['notes']);
        self::assertSame('https://${HOST}/mcp', $translated['servers']['a']['url']);
    }

    public function testKeyOrderIsCanonicalOnEmissionWhateverOrderTheFileCarried(): void
    {
        // The pass-through promise is SEMANTIC, not byte-layout: keys arrive
        // in the docs/MCP.md worked-example order even when the foreign file
        // scrambled them — display only, the values are normalizeEntry()'s.
        $translated = McpForeignTranslate::translateDocument('claude', ['mcpServers' => [
            'x' => [
                'args' => ['-y'],
                'env' => [],
                'command' => 'npx',
                'type' => 'stdio',
                'startTimeout' => 90,
            ],
        ]]);

        self::assertSame(['type', 'command', 'args', 'env', 'startTimeout'], array_keys($translated['servers']['x']));
    }

    public function testDisabledEntryIsDroppedWithItsSentenceNotSilently(): void
    {
        $translated = McpForeignTranslate::translateDocument('claude', ['mcpServers' => [
            'kept' => ['type' => 'stdio', 'command' => 'run'],
            'off' => ['type' => 'stdio', 'command' => 'sleep', 'enabled' => false],
        ]]);

        self::assertSame(['kept'], array_keys($translated['servers']), 'the declined entry must not ride into the printed block');
        self::assertSame(
            ['dropped "off" — its own "enabled": false declines to start'],
            $translated['notes'],
            'a silent drop at import time re-opens the exact skip E708 closed at launch time',
        );
    }

    public function testTheEnvPrecedenceWinsItsOwnSentenceWhenEnvironmentIsOverruled(): void
    {
        $translated = McpForeignTranslate::translateDocument('claude', ['mcpServers' => [
            'both' => [
                'type' => 'stdio',
                'command' => 'run',
                'env' => ['A' => 'wins'],
                'environment' => ['A' => 'loses'],
            ],
        ]]);

        self::assertSame(['A' => 'wins'], $translated['servers']['both']['env']);
        self::assertSame(
            ['both: "environment" map dropped — its own "env" wins the precedence'],
            $translated['notes'],
        );
    }

    public function testTheSharedVocabularyIsLiterallyTheLoaders(): void
    {
        // THE REUSE LAW, mechanical: hand-running McpClient's own load-path
        // primitives (the public constant it consults + the public
        // canonicaliser it calls) over each foreign entry must yield EXACTLY
        // what the translation emits. A second mapping somewhere would
        // eventually fail this; a shared table can only fail here if BOTH
        // sides moved together — which is the drift this law exists to allow.
        $aliases = (new \ReflectionClass(McpForeignTranslate::class))->getConstant('TYPE_ALIASES');
        self::assertIsArray($aliases, 'TYPE_ALIASES vanished — this parity lost its subject');

        $expected = [];
        foreach (self::OPENCODE_SERVERS as $name => $foreign) {
            $canonical = McpForeignTranslate::normalizeEntry($foreign);
            self::assertIsArray($canonical, "fixture entry {$name} unexpectedly declines");
            $canonical['type'] = $aliases[$foreign['type']] ?? $canonical['type'];
            unset($canonical['enabled']);
            $expected[$name] = $canonical;
        }

        // assertEquals (array == ignores key ORDER): the VOCABULARY parity is
        // the point here — which renames happen — while emission order is its
        // own promise pinned by testKeyOrderIsCanonical…, and the full byte-
        // exact parity of this same fixture by the fixture test above. A
        // hand-run cannot re-order without re-typing the order table, which
        // is exactly the duplication this file exists to forbid.
        self::assertEquals(
            $expected,
            McpForeignTranslate::translateDocument('opencode', ['mcp' => self::OPENCODE_SERVERS])['servers'],
            'translateDocument and normalizeEntry+TYPE_ALIASES are supposed to be the SAME answer',
        );
    }

    public function testAnUnknownDialectRefusesTheImport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no importer dialect named "gemini"');

        McpForeignTranslate::translateDocument('gemini', ['mcpServers' => []]);
    }

    public function testAMissingContainerNamesTheDialectsOwnBlock(): void
    {
        // A Claude file handed to the opencode door (and vice versa) fails
        // with the container the NAMED dialect uses — not a half-translation
        // of the wrong shape.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no "mcp" block to import — this is not opencode config');

        McpForeignTranslate::translateDocument('opencode', ['mcpServers' => self::CANONICAL_SERVERS]);
    }

    public function testANonObjectEntryNamesItselfAndRefuses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"nope" is not a server object');

        McpForeignTranslate::translateDocument('claude', ['mcpServers' => ['nope' => 'yes']]);
    }

    public function testAMalformedEntryIsReWrappedWithItsNameAndKeepsItsCause(): void
    {
        $caught = null;
        try {
            McpForeignTranslate::translateDocument('opencode', ['mcp' => [
                'broken' => ['type' => 'local', 'command' => [], 'enabled' => 'sure'],
            ]]);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'the translator emitted past a malformed entry');
        self::assertSame('"broken": "enabled" must be a boolean when present', $caught->getMessage());
        self::assertNotNull($caught->getPrevious(), 'the cause chain from normalizeEntry must survive the re-wrap');
    }

    /**
     * v4-F20 follow-through (with 00b8fec9f): three wrap-and-widen rethrows
     * under src/MCP dropped their transport cause; the fix passes the caught
     * exception as $previous. This pins the SHAPE, not just the three sites —
     * before this guard nothing went red if the next rethrow lost its cause
     * again, which is exactly how the tree sat at three sites for its whole
     * pre-history. A throw whose message interpolates `->getMessage()` must
     * end on a variable argument (the `$previous` slot). The scan is
     * token-level, so parentheses and semicolons inside messages cannot
     * confuse it, like the other token censuses in this suite.
     */
    public function testEveryWideningRethrowUnderSrcMcpCarriesItsCause(): void
    {
        $violations = [];
        foreach (\glob(\dirname(__DIR__, 2) . '/src/MCP/*.php') ?: [] as $file) {
            foreach ($this->wideningRethrowsMissingCause((string) \file_get_contents($file)) as $line) {
                $violations[] = \basename($file) . ':' . $line;
            }
        }

        self::assertSame([], $violations, 'wrap-and-widen rethrows must pass the caught exception as $previous');
    }

    /**
     * Positive control: without a planted violation, a blind scan reads green
     * exactly like a clean tree (the zero-fixture law this suite follows).
     */
    public function testTheWideningRethrowGuardSeesAPlantedViolation(): void
    {
        $bare = <<<'PHP'
            <?php
            final class P
            {
                public function p(): void
                {
                    try {
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("start failed: {$e->getMessage()}");
                    }
                }
            }
            PHP;

        $accepted = [
            'positional' => \str_replace('getMessage()}");', 'getMessage()}", 0, $e);', $bare),
            'trailing comma (multi-line chains in this tree use it)' => \str_replace('getMessage()}");', 'getMessage()}", 0, $e,);', $bare),
            'named (the McpForeignTranslate spelling)' => \str_replace('getMessage()}");', 'getMessage()}", previous: $e);', $bare),
        ];

        self::assertSame([8], $this->wideningRethrowsMissingCause($bare), 'the bare rethrow on line 8 must be named');
        foreach ($accepted as $spelling => $source) {
            self::assertSame([], $this->wideningRethrowsMissingCause($source), "the {$spelling} twin must pass");
            // TOKEN_PARSE keeps a broken fixture substitution from masquerading
            // as a scan bug: an unbalanced quote would tokenize silently into a
            // giant string and the walk would flag line 8 for the wrong reason.
            \PhpToken::tokenize($source, \TOKEN_PARSE);
        }
        \PhpToken::tokenize($bare, \TOKEN_PARSE);
    }

    /**
     * @return list<int> source lines of throw statements that widen a caught
     *                   message without a cause in the $previous slot
     */
    private function wideningRethrowsMissingCause(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);
        $violations = [];

        for ($i = 0; $i < $count; ++$i) {
            if ($tokens[$i]->id !== \T_THROW) {
                continue;
            }

            $depth = 0;
            $argOpen = -1;
            $callEnd = -1;
            $widening = false;
            $commas = [];
            $end = $count;
            $throwLine = $tokens[$i]->line;

            for ($j = $i + 1; $j < $count; ++$j) {
                $text = $tokens[$j]->text;

                if ($text === '(') {
                    ++$depth;
                    if ($argOpen === -1) {
                        $argOpen = $j;
                    }
                } elseif ($text === ')') {
                    --$depth;
                    if ($depth === 0 && $callEnd === -1) {
                        $callEnd = $j;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $commas[] = $j;
                } elseif ($text === ';' && $depth === 0) {
                    $end = $j;
                    break;
                }

                if ($tokens[$j]->id === \T_OBJECT_OPERATOR && ($tokens[$j + 1]->id ?? null) === \T_STRING && ($tokens[$j + 1]->text ?? '') === 'getMessage') {
                    $widening = true;
                }
            }

            $i = $end;

            if (!$widening) {
                continue;
            }

            // The $previous slot is the call's last NON-EMPTY top-level
            // argument — non-empty matters because PHP allows a trailing
            // comma, and multi-line chains in this tree use it. Accept a
            // variable (positional `0, $e`) or the `previous:` label
            // (named-argument spelling); anything else means the cause
            // never rode the rethrow.
            $bounds = array_merge([$argOpen + 1], array_map(static fn (int $comma): int => $comma + 1, $commas));
            $ends = array_merge($commas, [$callEnd]);
            $firstSignificant = null;

            for ($s = \count($bounds) - 1; $s >= 0; --$s) {
                for ($k = $bounds[$s]; $k < $ends[$s]; ++$k) {
                    $id = $tokens[$k]->id;
                    if ($id === \T_WHITESPACE || $id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                        continue;
                    }

                    $firstSignificant = match (true) {
                        $id === \T_VARIABLE => 'positional',
                        $id === \T_STRING && $tokens[$k]->text === 'previous' => 'named',
                        default => 'other',
                    };
                    break 2;
                }
            }

            if ($firstSignificant !== 'positional' && $firstSignificant !== 'named') {
                $violations[] = $throwLine;
            }
        }

        return $violations;
    }

    public function testTheMcpClientConsultsThisTableNotACopyOfIt(): void
    {
        // Census-adjacent truth for the REUSE LAW: McpClient holds no
        // TYPE_ALIASES constant of its own any more — its E708 vocabulary is
        // HERE, and the client is a caller. DocFigure AX/BL pin the call
        // sites; this pins the absence of a second table.
        self::assertFalse(
            (new \ReflectionClass(McpClient::class))->getReflectionConstant('TYPE_ALIASES') !== false,
            'McpClient grew a second alias map — the importer and the loader are drifting again',
        );
        self::assertSame(
            ['local' => 'stdio', 'remote' => 'http'],
            (new \ReflectionClass(McpForeignTranslate::class))->getConstant('TYPE_ALIASES'),
            'the alias pairs shifted — docs/MCP.md rows and McpConfigToleranceTest read this same map',
        );
    }
}
