<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Read implements Tool, ParallelSafe, CarriesSessionState
{
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): reading a file a skill scopes itself to
     * announces that skill once, the way Claude Code loads skills on first
     * read inside a scoped subdirectory. Null keeps the tool standalone.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
    ) {}

    /**
     * Opening a file mutates nothing a sibling call could observe, so a batch
     * of reads is the canonical case for
     * {@see \SugarCraft\Crush\Runtime}'s concurrent dispatch.
     *
     * The one thing `execute()` DOES mutate — the announce-once marks of the
     * shared {@see InstructionFileLoader}/{@see SkillPathNudge} — would die
     * with the forked child, which is why this tool also implements
     * {@see CarriesSessionState} and hands those marks back.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * Kept byte-identical to {@see Glob::exportSessionState()}: both tools are
     * wired to the SAME two collaborators (see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}), so a key one of them
     * exported and the other did not would leave that half re-announcing
     * forever.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $paths = $state['emittedInstructionPaths'] ?? null;
        if (is_array($paths)) {
            $this->instructionLoader?->markEmitted(array_values($paths));
        }

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }
    }

    public function name(): string
    {
        return 'Read';
    }
    public function description(): string
    {
        return 'Read contents of a file';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of why this file is being read (e.g. "Inspect the chat model constructor", not "reads a file").',
            ],
        ],
        'required' => ['file_path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';

        if ($this->worktreeJail !== null) {
            $path = $this->worktreeJail->jailPath($path);
            if (!$this->worktreeJail->isAllowed($path)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
        } elseif ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        }

        set_error_handler(static function (int $errno, string $errstr) use ($path): bool {
            throw new \RuntimeException("Error reading file {$path}: {$errstr}");
        });
        try {
            clearstatcache(true, $path);
            $size = @filesize($path);
            if ($size !== false && $size > $this->maxBytes) {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    throw new \RuntimeException("Error reading file {$path}");
                }
                $content = fread($handle, $this->maxBytes);
                fclose($handle);
                if ($content === false) {
                    throw new \RuntimeException("Error reading file {$path}");
                }
                $content .= "\n... [truncated]";
            } else {
                $content = file_get_contents($path);
            }
            restore_error_handler();

            // Prepend nested instruction file content if found for this path
            $nestedContent = $this->instructionLoader?->loadForPath($path);
            if ($nestedContent !== null) {
                $content = $nestedContent . "\n" . $content;
            }

            // Appended, not prepended: the file the model asked for stays the
            // head of the result and the reminder trails it.
            $nudge = $this->skillNudge?->forPath($path);
            if ($nudge !== null) {
                $content .= "\n\n" . $nudge;
            }

            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $content,
                isError: false,
            );
        } catch (\Throwable $e) {
            restore_error_handler();
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $e->getMessage(),
                isError: true,
            );
        }
    }
}
