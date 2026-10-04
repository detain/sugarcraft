<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * One delegated run's transcript, as JSONL, written by the process that runs
 * it (roadmap P-C1, Appendix P §5.2).
 *
 * WHY A FILE AND NOT FRAMES. A run's activity frames are a monitor — coalesced,
 * clipped, at most four a second — and carry no tool output at all. The whole
 * conversation goes here instead: full results would multiply frame traffic
 * many times over, the file survives a parent crash, a grandchild of a
 * parallel batch can write it without relaying through two processes, and the
 * parent (or a server) tails it only while somebody is looking
 * ({@see AgentTranscriptTail}). When the run finishes, the PARENT reads it back
 * into a child session ({@see \SugarCraft\Crush\Agents\AgentManager::projectRemoteSubAgent()});
 * the writer never touches SQLite, so no forked process ever uses the
 * inherited PDO handle.
 *
 * WHERE: `~/.sugar-crush/subagents/<parentSessionId>/<agentId>.jsonl`, the
 * directory `0700` and the file `0600`, both created under `umask(0077)` —
 * a transcript holds whatever the sub-agent read. So `~` is
 * {@see HomeDirectory::owned()}, never the temp-directory stand-in: when this
 * process cannot establish that the home is its user's, there is no
 * {@see defaultRoot()} and no run keeps a log. A resumed run keeps writing
 * the SAME file ({@see \SugarCraft\Crush\Agents\SuspendedDelegations} carries
 * the path), so the model's `resume` and the stored session continue one
 * conversation.
 *
 * ONE LINE PER ITEM, appended under `flock(LOCK_EX)` in one write, so two
 * writers — parallel members of a batch, or a resume racing a straggler —
 * never interleave inside a line. A tool result is clipped to
 * {@see MAX_RESULT_BYTES} with `truncated: true`, and no line exceeds
 * {@see MAX_LINE_BYTES}, so a reader's window always holds a whole line.
 *
 * BEST EFFORT, NEVER FATAL. A log that cannot be written costs the stored
 * session, not the delegated run: every append answers false instead of
 * throwing.
 */
final class SubAgentTranscriptLog
{
    /** The directory under `~/.sugar-crush` every run's log lives in. */
    public const DIR_NAME = 'subagents';

    /** A tool result's content is clipped to this many bytes. */
    public const MAX_RESULT_BYTES = 16384;

    /** No encoded line is longer than this; see the class doc. */
    public const MAX_LINE_BYTES = 32768;

    public const T_USER = 'user';
    public const T_ASSISTANT = 'assistant';
    public const T_TOOL_CALL = 'tool_call';
    public const T_TOOL_RESULT = 'tool_result';
    public const T_THINKING = 'thinking';
    public const T_INBOX = 'inbox';
    public const T_STATUS = 'status';

    /** Every item type a line may carry. */
    public const TYPES = [
        self::T_USER,
        self::T_ASSISTANT,
        self::T_TOOL_CALL,
        self::T_TOOL_RESULT,
        self::T_THINKING,
        self::T_INBOX,
        self::T_STATUS,
    ];

    /**
     * Where {@see defaultRoot()} points instead of the home directory — set by
     * the test bootstrap, so a suite run never writes into the developer's own
     * `~/.sugar-crush` (the AuditHook pin's reason, E351).
     */
    private static ?string $pinnedRoot = null;

    private function __construct(
        private readonly string $path,
    ) {}

    /** The log at $path — an existing run's, e.g. one a resume continues. */
    public static function at(string $path): self
    {
        return new self($path);
    }

    /**
     * The log of run $agentId under session $parentSessionId, below $root
     * (default {@see defaultRoot()}). Both ids become one path segment each;
     * anything outside `[A-Za-z0-9._-]` is replaced, so no id can climb out of
     * the directory.
     *
     * @throws \RuntimeException when no root is given and there is no default
     */
    public static function forRun(string $parentSessionId, string $agentId, ?string $root = null): self
    {
        $root ??= self::defaultRoot() ?? throw new \RuntimeException('no owned home directory to keep a sub-agent transcript in');

        return new self(
            rtrim($root, '/')
            . '/' . self::segment($parentSessionId)
            . '/' . self::segment($agentId) . '.jsonl',
        );
    }

    /**
     * `~/.sugar-crush/subagents` under this user's OWNED home, the pinned test
     * directory, or null when neither exists (see the class doc).
     */
    public static function defaultRoot(): ?string
    {
        if (self::$pinnedRoot !== null) {
            return self::$pinnedRoot;
        }

        $home = HomeDirectory::owned();

        return $home === null ? null : $home . '/.sugar-crush/' . self::DIR_NAME;
    }

