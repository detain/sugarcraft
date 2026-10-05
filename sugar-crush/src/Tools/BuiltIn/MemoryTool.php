<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The model's own access to persistent memory (roadmap 5.1-2): `view` a note
 * (or the index), `save` one, `str_replace` inside one, `delete` one, and
 * `recall` by search.
 *
 * Every write goes through {@see MemoryWriter}, the same router `/memory add`
 * uses, so a note the model saves lands exactly where the user's would and an
 * id resolves to the copy the prompt's index shows.
 *
 * PERMISSION CLASS: no-ask. `view` and `recall` read; `save`, `str_replace` and
 * `delete` write only the memory directories the harness owns (the home store
 * and the repository's `.sugar-crush/memory/`). A prompt would protect nothing
 * the user cannot undo with `/memory`, and an Ask would be a deny wherever no
 * approver is attached. `permissionRules` still apply first, so
 * `{"pattern": "Memory", "action": "deny"}` turns the tool off. A note id is
 * validated by {@see MemoryStore} before any file is named from it, so the tool
 * cannot reach a path outside those directories.
 */
#[BuiltInTool(name: 'Memory', permission: ToolPermissionClass::NoAsk, position: 12, gloss: 'view, save, edit, delete and recall the persistent memory notes indexed in the system prompt')]
final readonly class MemoryTool implements Tool, BuildsFromCatalog
{
    public const NAME = 'Memory';

    private const ACTIONS = ['view', 'save', 'str_replace', 'delete', 'recall'];

    /** Most matches a `recall` lists. */
    private const MAX_RECALL = 20;

    public function __construct(private ?MemoryWriter $writer = null)
    {
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->memory);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read and maintain your persistent memory: notes that outlive this session. The system '
            . 'prompt lists them as an index (type, id, opening words, tags). '
            . '`view` with an `id` returns that note in full; without one, the full index. '
            . '`save` stores a new note (`content`, optional `scope` project|user, `type` '
            . 'preference|convention|decision|pattern, `tags`). `str_replace` replaces the one occurrence of '
            . '`old_str` with `new_str` in the note `id`. `delete` removes the note `id`. `recall` searches '
            . 'every note for `query`. Save only what a future session needs and cannot read from the '
            . 'repository; never save secrets or what the code already says. Prefer `str_replace` on an '
            . 'existing note to saving a near-duplicate.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'What to do'],
                'id' => ['type' => 'string', 'description' => 'Note id (view, str_replace, delete)'],
                'content' => ['type' => 'string', 'description' => 'Note text (save)'],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['project', 'user'],
                    'description' => 'project (default): this repository; user: follows the user into every project (save)',
                ],
                'type' => ['type' => 'string', 'enum' => MemoryWriter::TYPES, 'description' => 'Note type (save; default pattern)'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags (save)'],
                'old_str' => ['type' => 'string', 'description' => 'Exact text to replace; must occur once (str_replace)'],
                'new_str' => ['type' => 'string', 'description' => 'Replacement text (str_replace)'],
                'query' => ['type' => 'string', 'description' => 'Text to search for (recall)'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        // The runtime pairs the result with its call; `id` here is a NOTE id.
        $callId = '';
        if ($this->writer === null) {
            return new ToolResult($callId, 'Error: no memory store is configured for this session', true);
        }

        $action = $args['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, self::ACTIONS, true)) {
            return new ToolResult($callId, 'Error: action must be one of ' . implode(', ', self::ACTIONS), true);
        }

        foreach (['id', 'content', 'scope', 'type', 'old_str', 'new_str', 'query'] as $field) {
            if (isset($args[$field]) && !\is_string($args[$field])) {
                return new ToolResult($callId, "Error: {$field} must be a string", true);
            }
        }

        try {
            return new ToolResult($callId, match ($action) {
                'view' => $this->view((string) ($args['id'] ?? '')),
                'save' => $this->save($args),
                'str_replace' => $this->replace($args),
                'delete' => $this->delete($this->requireString($args, 'id')),
                'recall' => $this->recall($this->requireString($args, 'query')),
            });
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new ToolResult($callId, 'Error: ' . $e->getMessage(), true);
        }
    }

    private function view(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return $this->index();
        }

        $located = $this->writer?->locate($id);
        if ($located === null) {
            throw new \InvalidArgumentException("no memory note '{$id}'");
        }

        [$entry, $store] = $located;

        return self::header($entry, $store === $this->writer?->repository() || !$store->writesIndex())
            . "\n\n" . $entry->content();
    }

    private function index(): string
    {
        $sections = [];
        $home = $this->writer?->home();
        $groups = [
            'user scope (home store)' => $home?->list(MemoryScope::User) ?? [],
            'project scope (this repository)' => $this->writer?->repository()?->list(MemoryScope::Project) ?? [],
            'project scope (home store)' => $home?->list(MemoryScope::Project) ?? [],
        ];
        foreach ($groups as $label => $entries) {
            if ($entries === []) {
                continue;
            }

            $lines = ["## {$label}"];
            foreach ($entries as $entry) {
                $lines[] = self::indexLine($entry);
            }
            $sections[] = implode("\n", $lines);
        }

        return $sections === [] ? 'No memory notes yet.' : implode("\n\n", $sections);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function save(array $args): string
    {
        $content = $this->requireString($args, 'content');
        // `memory.projectNoteMaxBytes` (roadmap N-P4d), not the constant: a
        // raised ceiling would otherwise still be refused here at the default.
        $maxBytes = ProjectMemoryWriter::maxContentBytes();
        if (\strlen($content) > $maxBytes) {
            throw new \InvalidArgumentException('content exceeds ' . $maxBytes . ' bytes');
        }

        $scope = (string) ($args['scope'] ?? 'project');
        if (!\in_array($scope, ['project', 'user'], true)) {
            throw new \InvalidArgumentException('scope must be project or user');
        }

        $tags = $args['tags'] ?? [];
        if (!\is_array($tags) || !array_is_list($tags) || array_filter($tags, static fn ($t): bool => !\is_string($t)) !== []) {
            throw new \InvalidArgumentException('tags must be a list of strings');
        }

        $result = $this->writer?->save($content, $scope, (string) ($args['type'] ?? 'pattern'), $tags)
            ?? throw new \RuntimeException('the memory store is not available');

        $where = $result->inRepository
            ? 'in this repository (' . ProjectMemoryWriter::RELATIVE_DIRECTORY . ')'
            : 'in the home store';
        $text = "Saved note {$result->id} (scope: {$result->scope}, {$where}).";
        if ($result->fellBackToHome) {
            $text .= ' The repository could not host ' . ProjectMemoryWriter::RELATIVE_DIRECTORY
                . ', so this project note is kept on this machine only.';
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function replace(array $args): string
    {
        $id = $this->requireString($args, 'id');
        $old = $this->requireString($args, 'old_str');
        $new = \is_string($args['new_str'] ?? null) ? $args['new_str'] : '';

        $this->writer?->replace($id, $old, $new);

        return "Updated note {$id}.";
    }

    private function delete(string $id): string
    {
        if ($this->writer?->delete($id) !== true) {
            throw new \InvalidArgumentException("no memory note '{$id}'");
        }

        return "Deleted note {$id}.";
    }

    private function recall(string $query): string
    {
        $found = $this->writer?->recall($query) ?? [];
        if ($found === []) {
            return "No memory note matches '{$query}'.";
        }

        $lines = [];
        foreach (\array_slice($found, 0, self::MAX_RECALL) as $entry) {
            $lines[] = self::indexLine($entry) . ' [scope: ' . $entry->scope() . ']';
        }
        if (\count($found) > self::MAX_RECALL) {
            $lines[] = sprintf('… and %d more; narrow the query.', \count($found) - self::MAX_RECALL);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function requireString(array $args, string $field): string
    {
        $value = $args[$field] ?? '';
        if (!\is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$field} is required");
        }

        return $value;
    }

    private static function indexLine(MemoryEntry $entry): string
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $entry->content()));
        if (\strlen($content) > MemoryStore::INDEX_PREVIEW_BYTES) {
            $content = rtrim(mb_strcut($content, 0, MemoryStore::INDEX_PREVIEW_BYTES, 'UTF-8')) . '...';
        }

        $tags = $entry->tags() === [] ? '' : ' (tags: ' . implode(', ', $entry->tags()) . ')';

        return '- [' . $entry->type() . '] ' . $entry->id() . ': ' . $content . $tags;
    }

    private static function header(MemoryEntry $entry, bool $inRepository): string
    {
        $tags = $entry->tags() === [] ? '' : ' · tags: ' . implode(', ', $entry->tags());

        return '[' . $entry->type() . '] ' . $entry->id() . ' · scope: ' . $entry->scope()
            . ($inRepository ? ' · in this repository' : ' · in the home store') . $tags
            . ' · updated ' . $entry->modifiedAt()->format('Y-m-d');
    }
}
