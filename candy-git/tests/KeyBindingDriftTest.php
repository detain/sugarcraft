<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;

/**
 * The docs-pinned-by-tests guard for the key surfaces (candy-git-053).
 *
 * Before this file, nothing connected README's key tables to what the
 * app actually dispatches or advertises: 048/049/031 were concrete rot
 * that shipped green. Now the machine-readable source of the in-app
 * promise is Renderer::hintPairs (the strip builder, read over all three
 * pane states) and the source of dispatch truth is the App's own key
 * arms; the README tables are checked against both directions:
 *
 *  - every key the strip advertises must be documented, and every help
 *    key it names must resolve in lang/en.php;
 *  - every key documented in any README table must exist in dispatch
 *    (as a rune arm, a KeyType arm, or a ctrl chord) - a documented
 *    ghost key goes red.
 *
 * Token shape differences (PgUp vs PageUp, arrows vs Up/Down) are
 * bridged by the named ALIASES table below - normalisation of spelling,
 * never exemption of a key.
 */
final class KeyBindingDriftTest extends TestCase
{
    /**
     * Documented-token spellings that mean a dispatch-side spelling.
     * Keys are lowercased README/hint tokens; values are tokens the
     * dispatch set uses.
     */
    private const ALIASES = [
        'pageup' => 'pageup',
        'pgup' => 'pageup',
        'pagedn' => 'pagedown',
        'pgdn' => 'pagedown',
        'pagedown' => 'pagedown',
        '↑' => 'up',
        '↓' => 'down',
        'ctrl+r' => 'ctrl+r',
        'ctrl+c' => 'ctrl+c',
        'esc' => 'escape',
    ];

