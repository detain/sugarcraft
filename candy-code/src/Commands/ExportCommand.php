<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use RuntimeException;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tools\PathJail;

/**
 * Implements `/export [format] [file|--path <file>]`: write the transcript to
 * a LOCAL file with its RAW bytes (plan W4-B, audit 2026-10-21; the SEC-4
 * ruling's other half, and the discoverable save path the end-user reviews
 * asked for).
 *
 * WHY A SEPARATE VERB FROM `/share`. `/share` is the publish-shaped family:
 * every body it writes — its own local files AND its served pages — passes
 * through {@see ShareSession::serialize()}, where SEC-4 runs the
 * SecretRedactor, because a share artifact can reach other eyes. The ruling
 * also says "local export keeps raw bytes": a file that never leaves the
 * machine is the user's own record, and silently scrubbing an API key from
 * it buys nothing while quietly damaging the transcript. `/export` answers
 * that need by reading {@see ShareSession::serializeRaw()} — the same rows,
 * the same formats, minus the redactor. The name of the door is the security
 * model: `share` shares, `export` exports.
 *
 * The reply line carries {@see Lang}('cmd.export.raw-note') so the user is
 * told, once per export, that the file is unredacted.
 *
 * THE TEXT SWEEP. The plain-text format additionally passes the body through
 * {@see Sanitize::stripPrivateUse()} and {@see Sanitize::untrustedForDisplay()}
 * — the same hygiene the renderer applies to untrusted frames — because a
 * `.txt` transcript is routinely opened in editors and terminals that would
 * otherwise eat image-marker bytes and bidirectional overrides literally.
 * This is DISPLAY hygiene, not redaction: secrets survive it (the raw-bytes
 * promise), invisible formatting and control noise do not. The structured
 * formats (md/html/json) write byte-exact — their consumers parse them.
 *
 * TARGETS (the {@see /share} shape, so one shelf holds both families):
 *   - no path: `~/.candy-code/exports/transcript-<session-id>-<UTC
 *     timestamp>.<ext>` (0700 directory, 0600 file) — the `transcript-`
 *     prefix keeps a `/export` name from colliding with the `/share` name
 *     beside it;
 *   - a plain path: jailed — it must land inside the project root or the
 *     `~/.candy-code/` shelf, so a typo or a `../` cannot drop a transcript
 *     somewhere unexpected ({@see PathJail::resolveForCreate()}, `~` is
 *     expanded);
 *   - `--path <file>`: the explicit escape hatch — the user named the door,
 *     so relative anchors to the project root and absolute/`~/` paths write
 *     as typed, no jail (still 0600 atomic). A name given both ways at once
 *     is refused, never silently preferred.
 *
 * FILE NAME TOKENS: a named file may carry the save-name palette this repo
 * already ships ({@see MediaStore::TOKENS}). The arms an export can answer —
 * `date`, `time`, `job_timestamp` — fill in MediaStore's own formats
 * ({@see MediaStore}'s generator arms). Any other palette token stays
 * literal IN the name and the reply says so, the same degrade-visibly law
 * the media store pins for stale patterns: an export carries no seed, no
 * sampler, no image. Non-palette brackets stay literal without a note.
 *
 * EXTENSION: a named file without one receives the chosen format's
 * (`/export out/notes` → `notes.md` under markdown, `notes.txt` for the
 * default text); a directory receives the default file name.
 *
 * OVERWRITE: an explicit target replaces what is there — the same semantics
 * {@see AtomicFileWriter} gives `/share` — atomically (temp+rename, 0600),
 * and the reply always names the file that was written.
 *
 * READ-ONLY ON THE SESSION: the command writes a FILE, never the transcript;
 * it sits in `Chat::READ_ONLY_COMMANDS` so it still works in a locked
 * session (like `/share`) and has no undo target.
 *
 * Output goes to stdout, which {@see Chat::handleExportCommand()} captures
 * into the transcript.
 *
 * @mirrors charmbracelet/crush ShareCommand (target and format parsing shape)
 */
final class ExportCommand
{
    /** Default file name prefix — what keeps this shelf's names distinct from /share's. */
    public const FILE_PREFIX = 'transcript-';

    /** Palette arms an export can fill; every other {@see MediaStore::TOKENS} name stays literal. */
    private const FILLABLE_TOKENS = ['date', 'time', 'job_timestamp'];

    /** The flag spelling of the file name, and its unjailed meaning. */
    private const PATH_FLAG = '--path';

    /**
     * @param ?string $exportsDir Where an export with no path lands; null is
     *        `~/.candy-code/exports` under an OWNED home (a seam for tests).
     */
    public function __construct(private readonly ?string $exportsDir = null) {}

