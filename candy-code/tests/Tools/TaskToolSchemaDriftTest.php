<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use PhpToken;
use ReflectionClass;
use RuntimeException;
use SugarCraft\Code\Agents\AgentDefinition;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;

/**
 * Standing guard: TaskTool's wire schema, its prose, its code, and the agent
 * doc pages must describe ONE parameter set, ONE built-in roster, and ONE
 * required/optional boundary.
 *
 * WHY THIS EXISTS. The Task tool is the delegation surface the whole
 * sub-agent system hangs from, and its contract is spelled out in five
 * places at once: inputSchema(), description(), the $args reads inside
 * execute(), docs/AGENTS.md's parameter table, and the roster sentences in
 * README.md, docs/AGENTS.md and docs/AGENTS_AUTHORING.md. Nothing checked
 * them against each other: a key added to the schema with no table row (or
 * a table row for a key the schema never grew) sailed through CI, and the
 * six built-in names hand-typed in prose were pinned nowhere at all —
 * AgentManagerWiringTest types them as a fixture, not against
 * AgentDefinition::TYPE_* derivation. This file joins them up in both
 * directions, derived from code rather than re-typed.
 *
 * THE DERIVATION SOURCES. Schema from a standalone new TaskTool() (every
 * constructor field is optional, so the tool answers its schema unbonded,
 * the TaskToolTest precedent). Roster from the TYPE_* class constants of
 * AgentDefinition. Doc rows from the '## The Task tool' table of
 * docs/AGENTS.md, keyed by the first column plus the exact alias syntax the
 * agent row uses for `subagent_type` — a blanket backtick scan would accuse
 * the table's own value literals like `true`. Argument reads from the
 * comment-stripped source of TaskTool.php, so doc prose mentioning
 * $args never counts as code.
 *
 * DECLARED BOUNDARIES. The transport envelope's `id` key (the engine's
 * tool-call identity, read by every house tool but never model-facing) is
 * allow-listed against the schema arm; that is a decision, not an omission.
 * The class-level doc-block is NOT scanned: it cites internals (execute(),
 * Chat::executeAgents()) whose backticks would swamp the prose arm. The
 * per-parameter prose quality of each description is not judged — only
 * membership: names mentioned must exist in the schema.
 */
final class TaskToolSchemaDriftTest extends TestCase
{
    /**
     * The tool-call id the engine injects into $args on every house tool.
     * Transport envelope, not a model-facing parameter — deliberately absent
     * from inputSchema(). The arm below names this exception explicitly.
     */
    private const TRANSPORT_ARGUMENT_KEYS = ['id'];

    public function testTheSchemaIsSelfConsistentAndEveryParameterIsDescribed(): void
    {
        $schema = (new TaskTool())->inputSchema();
        self::assertSame('object', $schema['type'] ?? null, 'the Task schema must describe an object');

        $properties = $schema['properties'] ?? [];
        self::assertNotCount(0, $properties, 'the Task schema lost its properties entirely');

        $required = $schema['required'] ?? [];
        self::assertNotCount(0, $required, 'the Task schema must require at least one parameter');
        foreach ($required as $key) {
            self::assertArrayHasKey($key, $properties, "required key $key names no schema property");
        }

        foreach ($properties as $key => $definition) {
            self::assertMatchesRegularExpression('/^[a-z][a-z_]*$/', (string) $key, 'parameter keys are snake_case');
            self::assertIsString($definition['description'] ?? null, "parameter $key carries no description string");
            self::assertNotSame('', trim((string) $definition['description']), "parameter $key is described by whitespace");
            self::assertIsString($definition['type'] ?? null, "parameter $key carries no type string");
        }
    }

    public function testTheDocumentedTaskTableRowsAreExactlyTheSchemaParameters(): void
    {
        $documented = array_merge(
            array_keys($this->agentsTaskTableRows()),
            $this->agentsTaskTableAliases(),
        );

        $undocumented = array_diff(array_keys($this->taskSchemaProperties()), $documented);
        $invented = array_diff($documented, array_keys($this->taskSchemaProperties()));

        self::assertSame([], array_values($undocumented), 'schema parameters missing a docs/AGENTS.md Task-table row');
        self::assertSame([], array_values($invented), 'docs/AGENTS.md Task-table rows naming no schema parameter');
    }

    public function testTheTableMarksOptionalExactlyWhereTheSchemaDoesNotRequire(): void
    {
        $required = (new TaskTool())->inputSchema()['required'] ?? [];
        $rows = $this->agentsTaskTableRows();
        $markedOptional = [];
        foreach ($rows as $key => $cell) {
            if (str_contains($cell, 'Optional.')) {
                $markedOptional[] = (string) $key;
            }
        }

        // The alias-only key (`subagent_type`) has no row to mark, so the
        // boundary is checked over the documented rows, never the full schema.
        $expected = array_values(array_diff(array_keys($rows), $required));
        sort($markedOptional);
        sort($expected);

        self::assertSame(
            $expected,
            $markedOptional,
            'the prose Optional. marks and the schema required list disagree',
        );
    }

    public function testTheProseToolDescriptionOnlyNamesRealSchemaParameters(): void
    {
        $tokens = $this->lowercaseBacktickTokens((new TaskTool())->description());
        self::assertNotCount(0, $tokens, 'the Task description prose quotes no parameter at all');

        $stray = array_values(array_diff($tokens, array_keys($this->taskSchemaProperties())));
        self::assertSame([], $stray, 'description() prose quotes names that are not Task parameters');
    }

