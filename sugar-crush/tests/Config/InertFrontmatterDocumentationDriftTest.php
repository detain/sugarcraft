<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\FrontmatterKeyAudit;

/**
 * X-37a: the documentation's "Inert" rows are {@see FrontmatterKeyAudit::INERT},
 * not a second opinion.
 *
 * Three pages each keep a field table for one frontmatter format, and each
 * marks the fields nothing acts on as **Inert**. Before this, those marks were
 * prose: `docs/COMMANDS.md` said `subtask: true` "runs it in an isolated
 * subagent" while nothing read it, and the README said `context: fork` skills
 * ran through `AgentWorkerPool`. Now the set of rows each table marks inert
 * must equal the audit's set for that format, both ways — so the step that
 * honours a field deletes its INERT entry and the doc row goes red until it
 * stops calling the field inert, and a doc that calls a live field inert (or
 * forgets an inert one) fails here.
 */
final class InertFrontmatterDocumentationDriftTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function tables(): iterable
    {
        yield 'skills' => ['docs/SKILLS.md', '| Key | Default | Read by | Effect today |', FrontmatterKeyAudit::SKILL];
        yield 'commands' => ['docs/COMMANDS.md', '| Key | Effect |', FrontmatterKeyAudit::COMMAND];
        yield 'agent presets' => ['docs/AGENTS_AUTHORING.md', '| Field | Effect today |', FrontmatterKeyAudit::AGENT];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testTheDocumentedInertRowsAreTheAuditsInertKeys(string $doc, string $header, string $format): void
    {
        $inert = self::inertKeysIn(self::table($doc, $header));
        $expected = array_keys(FrontmatterKeyAudit::INERT[$format]);

        sort($inert);
        sort($expected);
        self::assertSame(
            $expected,
            $inert,
            "{$doc}: the rows marked **Inert** must be exactly FrontmatterKeyAudit::INERT['{$format}']",
        );
    }

    /**
     * Every key a table names is one the audit knows, so a renamed or
     * invented field in the doc does not quietly escape the check above.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testEveryDocumentedKeyIsAKnownKey(string $doc, string $header, string $format): void
    {
        foreach (self::keysIn(self::table($doc, $header)) as $key) {
            self::assertContains($key, FrontmatterKeyAudit::KNOWN[$format], "{$doc} documents `{$key}`, which the audit does not know");
        }
    }

    public function testTheReadmeNoLongerClaimsForkContextSkillsAreEnforced(): void
    {
        $readme = self::read('README.md');

        self::assertStringNotContainsString('a fork-context skill runs through `AgentWorkerPool`', $readme);
        self::assertStringContainsString('a `context: fork` skill behaves exactly like a `thread` one', $readme);
    }

    /**
     * @param list<string> $rows
     *
     * @return list<string>
     */
    private static function inertKeysIn(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $cells = array_map('trim', explode('|', trim($row, " |")));
            if (str_starts_with((string) end($cells), '**Inert')) {
                array_push($keys, ...self::backticked($cells[0]));
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $rows
     *
     * @return list<string>
     */
    private static function keysIn(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            array_push($keys, ...self::backticked(explode('|', trim($row, " |"))[0]));
        }

        return $keys;
    }

    /** @return list<string> */
    private static function backticked(string $cell): array
    {
        preg_match_all('/`([^`]+)`/', $cell, $m);

        return $m[1];
    }

    /**
     * The body rows of the first table under $header.
     *
     * @return list<string>
     */
    private static function table(string $doc, string $header): array
    {
        $lines = explode("\n", self::read($doc));
        $start = array_search($header, $lines, true);
        self::assertIsInt($start, "{$doc} has no table headed `{$header}`");

        $rows = [];
        for ($i = $start + 2; isset($lines[$i]) && str_starts_with($lines[$i], '|'); ++$i) {
            $rows[] = $lines[$i];
        }
        self::assertNotSame([], $rows, "{$doc}: the `{$header}` table has no rows");

        return $rows;
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $text = file_get_contents($path);
        self::assertIsString($text, "cannot read {$path}");

        return $text;
    }
}