    /**
     * @param list<string> $args Command arguments: [format] [file] or [format] --path <file>
     *
     * @return int 0 on success, 1 on a refused export or failed write
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        $args = array_values(array_filter($args, static fn(string $a): bool => $a !== ''));

        $format = null;
        $path = null;
        $viaFlag = false;
        $literalTokens = [];

        for ($i = 0, $n = count($args); $i < $n; ++$i) {
            $arg = $args[$i];

            if (str_starts_with($arg, '-')) {
                if ($arg === self::PATH_FLAG || str_starts_with($arg, self::PATH_FLAG . '=')) {
                    if ($path !== null) {
                        $this->printError(Lang::t('cmd.export.conflict'));

                        return 1;
                    }
                    if ($arg === self::PATH_FLAG) {
                        $value = $args[$i + 1] ?? '';
                        if ($value === '') {
                            $this->printError(Lang::t('cmd.export.missing-path'));

                            return 1;
                        }
                        ++$i;
                        $path = $value;
                    } else {
                        $path = substr($arg, \strlen(self::PATH_FLAG . '='));
                        if ($path === '') {
                            $this->printError(Lang::t('cmd.export.missing-path'));

                            return 1;
                        }
                    }
                    $viaFlag = true;

                    continue;
                }

                $this->printError(Lang::t('cmd.export.bad-args', ['arg' => $arg]));

                return 1;
            }

            $parsed = $this->parseFormat($arg);
            if ($format === null && $parsed !== null) {
                $format = $parsed;

                continue;
            }
            if ($path === null && ($format !== null || $this->looksLikePath($arg))) {
                $path = $arg;

                continue;
            }
            $this->printError($format === null && !$this->looksLikePath($arg)
                ? Lang::t('cmd.export.bad-format', ['format' => $arg])
                : Lang::t('cmd.export.too-many'));

            return 1;
        }

        // The plan's default is the plain-text shape; a named file may still
        // pick the format through its extension, as /share does.
        $format ??= ($path !== null ? $this->formatFromExtension($path) : null) ?? ShareSession::FORMAT_TEXT;
        $session = new ShareSession($chat->history, $format);

        if ($session->messageCount() === 0) {
            $this->printError(Lang::t('cmd.export.nothing'));

            return 1;
        }

        try {
            $target = $this->targetPath($chat, $session, $path, $viaFlag, $literalTokens);
            $body = $session->serializeRaw();
            if ($format === ShareSession::FORMAT_TEXT) {
                // Display hygiene only — the raw-bytes promise is about
                // secrets, and secrets survive this sweep untouched.
                $body = Sanitize::untrustedForDisplay(Sanitize::stripPrivateUse($body));
            }
            AtomicFileWriter::write($target, $body, 0600);
        } catch (RuntimeException $e) {
            $this->printError($e->getMessage());

            return 1;
        }

        echo Lang::t('cmd.export.exported', ['count' => $session->messageCount(), 'format' => $format, 'target' => $target]) . "\n";
        echo Lang::t('cmd.export.raw-note') . "\n";
        if ($literalTokens !== []) {
            echo '  ' . Lang::t('cmd.export.unfilled', ['tokens' => implode(', ', array_map(
                static fn(string $name): string => '[' . $name . ']',
                $literalTokens,
            ))]) . "\n";
        }

        return 0;
    }

    /**
     * Parse a format argument, or null when it is not a format word.
     */
    private function parseFormat(string $arg): ?string
    {
        return match (strtolower($arg)) {
            'markdown', 'md' => ShareSession::FORMAT_MARKDOWN,
            'html', 'htm' => ShareSession::FORMAT_HTML,
            'json' => ShareSession::FORMAT_JSON,
            'jsonl' => ShareSession::FORMAT_JSONL,
            'text', 'txt', 'plain' => ShareSession::FORMAT_TEXT,
            default => null,
        };
    }

    /**
     * An argument that is not a format word is taken as the path only when it
     * is shaped like one; a bare unknown word is a mistyped format.
     */
    private function looksLikePath(string $arg): bool
    {
        return str_contains($arg, '/') || str_contains($arg, '.');
    }

