<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phantom-API guard: every method, property, and enum case the README
 * "API Summary" tables and the docs/_data site API table claim MUST resolve
 * against the live classes. Docs here carried a dead withAnimationDuration()
 * row and getHistory()/Action::make() spellings long after the code said
 * otherwise; this guard makes that class of drift CI-loud instead of
 * reader-loud.
 *
 * Fail-closed: unparseable rows are collected and reported (never skipped
 * silently), and per-surface minimum row counts catch a parser that has
 * drifted away from the doc shape entirely.
 */
final class ToastDocApiCensusTest extends TestCase
{
    private const KNOWN_CLASSES = ['Toast', 'Alert', 'Action', 'HistoryLog', 'Position', 'ToastType', 'Overflow', 'SymbolSet', 'Lang'];

    private const ENUM_CLASSES = ['Position', 'ToastType', 'Overflow', 'SymbolSet'];

    public function testEveryReadmeApiSummaryMethodAndCaseResolves(): void
    {
        [$rows, $unparseable] = $this->parseRows($this->readmeApiSection(), 'readme');
        $this->assertSame([], $unparseable, 'unparseable README API rows');
        $this->assertGreaterThanOrEqual(30, count($rows), 'README API parser fell off the table shape');
        $this->assertResolvable($rows, 'README');
    }

    public function testEverySiteBodyApiMethodAndCaseResolves(): void
    {
        [$rows, $unparseable] = $this->parseRows($this->siteBodyApiRows(), 'html');
        $this->assertSame([], $unparseable, 'unparseable site API rows');
        $this->assertGreaterThanOrEqual(30, count($rows), 'site API parser fell off the table shape');
        $this->assertResolvable($rows, 'site');
    }

    public function testThePhantomApiFamilyStaysDead(): void
    {
        // Named tripwires for the exact drifts this lib has already shipped.
        foreach (['withAnimationDuration', 'getHistory', 'setDuration', 'animate'] as $phantom) {
            $this->assertFalse(
                method_exists(\SugarCraft\Toast\Toast::class, $phantom),
                "Toast::{$phantom}() reappeared — docs must be re-derived with it"
            );
        }
        $this->assertFalse(method_exists(\SugarCraft\Toast\Action::class, 'make'));
    }

    // ── row extraction ─────────────────────────────────────────────────────

    /** @return array{list<array{class:string,sig:string,source:string}>, list<string>} */
    private function parseRows(array $cellsPerRow, string $source): array
    {
        $rows = [];
        $unparseable = [];
        foreach ($cellsPerRow as $cells) {
            if (count($cells) < 2 || $cells[0] === 'Class' || $cells[0] === 'Method' || $cells[0] === 'Enum') {
                continue;
            }
            if (str_starts_with($cells[0], '---') || str_starts_with($cells[0], '--------')) {
                continue;
            }
            if (in_array($cells[0], self::KNOWN_CLASSES, true)) {
                $class = $cells[0];
                $sig = $cells[1];
            } elseif ($source === 'readme' && str_starts_with($cells[0], '->')) {
                $class = 'Toast';
                $sig = $cells[0];
            } elseif (preg_match('/^(\w+)::/', $cells[0], $m) === 1 && in_array($m[1], self::KNOWN_CLASSES, true)) {
                $class = $m[1];
                $sig = $cells[0];
            } else {
                $unparseable[] = "{$source}: " . implode(' | ', $cells);
                continue;
            }
            $rows[] = ['class' => $class, 'sig' => $sig, 'source' => $source];
        }
        return [$rows, $unparseable];
    }

