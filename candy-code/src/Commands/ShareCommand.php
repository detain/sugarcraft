<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use RuntimeException;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\SafeDelete;
use SugarCraft\Code\Tools\PathJail;

/**
 * Implements `/share`: export the session to a LOCAL file, and publish or
 * revoke SELF-HOSTED share pages (roadmap X-35a; SES-3, COMPARISON §6.5).
 *
 * WHY LOCAL FIRST. `/share` used to "upload" through ShareUploader, which
 * had no backend and always threw, so the upload half failed on every run;
 * the uploader is retired (see below). A transcript can hold secrets, so the
 * useful, honest default remains a file the user owns:
 *
 *   - no path: `~/.candy-code/exports/<session-id>-<UTC timestamp>.<ext>`,
 *     the directory created 0700 and the file written 0600;
 *   - a path: resolved against the project root and JAILED to it with
 *     {@see PathJail::resolveForCreate()} (also 0600), so a typo or a `../`
 *     cannot drop a transcript somewhere unexpected. An existing directory
 *     receives the default file name.
 *
 * `/share serve [days]` is the explicit user act that PUBLISHES: an HTML
 * snapshot of the transcript AS IT STANDS NOW is rendered and written to the
 * `shares` table ({@see ShareStore}), and the reply names the `/s/{token}`
 * path on the user's own `candy-code serve`. Nothing leaves the machine —
 * the WHY-LOCAL stance is preserved; only serving stays opt-in. Editing the
 * transcript afterwards changes nothing a reader sees; publishing the new
 * state is a second user act. `/share revoke [token]` kills a page on its
 * next request.
 *
 * THE UPLOADER IS RETIRED IN THIS COMMAND'S OWN WORDS. When
 * `CANDY_CODE_SHARE_UPLOAD_URL` (or a deprecated spelling) names a host,
 * the export still lands locally and the reply then LOUDLY refuses the
 * upload and names `/share serve` as its replacement — the key keeps being
 * read precisely so the removal is not silent.
 *
 * Output goes to stdout, which {@see Chat::handleShareCommand()} captures
 * into the transcript.
 *
 * @mirrors charmbracelet/crush ShareCommand
 */
final class ShareCommand
{
    /** Exports directory under the user's home, created 0700. */
    public const EXPORTS_SUBDIR = '.candy-code/exports';

    /** Subcommand word that publishes the snapshot. */
    public const SERVE_WORD = 'serve';

    /** Subcommand word that revokes a published snapshot. */
    public const REVOKE_WORD = 'revoke';

    /**
     * @param ?string $exportsDir Where an export with no path lands; null is
     *        `~/.candy-code/exports` under an OWNED home (a seam for tests).
     * @param ?ShareStore $shareStore Where snapshots are published; null
     *        resolves the store from the session database the context is
     *        open on (a seam for tests).
     */
    public function __construct(
        private readonly ?string $exportsDir = null,
        private readonly ?ShareStore $shareStore = null,
    ) {}

    /**
     * @param list<string> $args Command arguments: [format] [path],
     *        `serve [days]`, or `revoke [token]`
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        $args = array_values(array_filter($args, static fn(string $a): bool => $a !== ''));

        $word = strtolower($args[0] ?? '');
        if ($word === self::SERVE_WORD) {
            return $this->serve($chat, array_slice($args, 1));
        }
        if ($word === self::REVOKE_WORD) {
            return $this->revoke($chat, array_slice($args, 1));
        }

        return $this->export($chat, $args);
    }

    /**
     * The original X-35a half: render one of four formats and write the file.
     *
     * @param list<string> $args
     */
    private function export(Chat|CommandContext $chat, array $args): int
    {
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

        $uploadKey = $this->getRetiredUploadKey();
        if ($uploadKey !== null) {
            echo "\n" . Lang::t('cmd.share.upload-retired', ['key' => $uploadKey]) . "\n";
        }

        return 0;
    }