    /**
     * Point {@see defaultRoot()} at $root (null restores the home directory).
     * For the test bootstrap; production never calls it.
     */
    public static function pinDefaultRoot(?string $root): void
    {
        self::$pinnedRoot = $root;
    }

    /**
     * Whether $path is a log this class would have written below $root — the
     * check a reader applies to a path that arrived on a frame before opening
     * it: a `.jsonl` file, two segments below the root, no `..`.
     */
    public static function isLogPath(string $path, ?string $root = null): bool
    {
        $root ??= self::defaultRoot();
        if ($root === null) {
            return false;
        }
        $root = rtrim($root, '/') . '/';
        if (!str_starts_with($path, $root) || !str_ends_with($path, '.jsonl')) {
            return false;
        }

        $parts = explode('/', substr($path, \strlen($root)));

        return \count($parts) === 2
            && preg_match('/^[A-Za-z0-9._-]+$/', $parts[0]) === 1
            && preg_match('/^[A-Za-z0-9._-]+\.jsonl$/', $parts[1]) === 1
            && $parts[0] !== '..' && $parts[0] !== '.';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function user(string $text): bool
    {
        return $this->append(self::T_USER, ['text' => $text]);
    }

    public function assistant(string $text): bool
    {
        return $this->append(self::T_ASSISTANT, ['text' => $text]);
    }

    public function thinking(string $text): bool
    {
        return $this->append(self::T_THINKING, ['text' => $text]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function toolCall(string $callId, string $tool, array $args): bool
    {
        return $this->append(self::T_TOOL_CALL, ['callId' => $callId, 'tool' => $tool, 'args' => $args]);
    }

    public function toolResult(string $callId, string $tool, bool $ok, string $content): bool
    {
        $truncated = \strlen($content) > self::MAX_RESULT_BYTES;

        return $this->append(self::T_TOOL_RESULT, [
            'callId' => $callId,
            'tool' => $tool,
            'ok' => $ok,
            'content' => $truncated ? mb_strcut($content, 0, self::MAX_RESULT_BYTES, 'UTF-8') : $content,
            'truncated' => $truncated,
        ]);
    }

    public function status(string $status, string $outcome, ?string $error): bool
    {
        return $this->append(self::T_STATUS, ['status' => $status, 'outcome' => $outcome, 'error' => $error]);
    }

    /**
     * Append one item. False — never an exception — when it could not be
     * written; see the class doc.
     *
     * @param array<string, mixed> $fields
     */
    public function append(string $type, array $fields): bool
    {
        if (!\in_array($type, self::TYPES, true)) {
            return false;
        }

        $line = self::encode(['t' => $type, 'ts' => round(microtime(true), 3)] + $fields);
        if ($line === null) {
            return false;
        }

        $previous = umask(0o077);
        try {
            $dir = \dirname($this->path);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return false;
            }
            if (is_link($dir) || is_link($this->path)) {
                return false;
            }

            $handle = @fopen($this->path, 'ab');
            if ($handle === false) {
                return false;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    return false;
                }
                $written = fwrite($handle, $line);
                fflush($handle);
                flock($handle, LOCK_UN);

                return $written === \strlen($line);
            } finally {
                fclose($handle);
            }
        } finally {
            umask($previous);
        }
    }

    /**
     * One JSONL line no longer than {@see MAX_LINE_BYTES}: a text field that
     * would push it past is halved until it fits (escaping can multiply a
     * control-heavy result several times over).
     *
     * @param array<string, mixed> $item
     */
    private static function encode(array $item): ?string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $json = json_encode($item, $flags);
            if ($json === false) {
                return null;
            }
            if (\strlen($json) < self::MAX_LINE_BYTES) {
                return $json . "\n";
            }

            $field = isset($item['content']) && \is_string($item['content']) ? 'content'
                : (isset($item['text']) && \is_string($item['text']) ? 'text' : null);
            if ($field === null) {
                $item['args'] = ['truncated' => true];
                continue;
            }
            $item[$field] = mb_strcut($item[$field], 0, intdiv(\strlen($item[$field]), 2), 'UTF-8');
            $item['truncated'] = true;
        }

        return null;
    }

    private static function segment(string $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $id) ?? '';
        $safe = trim($safe, '.');

        return $safe === '' ? '_' : substr($safe, 0, 128);
    }
}
