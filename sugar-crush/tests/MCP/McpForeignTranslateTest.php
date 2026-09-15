<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpForeignTranslate;

/**
 * E710 — the foreign-config translator behind `sugarcrush mcp import`.
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
     * The same four servers in sugar-crush's canonical shape — structurally
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
        // sugar-crush's native shape IS Claude's — a conforming document must
        // come out identical (modulo key ORDER, asserted separately) with an
        // EMPTY notes list. The empty list is the pin: a note line per server
        // that needed nothing would train the reader to skim the real ones.
        $document = ['mcpServers' => ['fs' => self::CANONICAL_SERVERS['searxng']]];

        $translated = McpForeignTranslate::translateDocument('claude', $document);

        self::assertSame(['fs' => self::CANONICAL_SERVERS['searxng']], $translated['servers']);
        self::assertSame([], $translated['notes']);
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