    /**
     * `/share serve [days]` — render the HTML snapshot NOW and publish it.
     *
     * The snapshot is rendered from `$chat->history` at this exact call:
     * the row stores rendered HTML, never a session reference to be
     * re-rendered later, which is the whole security model of §6.5.
     *
     * @param list<string> $args
     */
    private function serve(Chat|CommandContext $chat, array $args): int
    {
        if (count($args) > 1) {
            $this->printError(Lang::t('cmd.share.too-many'));

            return 1;
        }
        $days = ShareSession::DEFAULT_EXPIRY_DAYS;
        if ($args !== []) {
            $days = $this->parseDays($args[0]);
            if ($days === null) {
                $this->printError(Lang::t('cmd.share.bad-days', ['max' => ShareStore::MAX_EXPIRY_DAYS]));

                return 1;
            }
        }

        $session = new ShareSession($chat->history, ShareSession::FORMAT_HTML);
        if ($session->messageCount() === 0) {
            $this->printError(Lang::t('cmd.share.nothing'));

            return 1;
        }

        try {
            $store = $this->shareStoreFor($chat);
            $record = $store->publish(
                $chat->currentSessionId() ?? '',
                ShareSession::FORMAT_HTML,
                $session->serialize(),
                $days,
            );
        } catch (RuntimeException $e) {
            $this->printError($e->getMessage());

            return 1;
        }

        echo Lang::t('cmd.share.served', [
            'count' => $session->messageCount(),
            'url' => $this->serveUrl($record->path()),
            'days' => $days,
            'token' => $record->token,
        ]) . "\n";

        return 0;
    }

    /**
     * `/share revoke [token]` — kill a page on its next request. With no
     * token, revoke the session's only live share when there is exactly one,
     * and otherwise list what a token would choose between.
     *
     * @param list<string> $args
     */
    private function revoke(Chat|CommandContext $chat, array $args): int
    {
        if (count($args) > 1) {
            $this->printError(Lang::t('cmd.share.too-many'));

            return 1;
        }
        $store = $this->shareStoreFor($chat);

        if ($args !== []) {
            if ($store->revoke($args[0])) {
                echo Lang::t('cmd.share.revoked', ['token' => $args[0]]) . "\n";

                return 0;
            }
            $this->printError(Lang::t('cmd.share.revoke-unknown'));

            return 1;
        }

        $live = $store->liveForSession($chat->currentSessionId() ?? '');
        if ($live === []) {
            $this->printError(Lang::t('cmd.share.revoke-none'));

            return 1;
        }
        if (count($live) === 1) {
            $store->revoke($live[0]->token);
            echo Lang::t('cmd.share.revoked', ['token' => $live[0]->token]) . "\n";

            return 0;
        }

        echo Lang::t('cmd.share.revoke-many') . "\n";
        foreach ($live as $record) {
            echo '  ' . $record->token . '  (' . $record->format . ', '
                . gmdate('Y-m-d', $record->expiresAt) . ")\n";
        }

        return 1;
    }

    /**
     * The store publishes into the SAME database the session lives in: the
     * TUI act and the serve reader must resolve one file without either
     * re-deriving `~/.candy-code`.
     *
     * @throws RuntimeException When no session database can be named.
     */
    private function shareStoreFor(Chat|CommandContext $chat): ShareStore
    {
        if ($this->shareStore !== null) {
            return $this->shareStore;
        }
        $store = $chat instanceof CommandContext ? $chat->sessionStore : $chat->sessionStore();
        if ($store instanceof SessionStore || $store instanceof EnhancedSessionStore) {
            return ShareStore::atPath($store->path());
        }

        throw new RuntimeException(Lang::t('cmd.share.no-store'));
    }

