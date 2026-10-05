<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Tools\PathJail;

/**
 * Watch the project for `AI!` / `AI?` comments and turn them into a prompt
 * (roadmap 5.14i, aider's `--watch-files`).
 *
 * You write the instruction where the change belongs — `// make this retry on
 * a timeout AI!` — save the file, and the idle TUI sends a prompt that names
 * every "AI" comment it knows of and attaches their files. A comment ending
 * (or starting) with `AI!` asks for a change, `AI?` asks a question; a plain
 * `AI` comment is context and is listed with them, but sends nothing on its
 * own.
 *
 * OPT-IN: the `watchFiles` key ({@see SETTINGS_KEY}) is off by default and
 * USER-only. A checked-out repository can never turn it on, because what it
 * does is make text in the repository's files a prompt the agent acts on.
 *
 * ONLY WHAT CHANGES AFTER LAUNCH. The first {@see poll()} records every file's
 * stamp and reads nothing, so an `AI!` already committed in a cloned tree does
 * not fire on start-up; a comment fires once it is saved while the watcher is
 * running. Each action comment fires ONCE: it is remembered (by file and
 * text) until it disappears from its file, so the agent's own edits to a file
 * that still holds it — or the user touching another line — do not send it
 * again, and removing it then writing it back does.
 *
 * NON-BLOCKING BY BOUND, not by a thread. `Chat::subscriptions()` polls on a
 * {@see POLL_SECONDS} tick, and only while the chat is idle — no turn in
 * flight, an empty input box, nothing queued and no modal up — so a prompt is
 * never sent over a draft or into a running turn. A poll is one `lstat` per
 * file, at most {@see MAX_FILES} of them (dot-directories, `vendor/` and
 * `node_modules/` are not walked, and no symlink is followed, file or
 * directory), and it reads only the files whose stamp moved, each at most
 * {@see MAX_FILE_BYTES} and re-resolved through {@see PathJail} against the
 * root first. A tree larger than the bound is watched up to it;
 * {@see truncated()} says so.
 *
 * The prompt is dispatched like a typed one — the same submit pipeline, so
 * the turn hooks, the permission gate and the `@file` attachment bounds
 * ({@see \SugarCraft\Crush\Host\TurnController::userTurnMessage()}) all apply.
 *
 * Mirrors Aider-AI/aider.FileWatcher (`aider/watch.py`).
 */
final class AiCommentWatcher
{
    /** The settings key that turns the watcher on (a user-tier Bool, default false). */
    public const SETTINGS_KEY = 'watchFiles';

    /** The {@see \SugarCraft\Core\Subscriptions} id `Chat::subscriptions()` declares the poll under. */
    public const SUBSCRIPTION = 'crush.ai-comment-watch';

    /** Seconds between polls while the chat is idle. */
    public const POLL_SECONDS = 2.0;

    /** Most files one poll stats; the rest of a larger tree is not watched. */
    public const MAX_FILES = 5000;

    /** Largest file read for comments; a bigger one is skipped. */
    public const MAX_FILE_BYTES = 256 * 1024;

    /** Most files one prompt names (each is attached as an `@` mention). */
    public const MAX_PROMPT_FILES = 10;

    /** Most comments one file contributes to the prompt. */
    public const MAX_COMMENTS_PER_FILE = 20;

    /** Longest comment line quoted back, characters. */
    public const MAX_LINE_CHARS = 200;

    /** Directories never walked besides every dot-directory. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '__pycache__'];

    /**
     * A comment holding "AI" at either end of its text: `#`, `//`, `--`, `;`,
     * `/*` and `<!--` openers, anywhere on the line (a trailing comment after
     * code counts). Aider's pattern, plus the block openers.
     */
    private const COMMENT_PATTERN = '~(?:#|//|--|;+|/\*+|<!--)\s*(ai\b.*|.*\bai[?!]?)$~i';

    /** @var array<string, self> one watcher per project root, for the life of the process */
    private static array $shared = [];

    /** @var array<string, string> relative path => "mtime:size" */
    private array $stamps = [];