    private function formatFromExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return $extension === '' ? null : $this->parseFormat($extension);
    }

    /**
     * @param list<string> $literalTokens out: palette names left in the name
     *
     * @throws RuntimeException When no safe destination can be named.
     */
    private function targetPath(Chat|CommandContext $chat, ShareSession $session, ?string $path, bool $viaFlag, array &$literalTokens): string
    {
        $fileName = $this->defaultFileName($chat, $session);

        if ($path === null) {
            return $this->exportsDirectory() . '/' . $fileName;
        }

        $path = $this->expandHome($this->expandTokens($path, $literalTokens));

        if ($viaFlag) {
            // The user named the escape hatch; no jail, just anchoring — to
            // the configured project root, never to a bare getcwd() (a
            // process-global cwd is not a session fact; see the Phase-0
            // root-resolution guard this shape satisfies).
            $target = $this->literalTarget($chat, $path);
            if ($target === null) {
                throw new RuntimeException(Lang::t('cmd.export.no-anchor'));
            }
        } else {
            $root = $chat->projectRoot();
            $target = $root === '' ? null : PathJail::resolveForCreate($root, $path);
            $target ??= $this->homeShelfTarget($path);
            if ($target === null) {
                throw new RuntimeException(
                    Lang::t('cmd.export.outside-root', [
                        'path' => $path,
                        'root' => $root === '' ? '' : " ({$root})",
                        'exports' => ShareCommand::EXPORTS_SUBDIR,
                    ]),
                );
            }
        }

        if (is_dir($target)) {
            return rtrim($target, '/') . '/' . $fileName;
        }
        if (pathinfo($target, \PATHINFO_EXTENSION) === '') {
            return $target . '.' . $session->fileExtension();
        }

        return $target;
    }

    /**
     * A positional path that misses the project jail is still welcome on the
     * tool's own shelf (`~/.candy-code/`), jailed through the home root and
     * then prefix-checked so only the shelf subtree passes.
     */
    private function homeShelfTarget(string $path): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }
        // The shelf is the tool's own home directory — EXPORTS_SUBDIR hangs
        // one level below it, so name the parent explicitly.
        $shelf = rtrim($home, '/') . '/.candy-code';
        $resolved = PathJail::resolveForCreate($home, $path);
        if ($resolved === null) {
            return null;
        }

        return $resolved === $shelf || str_starts_with($resolved, $shelf . '/')
            ? $resolved
            : null;
    }

    /**
     * Anchor a `--path` name: absolute (and `~/`, already expanded) writes as
     * typed; relative anchors to the configured project root — never to a
     * bare `getcwd()`, which is process-global, not a session fact (the
     * Phase-0 root-resolution guard). With no root and a relative name there
     * is nothing honest to anchor to, so the write is refused.
     */
    private function literalTarget(Chat|CommandContext $chat, string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $configured = $chat->projectRoot();

        return $configured === '' ? null : rtrim($configured, '/') . '/' . $path;
    }

    /**
     * Expand a leading `~` to the owned home; other paths pass untouched.
     * When no owned home can be named the spelling stays literal and lands
     * wherever the jail rules allow (refusing there is the honest answer).
     */
    private function expandHome(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/')) {
            return $path;
        }
        $home = HomeDirectory::owned();
        if ($home === null) {
            return $path;
        }

        return $path === '~' ? $home : rtrim($home, '/') . '/' . substr($path, 2);
    }

    /**
     * Fill the palette arms an export answers, in MediaStore's own formats;
     * every other bracketed {@see MediaStore::TOKENS} name stays literal and
     * comes back listed so a name typed against a stale media pattern
     * degrades visibly. Non-palette brackets are not the palette's business
     * and pass through unmentioned.
     *
     * @param list<string> $literalTokens out: palette names left in the name
     */
    private function expandTokens(string $path, array &$literalTokens): string
    {
        $expanded = preg_replace_callback(
            '/\[([a-z_]+)\]/',
            static function (array $m) use (&$literalTokens): string {
                if (!\in_array($m[1], MediaStore::TOKENS, true)) {
                    return $m[0];
                }
                if (\in_array($m[1], self::FILLABLE_TOKENS, true)) {
                    return match ($m[1]) {
                        'date' => date('Y-m-d'),
                        'time' => date('H-i-s'),
                        default => date('YmdHis'),
                    };
                }
                if (!\in_array($m[1], $literalTokens, true)) {
                    $literalTokens[] = $m[1];
                }

                return $m[0];
            },
            $path,
        ) ?? $path;

        // Jail integrity is structural, not checked: the capture is [a-z_]+
        // and MediaStore's own formats carry no '/', '.' or control bytes, so
        // expansion can never smuggle a path separator past resolveForCreate().

        return $expanded;
    }

    /**
     * @throws RuntimeException When the home directory is not this user's own.
     */
    private function exportsDirectory(): string
    {
        if ($this->exportsDir !== null) {
            return rtrim($this->exportsDir, '/');
        }

        // owned(), not path(): path() falls back to the world-writable temp
        // directory, and a transcript is the last thing to leave there.
        $home = HomeDirectory::owned();
        if ($home === null) {
            throw new RuntimeException(
                Lang::t('cmd.export.no-home'),
            );
        }

        return rtrim($home, '/') . '/' . ShareCommand::EXPORTS_SUBDIR;
    }

    private function defaultFileName(Chat|CommandContext $chat, ShareSession $session): string
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $chat->currentSessionId() ?? '') ?: 'session';
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        return self::FILE_PREFIX . "{$sessionId}-{$stamp}.{$session->fileExtension()}";
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "  ✗ {$message}\n";
        echo "\n";
        echo '  ' . Lang::t('cmd.export.usage') . "\n";
        echo "\n";
        echo '  ' . Lang::t('cmd.export.usage-hint', ['exports' => ShareCommand::EXPORTS_SUBDIR]) . "\n";
        echo "\n";
    }
}