    /**
     * The published path beside the local serve URL when a live discovery
     * record names one — `serve` publishes that record on the same machine
     * this command runs on. Without it the reply still carries the path and
     * the token, which is everything `candy-code serve url` needs beside it.
     */
    private function serveUrl(string $path): string
    {
        $home = HomeDirectory::owned();
        if ($home !== null) {
            $state = StateDir::existing(rtrim($home, '/') . '/.candy-code/server');
            $record = $state === null ? null : DiscoveryFile::read($state);
            if ($record !== null && $record->isLive()) {
                return rtrim($record->url, '/') . $path;
            }
        }

        return $path;
    }

    /**
     * A positive whole number of days within {@see ShareStore::MAX_EXPIRY_DAYS},
     * or null for anything else — including `01`, whose leading zero says the
     * argument was typed for another purpose.
     */
    private function parseDays(string $arg): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,2}$/', $arg) !== 1) {
            return null;
        }
        $days = (int) $arg;

        return $days <= ShareStore::MAX_EXPIRY_DAYS ? $days : null;
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
     * The name of a RETIRED upload-host variable the user still has set, or
     * null when none is (the default). The upload itself is gone — this
     * reader survives so the reply can refuse the expectation loudly and
     * name `/share serve` instead, not to act on the value.
     *
     * The canonical variable is CANDY_CODE_SHARE_UPLOAD_URL.
     * `SUGARCRUSH_SHARE_UPLOAD_URL` is the pre-rename spelling and // @rename-keep
     * `SUGAR_CRUSH_SHARE_UPLOAD_URL` its original one-of-two underscore variant // @rename-keep
     * (crush_code.md Phase 4 item 4); both still reach this reader —
     * bin/candy-code folds the old prefixes onto CANDY_CODE_* via LegacyEnv
     * before anything reads env.
     */
    private function getRetiredUploadKey(): ?string
    {
        foreach (['CANDY_CODE_SHARE_UPLOAD_URL', 'SUGARCRUSH_SHARE_UPLOAD_URL', 'SUGAR_CRUSH_SHARE_UPLOAD_URL'] as $name) { // @rename-keep legacy dual-read
            $envUrl = getenv($name);
            if (is_string($envUrl) && $envUrl !== '') {
                return $name;
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

    /**
     * Delete export files older than $days, returning how many went (review
     * rv-devops-4: the retention sweep covered sessions only, so exports
     * accumulated forever). Called from the launch retention path
     * ({@see \SugarCraft\Code\Agents\AgentManager::pruneSessionArtifacts()})
     * only when `CANDY_CODE_SESSION_RETENTION_DAYS` is positive — opt-in, like
     * the session sweep it joins.
     *
     * Age basis is the file's own mtime; the layout under the exports root is
     * flat files (one per share), so no tree is walked. Symbolic links and
     * anything that is not a plain file are left alone, and every failure is
     * swallowed — an export that cannot be deleted today is not a reason to
     * disturb the launch, mirroring the sweep's session-side posture.
     *
     * @param string|null $exportsRoot where to sweep; null is the default
     *        `~/.candy-code/exports` under an owned home (a seam for tests)
     */
    public static function sweepExpiredExports(int $days, ?int $now = null, ?string $exportsRoot = null): int
    {
        if ($days <= 0) {
            return 0;
        }

        if ($exportsRoot === null) {
            $home = HomeDirectory::owned();
            if ($home === null) {
                return 0;
            }
            $exportsRoot = rtrim($home, '/') . '/' . self::EXPORTS_SUBDIR;
        }

        if (!is_dir($exportsRoot)) {
            return 0;
        }

        // ROOTFIX H1/H2: a sweep aimed at '/' — or any base that is not a real
        // directory strictly below it — removes nothing, ever. Under root the
        // uid checks elsewhere wave '/' through (root owns it); this is the
        // refusal that does not depend on who is running.
        if (!SafeDelete::baseIsSane($exportsRoot)) {
            return 0;
        }

        $cutoff = ($now ?? time()) - $days * 86400;
        $removed = 0;
        foreach (scandir($exportsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $exportsRoot . '/' . $entry;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime <= $cutoff) {
                if (@unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