    /** @var array<string, list<array{line: int, text: string, mark: string}>> relative path => its AI comments */
    private array $comments = [];

    /** @var array<string, array<string, true>> relative path => action comment text => already sent */
    private array $fired = [];

    private bool $primed = false;

    private bool $truncated = false;

    private function __construct(private readonly string $root)
    {
    }

    public static function new(string $root): self
    {
        return new self(rtrim($root, '/') === '' ? '/' : rtrim($root, '/'));
    }

    /**
     * The watcher for $root that outlives every `Chat` value: the model is
     * immutable and rebuilt on each Msg, while the stamps and the sent set
     * must persist across them, as `StatusLineCommand`'s runner does.
     */
    public static function shared(string $root): self
    {
        return self::$shared[$root] ??= self::new($root);
    }

    /** Drop every shared watcher — between tests that share one process. */
    public static function forgetShared(): void
    {
        self::$shared = [];
    }

    /**
     * Whether the merged settings turn the watcher on. Only a literal `true`
     * does: a wrong-typed hand edit leaves it off, the default.
     *
     * @param array<string, mixed> $config
     */
    public static function enabled(array $config): bool
    {
        return ($config[self::SETTINGS_KEY] ?? false) === true;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Whether the last walk stopped at {@see MAX_FILES} with files left over. */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * One poll: the prompt to send when a saved file holds an action comment
     * not sent before, else null. The first poll only records the tree.
     */
    public function poll(): ?string
    {
        clearstatcache();
        $current = $this->walk();

        if (!$this->primed) {
            $this->primed = true;
            $this->stamps = $current;

            return null;
        }

        foreach (array_diff_key($this->stamps, $current) as $gone => $_) {
            unset($this->comments[$gone], $this->fired[$gone]);
        }

        $trigger = false;
        foreach ($current as $relative => $stamp) {
            if (($this->stamps[$relative] ?? null) === $stamp) {
                continue;
            }

            $comments = $this->read($relative);
            $actions = [];
            foreach ($comments as $comment) {
                if ($comment['mark'] !== '') {
                    $actions[$comment['text']] = true;
                }
            }

            // A sent comment the file no longer holds is forgotten, so writing
            // it back is a new request.
            $sent = array_intersect_key($this->fired[$relative] ?? [], $actions);
            if (array_diff_key($actions, $sent) !== []) {
                $trigger = true;
            }
            $this->fired[$relative] = $sent;

            if ($comments === []) {
                unset($this->comments[$relative]);
            } else {
                $this->comments[$relative] = $comments;
            }
        }
        $this->stamps = $current;

        if (!$trigger) {
            return null;
        }

        foreach ($this->comments as $relative => $comments) {
            foreach ($comments as $comment) {
                if ($comment['mark'] !== '') {
                    $this->fired[$relative][$comment['text']] = true;
                }
            }
        }

        return self::prompt($this->comments);
    }

    /**
     * The "AI" comments in $content, one per line that holds one. `mark` is
     * `!` (asks for a change), `?` (asks a question) or `` (context only);
     * `text` is the whole line, trimmed and bounded, as the prompt quotes it.
     *
     * @return list<array{line: int, text: string, mark: string}>
     */
    public static function commentsIn(string $content): array
    {
        if (stripos($content, 'ai') === false) {
            return [];
        }

        $found = [];
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            $bare = preg_replace('~\s*(?:\*/|-->)\s*$~', '', rtrim($line)) ?? '';
            if (preg_match(self::COMMENT_PATTERN, $bare, $match) !== 1) {
                continue;
            }

            $body = strtolower(trim((string) preg_replace('~^(?:#|//|--|;+|/\*+|<!--|\s)+~', '', $match[1])));
            $mark = '';
            foreach (['!', '?'] as $candidate) {
                if (str_starts_with($body, 'ai' . $candidate) || str_ends_with($body, 'ai' . $candidate)) {
                    $mark = $candidate;
                    break;
                }
            }

            $text = trim($line);
            if (mb_strlen($text, 'UTF-8') > self::MAX_LINE_CHARS) {
                $text = mb_substr($text, 0, self::MAX_LINE_CHARS - 1, 'UTF-8') . '…';
            }
            $found[] = ['line' => $index + 1, 'text' => $text, 'mark' => $mark];
        }

        return $found;
    }

