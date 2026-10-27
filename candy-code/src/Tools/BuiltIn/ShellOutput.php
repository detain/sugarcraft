<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Reads the output of one background shell started by Bash's
 * `run_in_background` (SHL-1).
 *
 * CURSOR SEMANTICS are the whole design: the answer is everything written
 * since the cursor the caller last saw, so following a long build is
 * `ShellOutput(shell_id, cursor)` repeated, never a re-read of a growing
 * file. The cursor is a two-part token — `"<stdout offset>.<stderr
 * offset>"`, one offset per stream, each advancing in its own file — so
 * interleaved growth of the two streams can never skip one's bytes or
 * replay the other's (C-TOOL-1); a bare integer still decodes with the
 * legacy combined arithmetic, exact while stdout is frozen, which keeps a
 * cursor threaded before the upgrade working. Tail-wins applies only to a
 * FRESH read that chooses no window: an oversized backlog answers its
 * NEWEST bytes and admits how many older ones were skipped. Once the
 * caller threads a cursor (or sets max_bytes) the read is pagination —
 * every byte at or above the cursor is owed and nothing is destroyed; a
 * huge backlog is walked with more steps. An offset past the end of a
 * stream that has since SHRUNK (a shell truncating its own output)
 * restarts at that stream's true end, and because the other stream's
 * offset is its own, a shrink can never strand unread bytes. The cursor
 * never rewinds and never lies about position.
 *
 * The state line is honest about what the launch can see: a shell with no
 * exit file yet reports `running` (or `unknown` when even the process is
 * unprovable here), and the max-lifetime wall is enforced on read, so a call
 * after the wall reports the shell expired rather than forever running. An
 * exit code that names the output cap ({@see BackgroundShellRegistry::
 * OUTPUT_LIMIT_EXIT_CODE}) is narrated with its cause — `output limit
 * exceeded` — beside the number, because a bare 153 reads as mystery to the
 * model and a truncated log is the fact it needs to know.
 *
 * READ permission class: this tool writes nothing and kills nothing — it
 * tails a file this launch created. Its sibling {@see ShellStop} carries the
 * process-mutating half.
 */
#[BuiltInTool(name: 'ShellOutput', permission: ToolPermissionClass::Read, position: 37, gloss: 'read output from a background shell started with Bash run_in_background')]
final readonly class ShellOutput implements Tool, BuildsFromCatalog, TakesToolCallId, CarriesSessionState
{
    public const NAME = 'ShellOutput';

    private function __construct(
        private ?BackgroundShellRegistry $registry = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->backgroundShells);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read the output of a background shell started with Bash(run_in_background: true). Pass the shell_id Bash returned and the cursor from the previous answer to read only what is new since; the cursor is a pair "<stdout>.<stderr>", one offset per stream — pass it back exactly as given. With no cursor you get the tail of the backlog (skippedBytes says how much older output was too large to include). The answer ends with the next cursor to pass and the shell\'s state (running, finished with its exit code, stopped, or expired).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shell_id' => [
                    'type' => 'string',
                    'description' => 'The id Bash returned when it started the shell (sh-xxxxxxxx).',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Cursor to read from — pass back the exact [cursor: <stdout>.<stderr>] token from the previous ShellOutput answer (one offset per stream). Omit (or "0.0") to read from the start of the backlog. A bare integer is still accepted and read as the legacy combined offset.',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => sprintf(
                        'Maximum bytes of new output to return (default %d, maximum %d). Older bytes beyond the budget are skipped so the newest output always arrives.',
                        BackgroundShellRegistry::DEFAULT_READ_BYTES,
                        BackgroundShellRegistry::MAX_READ_BYTES,
                    ),
                    'minimum' => 1,
                ],
            ],
            'required' => ['shell_id'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $id = $args['shell_id'] ?? null;
        if (!\is_string($id) || preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1) {
            return $this->refuse('shell_id must be an id of the form sh-xxxxxxxx exactly as Bash returned it');
        }
        if ($this->registry === null) {
            return $this->refuse('no background shell registry is attached to this launch');
        }
        $cursor = self::parseCursor($args['cursor'] ?? null);
        $maxBytes = isset($args['max_bytes']) && \is_int($args['max_bytes']) ? $args['max_bytes'] : null;

        $answer = $this->registry->read($id, $cursor, $maxBytes);
        if ($answer['ok'] !== true) {
            return $this->refuse((string) $answer['error']);
        }

        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: $this->paint($id, $answer),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSessionState(): array
    {
        return [BackgroundShellRegistry::SESSION_STATE_KEY => $this->registry?->toArray() ?? []];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $this->registry?->merge($state[BackgroundShellRegistry::SESSION_STATE_KEY] ?? null);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function paint(string $id, array $answer): string
    {
        $stdout = (string) $answer['stdout'];
        $stderr = (string) $answer['stderr'];
        $body = '';
        if ($stdout !== '') {
            $body .= $stdout;
        }
        if ($stderr !== '') {
            $body .= ($body !== '' ? "\n" : '') . '[stderr]' . "\n" . $stderr;
        }
        if ($body === '') {
            $body = '(no new output)';
        }

        $state = match (true) {
            $answer['expired'] === true => 'expired (killed at the lifetime wall)',
            $answer['state'] === 'finished' && $answer['exitCode'] !== null => 'finished (exit ' . $answer['exitCode'] . ')',
            $answer['state'] === 'finished' => 'finished (exit code unknown)',
            default => (string) $answer['state'],
        };
        $exitNote = (string) ($answer['exitNote'] ?? '');
        if ($exitNote !== '') {
            $state .= ' — ' . $exitNote;
        }

        $head = sprintf('shell %s — %s', $id, $state);
        $notes = [];
        if ((int) $answer['skippedBytes'] > 0) {
            $notes[] = sprintf('%d older bytes skipped (%d unread bytes were available)', (int) $answer['skippedBytes'], (int) $answer['bytesAvailable']);
        }

        return $head . ($notes === [] ? '' : ' — ' . implode('; ', $notes)) . "\n" . $body . "\n"
            . sprintf('[cursor: %s]', (string) $answer['cursor']);
    }

    private function refuse(string $reason): ToolResult
    {
        return new ToolResult(toolCallId: '', content: $reason, isError: true);
    }

    /**
     * Accept the cursor spellings a caller can honestly bring: the token
     * this tool handed out (`"<out>.<err>"`), a legacy combined integer or
     * digit string, and nothing at all. Anything else reads from the start
     * — the door never silently picks a position it cannot name.
     */
    private static function parseCursor(mixed $raw): int|string
    {
        if (\is_int($raw)) {
            return \max(0, $raw);
        }
        if (\is_string($raw) && \preg_match('/^\s*(\d+)\.(\d+)\s*$/', $raw) === 1) {
            return \trim($raw);
        }
        if (\is_string($raw) && \preg_match('/^\s*\d+\s*$/', $raw) === 1) {
            return (int) \trim($raw);
        }

        return 0;
    }
}