    public function testTheSchemaPropertyDescriptionsOnlyNameRealSchemaParameters(): void
    {
        $tokens = $this->lowercaseBacktickTokens(
            implode("\n", array_map('strval', array_column($this->taskSchemaProperties(), 'description'))),
        );
        self::assertNotCount(0, $tokens, 'no schema property cross-references another by backtick');

        $stray = array_values(array_diff($tokens, array_keys($this->taskSchemaProperties())));
        self::assertSame([], $stray, 'a schema description cross-references a name the schema never declares');
    }

    public function testTheAgentParameterListsTheBuiltInRosterExactly(): void
    {
        $agentDescription = (string) ($this->taskSchemaProperties()['agent']['description'] ?? '');
        self::assertMatchesRegularExpression(
            '/"([a-z_]+)"/',
            $agentDescription,
            'the agent parameter stopped naming example roster entries in quotes',
        );

        preg_match_all('/"([a-z_]+)"/', $agentDescription, $quoted);
        $listed = array_values(array_unique($quoted[1]));
        sort($listed);

        self::assertSame(
            $this->builtinRosterNames(),
            $listed,
            'the agent examples and the derived AgentDefinition roster diverged',
        );
    }

    public function testTheDocPagesListTheBuiltInRosterExactly(): void
    {
        $roster = $this->builtinRosterNames();
        $windows = [
            'docs/AGENTS.md' => '/six\s+built-in definitions — (.*?) \(`Agents/us',
            'README.md' => '/six\s+built-ins — (.*?) —\s+plus/us',
            'docs/AGENTS_AUTHORING.md' => '/six\s+built-in definitions \(`src\/Agents\/AgentDefinition\.php`\) are (.*?)\. All/us',
        ];

        foreach ($windows as $file => $pattern) {
            $page = $this->packageFile($file);
            self::assertSame(
                1,
                preg_match($pattern, $page, $window),
                "the built-in roster sentence vanished from $file",
            );

            $listed = $this->lowercaseBacktickTokens($window[1]);
            sort($listed);
            self::assertSame(
                $roster,
                $listed,
                "the built-in roster hand-typed in $file no longer matches AgentDefinition",
            );
        }
    }

    public function testTheArgumentsTheCodeReadsAreTheSchemaPlusTheTransportEnvelope(): void
    {
        $expected = array_merge(array_keys($this->taskSchemaProperties()), self::TRANSPORT_ARGUMENT_KEYS);
        sort($expected);
        $read = array_values(array_unique($this->taskToolArgumentReads()));
        sort($read);

        self::assertSame(
            $expected,
            $read,
            'execute() reads a different $args set than the schema declares (plus the allow-listed transport key)',
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function taskSchemaProperties(): array
    {
        return (new TaskTool())->inputSchema()['properties'] ?? [];
    }

    /**
     * First-column keys of the docs/AGENTS.md Task table mapped to their cells.
     *
     * @return array<string, string>
     */
    private function agentsTaskTableRows(): array
    {
        $section = $this->agentsTaskTableSection();
        preg_match_all('/^\| `([a-z_]+)` \|([^\n]*)$/m', $section, $rows, PREG_SET_ORDER);

        $cells = [];
        foreach ($rows as $row) {
            $cells[$row[1]] = trim($row[2], " |\t");
        }

        self::assertNotCount(0, $cells, 'the Task parameter table disappeared from docs/AGENTS.md');

        return $cells;
    }

    /**
     * @return list<string>
     */
    private function agentsTaskTableAliases(): array
    {
        preg_match_all('/`([a-z_]+)` is accepted as an alias/', $this->agentsTaskTableSection(), $aliases);

        return $aliases[1];
    }

    private function agentsTaskTableSection(): string
    {
        $page = $this->packageFile('docs/AGENTS.md');
        self::assertSame(
            1,
            preg_match('/## The `Task` tool\n(.*?)(?:\n## |\z)/s', $page, $section),
            'the docs/AGENTS.md Task-tool section vanished',
        );

        return $section[1];
    }

    /**
     * The roster derived, not re-typed: every TYPE_* constant of AgentDefinition.
     *
     * @return list<string>
     */
    private function builtinRosterNames(): array
    {
        $names = [];
        foreach ((new ReflectionClass(AgentDefinition::class))->getConstants() as $name => $value) {
            if (str_starts_with((string) $name, 'TYPE_')) {
                $names[] = (string) $value;
            }
        }

        self::assertNotCount(0, $names, 'AgentDefinition grew no TYPE_* constants at all');
        sort($names);

        return $names;
    }

    /**
     * Every $args['key'] the real (comment-stripped) code of TaskTool reads.
     *
     * @return list<string>
     */
    private function taskToolArgumentReads(): array
    {
        $tokens = PhpToken::tokenize($this->packageFile('src/Tools/BuiltIn/TaskTool.php'));
        $code = '';
        foreach ($tokens as $token) {
            if ($token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }

            $code .= $token->text;
        }

        preg_match_all('/\$args\[\'([a-z_]+)\'\]/', $code, $reads);
        self::assertNotCount(0, $reads[1], 'the comment-stripped TaskTool reads no $args key at all');

        return $reads[1];
    }

    /**
     * @return list<string>
     */
    private function lowercaseBacktickTokens(string $text): array
    {
        preg_match_all('/`([a-z_]+)`/', $text, $tokens);

        return array_values(array_unique($tokens[1]));
    }

    private function packageFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("the guard cannot read its own package file: $path");
        }

        return $contents;
    }
}