    /**
     * The prompt for $comments (relative path => its comments): what to do,
     * then each file as an `@` mention — so it is attached under the `@file`
     * bounds — with its comment lines quoted in backticks, which keeps an `@`
     * or `$` inside a comment from being read as a mention of its own.
     *
     * A change request when any comment is `AI!`; a question otherwise.
     *
     * @param array<string, list<array{line: int, text: string, mark: string}>> $comments
     */
    public static function prompt(array $comments): string
    {
        ksort($comments, SORT_STRING);

        $action = false;
        foreach ($comments as $list) {
            foreach ($list as $comment) {
                $action = $action || $comment['mark'] === '!';
            }
        }

        $lines = [$action
            ? 'I left instructions for you in comments marked "AI" in the files below. Follow them, '
                . 'then remove every "AI" comment you acted on from the code.'
            : 'I left questions for you in comments marked "AI" in the files below. Answer them; '
                . 'change no code unless a comment asks you to.'];

        $files = 0;
        foreach ($comments as $relative => $list) {
            if (++$files > self::MAX_PROMPT_FILES) {
                $lines[] = '';
                $lines[] = sprintf('(%d more file(s) with "AI" comments were left out.)', \count($comments) - self::MAX_PROMPT_FILES);
                break;
            }

            $lines[] = '';
            $lines[] = '@' . (preg_match('/\s/', $relative) === 1 ? '"' . $relative . '"' : $relative);
            foreach (\array_slice($list, 0, self::MAX_COMMENTS_PER_FILE) as $comment) {
                $lines[] = sprintf('- line %d: `%s`', $comment['line'], str_replace('`', "'", $comment['text']));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Every watched file's stamp, relative to the root, at most
     * {@see MAX_FILES}. No symlink is followed: a link can graft any tree on,
     * or name a file outside the project whose text would become a prompt.
     *
     * @return array<string, string>
     */
    private function walk(): array
    {
        $this->truncated = false;
        $stamps = [];
        $pending = [''];
        while ($pending !== []) {
            $relativeDir = array_pop($pending);
            $absoluteDir = $relativeDir === '' ? $this->root : $this->root . '/' . $relativeDir;
            $entries = @scandir($absoluteDir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;
                $absolute = $this->root . '/' . $relative;
                if (is_link($absolute)) {
                    continue;
                }
                if (is_dir($absolute)) {
                    if ($entry[0] !== '.' && !\in_array($entry, self::SKIPPED_DIRECTORIES, true)) {
                        $pending[] = $relative;
                    }

                    continue;
                }

                if (\count($stamps) >= self::MAX_FILES) {
                    $this->truncated = true;

                    return $stamps;
                }

                $stat = @stat($absolute);
                if ($stat !== false && is_file($absolute)) {
                    $stamps[$relative] = $stat['mtime'] . ':' . $stat['size'];
                }
            }
        }

        return $stamps;
    }

    /**
     * The comments in one file, or none when it is a link, resolves outside
     * the root, is too large, unreadable or binary (a NUL in its first 8 KiB).
     * The walk already refused links; the checks hold anyway, because the file
     * can be swapped for one between the walk and the read.
     *
     * @return list<array{line: int, text: string, mark: string}>
     */
    private function read(string $relative): array
    {
        if (is_link($this->root . '/' . $relative)) {
            return [];
        }
        $absolute = PathJail::resolve($this->root, $relative);
        if ($absolute === null) {
            return [];
        }
        $size = @filesize($absolute);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return [];
        }

        $content = @file_get_contents($absolute);
        if ($content === false || str_contains(substr($content, 0, 8192), "\0")) {
            return [];
        }

        return self::commentsIn($content);
    }
}
