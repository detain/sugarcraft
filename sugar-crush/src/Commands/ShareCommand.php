<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use RuntimeException;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Share\ShareResult;
use SugarCraft\Crush\Share\ShareSession;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Tools\PathJail;

/**
 * Implements `/share [md|html|json|text] [path]`: export the current session
 * to a LOCAL file and name the path in the reply (roadmap X-35a).
 *
 * WHY LOCAL. `/share` used to "upload" through {@see \SugarCraft\Crush\Share\ShareUploader},
 * which has no backend and always throws, so the command failed on every run.
 * A transcript can hold secrets, so the useful, honest default is a file the
 * user owns:
 *
 *   - no path: `~/.sugar-crush/exports/<session-id>-<UTC timestamp>.<ext>`,
 *     the directory created 0700 and the file written 0600;
 *   - a path: resolved against the project root and JAILED to it with
 *     {@see PathJail::resolveForCreate()} (also 0600), so a typo or a `../`
 *     cannot drop a transcript somewhere unexpected. An existing directory
 *     receives the default file name.
 *
 * THE UPLOAD STAYS A DORMANT OPT-IN. Only when `SUGARCRUSH_SHARE_UPLOAD_URL`
 * (or the deprecated `SUGAR_CRUSH_SHARE_UPLOAD_URL`) names a host is the
 * uploader tried; it still always fails today, and the reply says so beside
 * the path of the local file, which is written either way.
 *
 * Output goes to stdout, which {@see Chat::handleShareCommand()} captures
 * into the transcript.
 *
 * @mirrors charmbracelet/<repo>.ShareCommand
 */
final class ShareCommand
{
    /** Exports directory under the user's home, created 0700. */
    public const EXPORTS_SUBDIR = '.sugar-crush/exports';

    /**
     * @param ?string $exportsDir Where an export with no path lands; null is
     *        `~/.sugar-crush/exports` under an OWNED home (a seam for tests).
     */
    public function __construct(private readonly ?string $exportsDir = null) {}

    /**
     * @param list<string> $args Command arguments: [format] [path]
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        $args = array_values(array_filter($args, static fn(string $a): bool => $a !== ''));

        $format = null;
        if ($args !== []) {
            $format = $this->parseFormat($args[0]);
            if ($format !== null) {
                array_shift($args);
            } elseif (!$this->looksLikePath($args[0])) {
                $this->printError(Lang::t('cmd.share.bad-format', ['format' => $args[0]]));
                return 1;
            }
        }

        $path = array_shift($args);
        if ($args !== []) {
            $this->printError(Lang::t('cmd.share.too-many'));
            return 1;
        }

        $format ??= ($path !== null ? $this->formatFromExtension($path) : null) ?? ShareSession::FORMAT_MARKDOWN;
        $session = new ShareSession($chat->history, $format);

        try {
            $target = $this->targetPath($chat, $session, $path);
            AtomicFileWriter::write($target, $session->serialize(), 0600);
        } catch (RuntimeException $e) {
            $this->printError($e->getMessage());
            return 1;
        }

        echo Lang::t('cmd.share.exported', ['count' => $session->messageCount(), 'format' => $format, 'target' => $target]) . "\n";

        $uploadBaseUrl = $this->getUploadBaseUrl();
        if ($uploadBaseUrl !== null) {
            try {
                ShareResult::create($session, $format, ShareSession::DEFAULT_EXPIRY_DAYS * 86400, $uploadBaseUrl);
                // ShareUploader::upload() is `never` and always throws, so
                // there is no success line to print until a real backend lands.
            } catch (RuntimeException $e) {
                echo "\n" . Lang::t('cmd.share.upload-failed', ['url' => $uploadBaseUrl, 'error' => $e->getMessage()]) . "\n";
            }
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
            'text', 'txt', 'plain' => ShareSession::FORMAT_TEXT,
            default => null,
        };
    }

    /**
     * A first argument that is not a format word is taken as the path only
     * when it is shaped like one; a bare unknown word is a mistyped format.
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
     * @throws RuntimeException When no safe destination can be named.
     */
    private function targetPath(Chat|CommandContext $chat, ShareSession $session, ?string $path): string
    {
        $fileName = $this->defaultFileName($chat, $session);

        if ($path === null) {
            return $this->exportsDirectory() . '/' . $fileName;
        }

        $root = $chat->projectRoot();
        $resolved = $root === '' ? null : PathJail::resolveForCreate($root, $path);
        if ($resolved === null) {
            throw new RuntimeException(
                Lang::t('cmd.share.outside-root', [
                    'path' => $path,
                    'root' => $root === '' ? '' : " ({$root})",
                    'exports' => self::EXPORTS_SUBDIR,
                ]),
            );
        }

        return is_dir($resolved) ? rtrim($resolved, '/') . '/' . $fileName : $resolved;
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
                Lang::t('cmd.share.no-home'),
            );
        }

        return rtrim($home, '/') . '/' . self::EXPORTS_SUBDIR;
    }

    private function defaultFileName(Chat|CommandContext $chat, ShareSession $session): string
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $chat->currentSessionId() ?? '') ?: 'session';
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        return "{$sessionId}-{$stamp}.{$session->fileExtension()}";
    }

    /**
     * The opt-in upload host, or null when none is configured (the default).
     *
     * The canonical variable is SUGARCRUSH_SHARE_UPLOAD_URL.
     * SUGAR_CRUSH_SHARE_UPLOAD_URL is the original spelling and one of only two
     * app variables that ever carried the underscore after SUGAR — every other
     * SUGARCRUSH_* variable this app reads does not (crush_code.md Phase 4
     * item 4). It keeps working for one release; the canonical name wins when
     * both are set. There is no default host any more (X-35a): unset means
     * "do not upload", not "upload to a public default".
     */
    private function getUploadBaseUrl(): ?string
    {
        foreach (['SUGARCRUSH_SHARE_UPLOAD_URL', 'SUGAR_CRUSH_SHARE_UPLOAD_URL'] as $name) {
            $envUrl = getenv($name);
            if (is_string($envUrl) && $envUrl !== '') {
                return $envUrl;
            }
        }

        return null;
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "  ✗ {$message}\n";
        echo "\n";
        echo '  ' . Lang::t('cmd.share.usage') . "\n";
        echo "\n";
        echo '  ' . Lang::t('cmd.share.usage-hint', ['exports' => self::EXPORTS_SUBDIR]) . "\n";
        echo "\n";
    }
}