    /** @return list<string> */
    private static function readmeTableTokens(): array
    {
        $readme = (string) \file_get_contents(__DIR__ . '/../README.md');
        $tokens = [];
        // First-cell backticked spans of KEY TABLE rows only. A key table
        // is a '| Key | Action |' run: the API tables ('Class', 'Default
        // string') document surfaces, not bindings, and sweeping them in
        // turned the class name Git into a phantom key binding.
        $collect = false;
        foreach (explode("\n", $readme) as $line) {
            $isRow = preg_match('/^\|\s*`[^`]/', $line) === 1;
            $isSep = preg_match('/^\|[\s:|-]+$/', $line) === 1;
            $isKeyHeader = !$isRow && !$isSep
                && preg_match('/^\|\s*Key\s*\|.*\bAction\b/i', $line) === 1;
            if ($isKeyHeader) {
                $collect = true;
            } elseif (!$isRow && !$isSep && preg_match('/^\|[^|]+\|[^|]+\|/', $line) === 1) {
                $collect = false; // some other table header ends the run
            } elseif (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                $collect = false; // paragraph gap or section heading ends it
            }
            if (!$collect || !$isRow) {
                continue;
            }
            $cell = explode('|', $line)[1] ?? '';
            if (preg_match_all('/`([^`]+)`/', $cell, $spans) > 0) {
                foreach ($spans[1] as $span) {
                    // 'k/j' and '[ / ]' style compound cells split apart.
                    foreach (preg_split('/\s*\/\s*|\s+or\s+/', $span) ?: [] as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $tokens[strtolower($part)] = true;
                        }
                    }
                    $tokens[strtolower($span)] = true;
                }
            }
        }
        return array_keys($tokens);
    }

    /**
     * The dispatch truth: single-char runes, KeyType arms and ctrl
     * chords lifted straight out of src/App.php source text.
     *
     * @return array<string,true> set keyed by normalised token
     */
    private static function dispatchTokens(): array
    {
        $src = (string) \file_get_contents(__DIR__ . '/../src/App.php');
        $set = [];
        if (preg_match_all("/->rune\\s*===\\s*'([^']+)'/", $src, $m) > 0) {
            foreach ($m[1] as $rune) {
                $set[strtolower($rune)] = true;
            }
        }
        if (preg_match_all('/KeyType::([A-Z][A-Za-z]+\\??)/', $src, $m) > 0) {
            foreach ($m[1] as $arm) {
                $set[strtolower(rtrim($arm, '?'))] = true;
            }
        }
        if (preg_match_all('/->ctrl\\b[^;\\n]*?->rune\\s*===\\s*\'([^\']+)\'/', $src, $m) > 0) {
            foreach ($m[1] as $rune) {
                $set['ctrl+' . strtolower($rune)] = true;
            }
        }
        return $set;
    }

    /**
     * hintPairs across all three pane states (strip is only built while
     * no overlay is open, and each pane contributes its verbs).
     *
     * @return list<array{0:string,1:string}>
     */
    private static function allHintPairs(): array
    {
        $git = new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/B.php'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: [['sha' => 'abc1', 'subject' => 'first commit', 'author' => 'Joe', 'ago' => '5m ago']],
        );
        $hintPairs = new \ReflectionMethod(\SugarCraft\Git\Renderer::class, 'hintPairs');
        $hintPairs->setAccessible(true);

        $pairs = [];
        $app = App::start($git);
        foreach ([0, 1, 2] as $paneTurn) {
            $seen = [];
            foreach ($hintPairs->invoke(null, $app) as $pair) {
                $seen[$pair[0] . '#' . $pair[1]] = $pair;
            }
            $pairs = array_merge($pairs, array_values($seen));
            $app = $app->update(new KeyMsg(KeyType::Tab))[0];
        }
        // De-duplicate by token+key across panes.
        $unique = [];
        foreach ($pairs as $pair) {
            $unique[$pair[0] . '#' . $pair[1]] = $pair;
        }
        return array_values($unique);
    }

    public function test_every_key_the_hint_strip_advertises_is_documented_in_readme(): void
    {
        $documented = self::readmeTableTokens();

        foreach (self::allHintPairs() as [$token, $langKey]) {
            $parts = str_contains($token, '/') ? explode('/', $token) : [$token];
            foreach ($parts as $part) {
                $part = strtolower($part);
                $part = self::ALIASES[$part] ?? $part;
                // The promise the strip makes is a documentation obligation:
                // advertised keys must appear in the README tables, full
                // stop - dispatch membership is checked separately and
                // cannot excuse a missing row (that is the 048/049 class).
                $this->assertTrue(
                    in_array($part, $documented, true),
                    sprintf('hint strip advertises %s (%s) but the README key tables never document it', $token, $langKey),
                );
            }
        }
    }

    public function test_every_documented_key_actually_dispatches(): void
    {
        $dispatch = self::dispatchTokens();
        $structural = ['key', 'action', 'a+b', 'j/k', 'x/y']; // header + prose fragments

        foreach (self::readmeTableTokens() as $token) {
            $normalized = self::ALIASES[$token] ?? $token;
            if (in_array($normalized, $structural, true)) {
                continue;
            }
            // Compound cells were also indexed whole (e.g. 'k/j'); only
            // single-binding tokens carry a dispatch promise. The filter
            // key IS a slash, so length decides, not containment.
            if (str_contains($normalized, ' ') || (str_contains($normalized, '/') && strlen($normalized) > 1)) {
                $provable = true;
                foreach (preg_split('/\s*\/\s*|\s+or\s+/', $normalized) ?: [] as $part) {
                    $part = self::ALIASES[$part] ?? $part;
                    if (!isset($dispatch[$part])) {
                        $provable = false;
                    }
                }
                $this->assertTrue($provable, "README documents compound key {$token} with an undispatchable part");
                continue;
            }
            $this->assertTrue(
                isset($dispatch[$normalized]),
                sprintf('README key table documents %s but src/App.php has no matching rune/KeyType/ctrl arm', $token),
            );
        }
    }

    public function test_every_hint_strip_lang_key_resolves(): void
    {
        $catalogue = require __DIR__ . '/../lang/en.php';
        foreach (self::allHintPairs() as [$token, $langKey]) {
            $this->assertArrayHasKey(
                $langKey,
                (array) $catalogue,
                sprintf('hint pair %s points at lang key %s which the en catalog does not define', $token, $langKey),
            );
        }
    }
}
