<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Code\MCP\McpForeignTranslate;

/**
 * Pins the "Browser control and computer use" presets in docs/MCP.md to the
 * loader's own reading vocabulary (plan_comparison C-TOOL-6.a).
 *
 * WHY THE SECTION NEEDS A TEST AT ALL: it is the one place on the page where
 * a reader is invited to paste a whole block and start servers that steer a
 * browser. The section's argument is that exact version pins are what keep
 * the trust fingerprint honest (the gate compares recorded argv, so a
 * floating tag never re-triggers it) — a claim a later careless edit could
 * dissolve by writing `@latest` into an args array. And any edit that
 * renames the heading would silently empty the extraction, so the heading
 * itself is pinned first: no section, no green.
 *
 * WHAT IS PINNED: every fenced JSON block under the heading decodes; every
 * server entry it shows passes {@see McpForeignTranslate::normalizeEntry()}
 * — the SAME method the loader runs before any transport is chosen, so the
 * docs cannot teach a shape the reader would have to retype; every `npx`/`uvx`
 * package argument carries an exact version in its toolchain's pinning
 * grammar (npm `name@X.Y.Z`, uv `name==X.Y.Z` — never `@latest`, never a
 * bare name); and every permission rule shown names a bridged wire spelling.
 *
 * WHAT IS NOT: that the packages still exist today or still accept the flags
 * shown. That drift is dated in the prose (the section names the day the
 * versions were read); the pins keep any future edit honest about having
 * re-read something, rather than quietly loosening what it asserts.
 */
final class McpBrowserPresetDocTest extends TestCase
{
    /** The subsection under test, byte-exact against the docs file. */
    private const HEADING = '### Browser control and computer use';

    /** npm exact-pin grammar: optional scope, name, `@`, three numeric parts. */
    private const NPX_PIN_PATTERN = '/\A@?[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)?@\d+\.\d+\.\d+\z/';

    /** Python/uv exact-pin grammar: name, `==`, three numeric parts. */
    private const UVX_PIN_PATTERN = '/\A[A-Za-z0-9._-]+==\d+\.\d+\.\d+\z/';

    /** Bridged wire spelling the gate matches: `mcp__<server>__<tool>`. */
    private const MCP_WIRE_PATTERN = '/\Amcp__[A-Za-z0-9._-]+__[A-Za-z0-9._-]+/';

    private static function browserSectionBody(): string
    {
        $docs = file_get_contents(dirname(__DIR__, 2) . '/docs/MCP.md');
        self::assertIsString($docs, 'docs/MCP.md is unreadable');

        $at = strpos($docs, "\n" . self::HEADING . "\n");
        self::assertNotFalse(
            $at,
            'the "Browser control and computer use" subsection of docs/MCP.md was '
            . 'renamed or moved: the pinned presets would become un-extractable',
        );

        $body = substr($docs, $at + strlen(self::HEADING) + 2);
        $next = preg_match('/^#{2,4} /m', $body, $end, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $next, 'the subsection must end at a following heading');

        return substr($body, 0, $end[0][1]);
    }

    /**
     * @return list<array<string,mixed>> every fenced json block in the section
     */
    private static function browserSectionBlocks(): array
    {
        $blocks = [];
        preg_match_all('/^```json\s*\n(.*?)^```/ms', self::browserSectionBody(), $fences);
        foreach ($fences[1] as $index => $raw) {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray(
                $decoded,
                'preset block ' . ($index + 1) . ' under the browser section is not a JSON object',
            );
            $blocks[] = $decoded;
        }

        self::assertGreaterThanOrEqual(2, count($blocks), 'the section must show the presets once per tier');

        return $blocks;
    }