    /** @param list<array{class:string,sig:string,source:string}> $rows */
    private function assertResolvable(array $rows, string $label): void
    {
        $caseRows = 0;
        $methodRows = 0;
        foreach ($rows as $row) {
            $fqcn = "SugarCraft\\Toast\\{$row['class']}";
            $this->assertTrue(class_exists($fqcn) || enum_exists($fqcn), "{$label}: unknown class {$row['class']}");
            $sig = $row['sig'];

            // Typed-property shape first: "→name: type" (may itself carry
            // parens inside the type, e.g. "\Closure(): void").
            if (preg_match('/^(?:->|\$)?([a-z_][A-Za-z0-9_]*)\s*:/', $sig, $pm) === 1) {
                $methodRows++;
                $this->assertTrue(
                    property_exists($fqcn, $pm[1]),
                    "{$label}: {$row['class']}->{$pm[1]} is not a property (sig: {$sig})"
                );
                continue;
            }

            if (!str_contains($sig, '(')) {
                // Either an enum case list ("TopLeft, TopCenter, …"), a
                // property shape ("->label: non-empty-string" / "$label"),
                // or a method-name-without-parens row ("label()"), or a
                // label table row ("type.info / … | …") in the i18n section
                // — the census only polices API-summary sources.
                if (in_array($row['class'], self::ENUM_CLASSES, true) && preg_match('/^[A-Z]/', $sig) === 1) {
                    $caseRows++;
                    foreach (explode(',', $sig) as $case) {
                        $case = trim($case);
                        if ($case === '') {
                            continue;
                        }
                        $this->assertTrue(
                            \defined("{$fqcn}::{$case}"),
                            "{$label}: {$row['class']}::{$case} does not exist (docs enum row)"
                        );
                    }
                    continue;
                }
                if (preg_match('/^(?:->|\$)?([a-z_][A-Za-z0-9_]*)/', $sig, $m) === 1) {
                    $methodRows++;
                    if (method_exists($fqcn, $m[1])) {
                        continue; // e.g. bare `label()` spelling
                    }
                    $this->assertTrue(
                        property_exists($fqcn, $m[1]),
                        "{$label}: {$row['class']}->{$m[1]} is neither method nor property (sig: {$sig})"
                    );
                    continue;
                }
                $this->fail("{$label}: unresolvable row without parens: {$row['class']} | {$sig}");
            }

            $methodRows++;
            // Slash-joined helper names sharing one parameter list:
            // ->error/warning/info/success(string)
            $expanded = (string) preg_replace_callback(
                '/(?:^|(?<=[\s>]))([a-z]\w*)(?:\/([a-z]\w*))+\s*\(/',
                function (array $m): string {
                    $full = $m[0];
                    $openAt = strpos($full, '(');
                    $head = rtrim(substr($full, 0, $openAt));
                    $tail = substr($full, $openAt);
                    return implode(', ', array_map(
                        static fn(string $n): string => $n . $tail,
                        explode('/', $head)
                    ));
                },
                $sig
            );

            preg_match_all('/(?<![\w\\\\])([A-Za-z_]\w*)\s*\(/', $expanded, $ms);
            $names = $ms[1];
            // Backslash-qualified type callables (\Closure()) are excluded by
            // the lookbehind; drop parameter type words defensively.
            $names = array_values(array_unique(array_filter(
                $names,
                static fn(string $n): bool => $n !== 'Closure' && $n !== 'callable'
            )));
            $this->assertNotEmpty($names, "{$label}: no method token in {$sig}");
            foreach ($names as $method) {
                $this->assertTrue(
                    method_exists($fqcn, $method),
                    "{$label}: {$row['class']}::{$method}() claimed but absent (sig: {$sig})"
                );
            }
        }
        $this->assertGreaterThanOrEqual(4, $caseRows, "{$label}: enum case rows vanished (parser drift?)");
        $this->assertGreaterThanOrEqual(20, $methodRows, "{$label}: method rows vanished (parser drift?)");
    }

    // ── surfaces ───────────────────────────────────────────────────────────

    /** @return list<list<string>> rows of backticked-cell tables in API Summary */
    private function readmeApiSection(): array
    {
        $readme = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        $start = strpos($readme, '## API Summary');
        $end = strpos($readme, '## Shared foundations');
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $section = substr($readme, $start, $end - $start);

        $rows = [];
        foreach (explode("\n", $section) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map(
                static fn(string $c): string => trim(str_replace(['\\|', '`', '|'], ['|', '', ''], $c)),
                // Split on pipes NOT escaped as \|
                preg_split('/(?<!\\\\)\|/', $line) ?: []
            );
            // First/last empties from leading/trailing pipe: drop via filter on ''
            $cells = array_values(array_filter($cells, static fn(string $c): bool => $c !== ''));
            // Re-apply the escaped-pipe fix per cell after the trim pass above.
            $cells = array_map(static fn(string $c): string => str_replace('\\|', '|', $c), $cells);
            if (count($cells) >= 2) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    /** @return list<list<string>> rows of every api-table in the site body */
    private function siteBodyApiRows(): array
    {
        $body = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/_data/sugar-toast.body.html');
        $this->assertNotSame('', $body, 'site body missing');
        preg_match_all('#<tr>(.*?)</tr>#s', $body, $trMs);

        $rows = [];
        foreach ($trMs[1] as $inner) {
            preg_match_all('#<td>(.*?)</td>#s', $inner, $tdMs);
            $cells = array_map(
                static fn(string $c): string => trim(html_entity_decode(strip_tags($c), flags: ENT_QUOTES)),
                $tdMs[1] ?? []
            );
            if (count($cells) >= 3) {
                $rows[] = $cells;
            }
        }
        // Site i18n key table also has 3 tds — its first cell is a key list,
        // which the classifier cannot map to a class; exclude by shape here
        // (they are language keys, verified by LangCoverageTest instead).
        return array_values(array_filter(
            $rows,
            static fn(array $r): bool => !preg_match('/^(type\.|dismiss$|count$|Key\b)/', $r[0])
        ));
    }
}
