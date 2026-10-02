<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;

/**
 * Pins two memory-adjacent documentation claims to the code they describe.
 *
 * AUDIT 15d-25. Since 15d-10 `PromptFence::escape()` also rewrites the `<` of
 * chat-template control-token openers (`<|`, `<｜`) and matches roster tags
 * that carry attributes, while MEMORY.md said the escape "touches nothing
 * else" and PROMPT_ENGINEERING.md's fence rules listed only the roster tags —
 * so a user who saw `&lt;|` in a note's prompt rendering had nothing to
 * explain it. Both pages now cite the defang with worked examples, and each
 * example is replayed through the live authority here, against the glyph list
 * `PromptFence::CONTROL_TOKEN_PIPES` itself: a new pipe glyph the pages do not
 * name, or an example the escape no longer produces, goes red.
 *
 * AUDIT R11. `/memory import claude` follows Claude Code's `CLAUDE_CONFIG_DIR`
 * and `CLAUDE_CODE_PROJECT_DIR_NAME`. EnvRosterDriftTest pins only the
 * `SUGARCRUSH_*` roster, so the "Claude Code variables" table on
 * ENVIRONMENT.md is pinned here instead, both directions, against the
 * importer's own `*_ENV` constants.
 */
final class MemoryDocumentationDriftTest extends TestCase
{
    private const DOCS = __DIR__ . '/../../docs';

    /** @return iterable<string, array{string}> */
    public static function defangPages(): iterable
    {
        yield 'MEMORY.md' => ['MEMORY.md'];
        yield 'PROMPT_ENGINEERING.md' => ['PROMPT_ENGINEERING.md'];
    }

    #[DataProvider('defangPages')]
    public function testThePageNamesEveryControlTokenOpenerTheFenceDefangs(string $page): void
    {
        $prose = self::prose($page);

        foreach (self::controlTokenPipes() as $pipe) {
            self::assertStringContainsString(
                '`<' . $pipe . '`',
                $prose,
                sprintf('%s does not name the `<%s` opener PromptFence::CONTROL_TOKEN_PIPES defangs (audit 15d-25)', $page, $pipe),
            );
        }
    }

    #[DataProvider('defangPages')]
    public function testThePagesControlTokenExamplesAreWhatTheEscapeProduces(string $page): void
    {
        self::assertSame(
            1,
            preg_match(
                '/so `([^`]+)` and `([^`]+)` reach the prompt as `([^`]+)` and `([^`]+)`/u',
                self::prose($page),
                $m,
            ),
            sprintf('%s lost its "so `A` and `B` reach the prompt as `A\'` and `B\'`" defang example', $page),
        );

        self::assertSame($m[3], PromptFence::escape($m[1]), sprintf('%s: `%s` no longer escapes to `%s`', $page, $m[1], $m[3]));
        self::assertSame($m[4], PromptFence::escape($m[2]), sprintf('%s: `%s` no longer escapes to `%s`', $page, $m[2], $m[4]));

        // The two examples between them cover every glyph the authority lists,
        // so a page cannot show the ASCII bar alone and leave the fullwidth one
        // (the DeepSeek-style `<｜User｜>`) unexplained.
        $shown = [];
        foreach ([$m[1], $m[2]] as $source) {
            foreach (self::controlTokenPipes() as $pipe) {
                if (str_starts_with(ltrim(substr($source, 1), '/'), $pipe)) {
                    $shown[] = $pipe;
                }
            }
        }
        self::assertEqualsCanonicalizing(self::controlTokenPipes(), $shown, sprintf('%s\'s two examples do not cover every CONTROL_TOKEN_PIPES glyph', $page));
    }

    #[DataProvider('defangPages')]
    public function testThePagesAttributeBearingTagIsOneTheEscapeDefangs(string $page): void
    {
        self::assertSame(
            1,
            preg_match('/`(<system-reminder [^`]*)`/u', self::prose($page), $m),
            sprintf('%s no longer cites an attribute-bearing roster tag', $page),
        );

        self::assertSame('&lt;' . substr($m[1], 1), PromptFence::escape($m[1]), sprintf('%s: `%s` is no longer defanged', $page, $m[1]));
    }

    public function testTheClaudeCodeVariablesTableIsExactlyTheVariablesTheImporterReads(): void
    {
        $doc = (string) file_get_contents(self::DOCS . '/ENVIRONMENT.md');
        self::assertSame(
            1,
            preg_match('/^## Claude Code variables\n(.*?)(?=^## |\z)/ms', $doc, $section),
            'ENVIRONMENT.md lost its "Claude Code variables" section (audit R11)',
        );
        preg_match_all('/^\| `([A-Z][A-Z0-9_]*)` \|/m', $section[1], $rows);

        $read = [];
        foreach ((new \ReflectionClass(ForeignMemoryImporter::class))->getReflectionConstants() as $constant) {
            if (str_ends_with($constant->getName(), '_ENV')) {
                $read[] = (string) $constant->getValue();
            }
        }

        self::assertNotSame([], $read, 'ForeignMemoryImporter declares no *_ENV constant — the harness lost its subject');
        self::assertEqualsCanonicalizing(
            $read,
            $rows[1],
            'the "Claude Code variables" table and the variables ForeignMemoryImporter reads disagree',
        );
    }

    public function testTheProjectDirNameRowStatesTheLengthTheImporterAccepts(): void
    {
        $pattern = (string) (new \ReflectionClass(ForeignMemoryImporter::class))
            ->getReflectionConstant('CLAUDE_PROJECT_DIR_NAME_PATTERN')
            ?->getValue();
        self::assertSame(1, preg_match('/\{(\d+),(\d+)\}/', $pattern, $bounds), 'the name pattern lost its length bounds');

        $doc = (string) file_get_contents(self::DOCS . '/ENVIRONMENT.md');
        self::assertSame(1, preg_match('/^\| `CLAUDE_CODE_PROJECT_DIR_NAME` \|.*$/m', $doc, $row));
        self::assertStringContainsString(
            sprintf('%s–%s letters', $bounds[1], $bounds[2]),
            $row[0],
            'the CLAUDE_CODE_PROJECT_DIR_NAME row no longer states the length the importer accepts',
        );
    }

    /** @return list<string> */
    private static function controlTokenPipes(): array
    {
        $pipes = (new \ReflectionClass(PromptFence::class))->getReflectionConstant('CONTROL_TOKEN_PIPES')?->getValue();
        self::assertIsArray($pipes);
        self::assertNotSame([], $pipes);

        return array_values(array_map('strval', $pipes));
    }

    /** The page with every run of whitespace folded to one space, so a reflow does not break a match. */
    private static function prose(string $page): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(self::DOCS . '/' . $page));
    }
}