    /**
     * @return array<string,array<string,mixed>> server key => entry, from every
     *         block that declares mcpServers (the tier blocks)
     */
    private static function browserPresetEntries(): array
    {
        $entries = [];
        foreach (self::browserSectionBlocks() as $block) {
            $servers = $block['mcpServers'] ?? null;
            if ($servers === null) {
                continue; // a rules or other shape block; decoding was already pinned
            }
            self::assertIsArray($servers, 'an mcpServers value in the section is not an object');
            foreach ($servers as $name => $entry) {
                self::assertIsString($name);
                self::assertIsArray($entry);
                $entries[$name] = $entry;
            }
        }

        self::assertNotEmpty($entries, 'no block under the browser section declares mcpServers');

        return $entries;
    }

    public function testTheBrowserSectionExistsAndIsNotEmpty(): void
    {
        $body = self::browserSectionBody();

        self::assertStringContainsString('2026-10-30', $body,
            'the section must carry the day its versions were read — the pins are dated');
        self::assertMatchesRegularExpression('/@playwright\/mcp@\d+\.\d+\.\d+/', $body);
        self::assertMatchesRegularExpression('/chrome-devtools-mcp@\d+\.\d+\.\d+/', $body);
        self::assertMatchesRegularExpression('/windows-mcp==\d+\.\d+\.\d+/', $body);
    }

    public function testEveryServerEntryPassesTheLoadersOwnNormalisation(): void
    {
        foreach (self::browserPresetEntries() as $name => $entry) {
            try {
                $normalised = McpForeignTranslate::normalizeEntry($entry);
            } catch (RuntimeException $rejected) {
                self::fail('server "' . $name . '" in docs/MCP.md is a shape the loader rejects: '
                    . $rejected->getMessage());
            }

            // None of the presets may decline to run: an `enabled: false`
            // example would teach readers to paste a server that starts nothing.
            self::assertIsArray($normalised, 'server "' . $name . '" normalises to nothing (enabled: false)');
        }
    }

    public function testPackageArgumentsCarryExactVersionsAndNeverFloatingTags(): void
    {
        foreach (self::browserPresetEntries() as $name => $entry) {
            $command = $entry['command'] ?? null;
            if ($command !== 'npx' && $command !== 'uvx') {
                continue; // the pinning grammar belongs to these two launchers
            }

            $args = $entry['args'] ?? [];
            self::assertIsArray($args, 'server "' . $name . '" has a non-list args array');

            $package = null;
            foreach ($args as $arg) {
                self::assertIsString($arg);
                if (!isset($arg[0]) || $arg[0] === '-') {
                    continue;
                }
                $package = $arg;
                break;
            }

            self::assertNotNull($package, 'server "' . $name . '" launches ' . $command . ' with no package argument');
            $grammar = $command === 'npx' ? self::NPX_PIN_PATTERN : self::UVX_PIN_PATTERN;
            self::assertSame(1, preg_match($grammar, $package),
                'server "' . $name . '" carries "' . $package . '" — the section promises an exact '
                . 'version pin (' . ($command === 'npx' ? 'name@X.Y.Z' : 'name==X.Y.Z') . '), '
                . 'because a floating argv never re-triggers the trust fingerprint');
        }
    }

    public function testPermissionRuleExamplesNameBridgedWireSpellings(): void
    {
        $seen = 0;
        foreach (self::browserSectionBlocks() as $block) {
            $rules = $block['permissionRules'] ?? null;
            if ($rules === null) {
                continue;
            }
            self::assertIsArray($rules, 'a permissionRules value in the section is not a list');
            foreach ($rules as $rule) {
                self::assertIsArray($rule);
                self::assertIsString($rule['pattern'] ?? null, 'a rule example lost its pattern');
                self::assertSame(1, preg_match(self::MCP_WIRE_PATTERN, (string) $rule['pattern']),
                    'rule example "' . (string) ($rule['pattern'] ?? '?') . '" is not the '
                    . 'mcp__<server>__<tool> spelling the gate matches');
                self::assertContains($rule['action'] ?? null, ['allow', 'deny', 'ask'],
                    'rule example carries an action outside the three the parser reads');
                ++$seen;
            }
        }

        self::assertGreaterThanOrEqual(1, $seen,
            'the section lost its permissionRules example; readers need the by-name allow shape');
    }
}
