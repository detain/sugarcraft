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
    /**
     * The cap is the behaviour worth stating: an oversize file comes back
     * SHORT rather than as an error (see {@see execute()}), and a model told
     * only "read a file" has no reason to suspect the content it got was the
     * head of a larger one.
     *
     * The byte figure is read off $maxBytes rather than written out — a caller
     * that passed its own cap would otherwise advertise the default's number
     * instead of its own. The prefer-this-over-`cat` clauses are conditional
     * for the same reason: containment and instruction-file surfacing come
     * from two DIFFERENT injected collaborators, and an instance holding
     * neither must not claim either.
     */
    public function description(): string
    {
        // Each advantage is claimed only by the instance that actually has
        // it: containment comes from a root or a worktree jail, and the
        // instruction-file surfacing comes from the loader. A standalone
        // instance has neither, and must not advertise them.
        $advantages = [];
        if ($this->worktreeJail !== null || $this->root !== null) {
            $advantages[] = 'it is confined to the workspace root';
        }
        if ($this->instructionLoader !== null) {
            $advantages[] = 'any CLAUDE.md/AGENTS.md governing the file\'s directory is '
                . 'surfaced with the content the first time that directory is touched';
        }
        $advantages[] = 'a read failure comes back as a tool error rather than as a crash';

        return 'Read contents of a file from the local filesystem. Content comes back up to '
            . number_format($this->maxBytes) . ' bytes; a larger file is truncated to that '
            . 'much and marked "... [truncated]" rather than erroring, so a short result may '
            . 'be the head of a longer file. Prefer this over `cat`/`head` through Bash: '
            . implode('; ', $advantages) . '.';
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

        // The type check is part of the same guard, not a separate nicety: this
        // sits ABOVE the try/catch below, and under strict_types str_contains()
        // raises a TypeError on a non-string. Without it, `file_path: 123` --
        // which the untyped tool-call JSON can carry -- turned the very crash
        // the NUL guard exists to remove back into an uncaught throw out of
        // execute(), where it had previously been caught and reported as a tool
        // error. ToolRegistry::execute() does not wrap its call site, so that
        // is a crash the model never sees as a result.
        if (!is_string($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: file_path must be a string',
                isError: true,
            );
        }

        // A NUL byte makes realpath()/filesize() throw a ValueError rather than
        // fail, which escaped execute() as an uncaught crash instead of a tool
        // error the model can read and correct. It is not a containment bypass
        // -- both jail branches below reject the path anyway -- but the throw
        // happened before they got the chance on the no-jail path.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: file_path contains a NUL byte',
                isError: true,
            );
        }

        if ($this->worktreeJail !== null) {
            // resolve() proves containment AND returns the canonical path, so
            // the bytes read below are the bytes that were checked. The old
            // jailPath()+isAllowed() pairing proved containment on the
            // realpath() of $path and then re-opened the UNRESOLVED $path --
            // a symlink component swapped between the two calls resolved
            // somewhere else on the second pass (crush_code.md P8.14/15).
            //
            // The file_exists() half is isAllowed()'s existence-strictness,
            // kept verbatim: resolve() also accepts a not-yet-existing file
            // whose parent is in the jail, which is not something Read may
            // open, and rejecting it here preserves this call site's error.
            $resolved = $this->worktreeJail->resolve($path);
            if ($resolved === null || !file_exists($resolved)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
            $path = $resolved;
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
