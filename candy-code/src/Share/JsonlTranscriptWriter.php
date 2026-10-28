<?php

declare(strict_types=1);

namespace SugarCraft\Code\Share;

use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Util\Exporter;

/**
 * Append-only JSONL transcript journal (C-SES-8).
 *
 * One file per session at `<root>/<project-slug>/<session-id>.jsonl`, the
 * root defaulting to `~/.candy-code/transcripts` (directories 0700, files
 * 0600). Each {@see save()} appends ONLY the rows past a high-water mark read
 * back from the file itself: every message line counts the mark up, and a
 * rewound session (fewer messages than the mark) appends a
 * `{"type":"rewind","to":N}` marker line that re-sets the mark instead of
 * rewriting the journal. Skipping marker lines, the remaining lines ARE
 * {@see Exporter::toJsonl()} output — the file replays as the export.
 *
 * A marker can never be confused with a message row: every message row —
 * concrete or interface, see `Message::toWire()` and the
 * `SugarCraft\Code\Messages\*::toArray()` shapes — carries a top-level `role`
 * key and no top-level `type`; the walk below demands both halves of that
 * asymmetry before honouring a marker.
 *
 * NOT a redaction boundary: this is the owner's local journal of their own
 * session and carries raw rows, like the raw `--path` export, not the share
 * body ({@see ShareSession::serialize()} redacts; this never did and does not
 * route through it).
 *
 * BEST EFFORT by contract: {@see save()} returns false on any failure and
 * never throws — the launch wiring calls the mirror after a store commit
 * (C-SES-8.g) and a broken journal must never fail the save it mirrors.
 */
final class JsonlTranscriptWriter
{
    /** Directory name under `~/.candy-code` that holds the journals. */
    public const DIR_NAME = 'transcripts';

    /** The `type` value of a high-water-mark rewind marker line. */
    public const REWIND_TYPE = 'rewind';

    /** Process flag the launch wiring sets (C-SES-8.g); default OFF. */
    private static bool $enabled = false;

    /** Test seam redirecting {@see defaultRoot()} away from the home dir. */
    private static ?string $pinnedRoot = null;

    /**
     * @param string|null $baseDir    journal root override (tests, callers with a shelf of their own)
     * @param string|null $projectRoot project the slug is taken from when {@see save()} passes none
     */
    public function __construct(
        private readonly ?string $baseDir = null,
        private readonly ?string $projectRoot = null,
    ) {
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Point {@see defaultRoot()} at $root (null restores the home directory).
     */
    public static function pinDefaultRoot(?string $root): void
    {
        self::$pinnedRoot = $root;
    }

    public static function defaultRoot(): ?string
    {
        if (self::$pinnedRoot !== null) {
            return self::$pinnedRoot;
        }
        $home = HomeDirectory::owned();

        return $home === null ? null : rtrim($home, '/') . '/.candy-code/' . self::DIR_NAME;
    }

    /**
     * The journal path for a session WITHOUT creating anything, so a hook
     * payload can name the file before the first save (C-SES-8.g).
     */
    public static function pathFor(string $sessionId, ?string $root = null, ?string $baseDir = null): string
    {
        $base = $baseDir ?? self::defaultRoot() ?? '';

        return rtrim($base, '/') . '/' . self::slugFor($root) . '/' . self::segment($sessionId) . '.jsonl';
    }

    /**
     * Mirror $messages into the journal for $sessionId, appending only what
     * the high-water mark has not seen. True when the journal already matches
     * or was extended; false on any I/O failure (never an exception).
     */
    public function save(string $sessionId, array $messages, ?string $projectRoot = null): bool
    {
        $base = $this->baseDir ?? self::defaultRoot();
        if ($base === null || $base === '') {
            return false;
        }
        $path = rtrim($base, '/') . '/' . self::slugFor($projectRoot ?? $this->projectRoot)
            . '/' . self::segment($sessionId) . '.jsonl';
        $dir = \dirname($path);

        if (is_link($dir) || is_link($path)) {
            return false;
        }

        $existing = is_file($path) ? @file_get_contents($path) : null;
        if ($existing === false) {
            return false;
        }
        $head = self::scanHighWater(\is_string($existing) ? $existing : '');

        $body = Exporter::toJsonl($messages);
        $rows = $body === '' ? [] : \explode("\n", substr($body, 0, -1));
        $count = \count($rows);

        if ($count === $head) {
            return true;
        }

        if ($count > $head) {
            $blob = '';
            foreach (\array_slice($rows, $head) as $line) {
                $blob .= $line . "\n";
            }
        } else {
            // Rewind: keep every byte already written and re-set the mark.
            $blob = '{"type":"' . self::REWIND_TYPE . '","to":' . $count . "}" . "\n";
        }

        $previous = umask(0o077);
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return false;
            }
            if (is_link($dir) || is_link($path)) {
                return false;
            }
            $fresh = !file_exists($path);
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return false;
            }
            try {
                if (!flock($handle, \LOCK_EX)) {
                    return false;
                }
                if (fwrite($handle, $blob) === false) {
                    return false;
                }
                fflush($handle);
                flock($handle, \LOCK_UN);
            } finally {
                fclose($handle);
            }
            if ($fresh) {
                @chmod($path, 0o600);
            }

            return true;
        } finally {
            umask($previous);
        }
    }

    /**
     * Replay the journal and return the message count it stands at: message
     * lines count up, `{"type":"rewind","to":N}` re-sets.
     */
    private static function scanHighWater(string $contents): int
    {
        $head = 0;
        foreach (\explode("\n", $contents) as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (\is_array($row) && !isset($row['role'])
                && ($row['type'] ?? null) === self::REWIND_TYPE
                && isset($row['to']) && \is_int($row['to'])) {
                $head = \max(0, $row['to']);
                continue;
            }
            ++$head;
        }

        return $head;
    }

    /**
     * One safe segment from a project root: its basename, or `default` when
     * no root is known. Two same-basename projects share a directory but each
     * keeps its own session-id file, so nothing collides inside a journal.
     */
    private static function slugFor(?string $root): string
    {
        if ($root === null || $root === '') {
            return 'default';
        }
        $name = basename(rtrim($root, '/'));

        return self::segment($name === '' ? '_' : $name);
    }

    /** Contain an id segment the way {@see \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog} does. */
    private static function segment(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $id) ?? '_';
        $clean = trim($clean, '.');
        if ($clean === '') {
            $clean = '_';
        }

        return substr($clean, 0, 128);
    }
}
