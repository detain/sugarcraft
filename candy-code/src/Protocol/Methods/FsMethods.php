<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\Directories\DirectoryBrowser;
use SugarCraft\Code\Support\Directories\DirectoryBrowserException;

/**
 * `fs.listDirs` — the directory listing behind the web UI's new-session
 * picker: child DIRECTORY names under the server's browse root, never a file
 * name or a file's content ({@see DirectoryBrowser}, the same listing the
 * TUI's `/new` picker shows).
 *
 * OPT-IN. Off unless the server was started with `--allow-dir-browse` (or
 * `server.dirBrowse`), because it shows every signed-in client the directory
 * names under the browse root; off, it answers `dir_browse_disabled`. Every
 * path stays inside `--browse-root` (default the user's home): `..` cannot
 * climb out and a link is followed, then checked again.
 *
 * `fs.search` — the file-name search behind the web composer's `@`-mention
 * popup (plan_comparison WEB-5b). It is NOT opt-in and NOT browse-rooted: it
 * lists the files of the CALLER'S OWN WORKSPACE, the same project root
 * `files.read` is jailed to ({@see FilesMethods::root()},
 * {@see ContainedPath::within()}),
 * and for exactly the same reason — a signed-in client that can open a
 * session in a workspace can already read any file in it, so seeing the
 * workspace's file NAMES leaks nothing the workspace grant does not already
 * give. It reuses the TUI's `@`-index ({@see MentionIndex}): the tracked+
 * non-ignored file listing ranked by a Smith-Waterman fuzzy match, so the
 * popup the browser shows and the popup the terminal shows float the same
 * file for the same typed partial. Every result is root-relative and the
 * walk re-checks containment, so `..` and links cannot reach outside.
 */
final class FsMethods
{
    /** Rows one `fs.search` answers by default. */
    public const SEARCH_DEFAULT_LIMIT = 20;

    /** Rows one `fs.search` may answer at most (the TUI popup's own cap). */
    public const SEARCH_MAX_LIMIT = MentionIndex::POPUP_LIMIT;

    /** Directories and files one walk-answered `fs.search` reads at most. */
    private const WALK_MAX_ENTRIES = 20_000;

    /** Candidate paths a walk gathers at most before ranking. */
    private const WALK_MAX_CANDIDATES = 5_000;

    /** How deep below the workspace root the walk looks. */
    private const WALK_MAX_DEPTH = 24;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('fs.listDirs', Scope::Read, 'The child directories (names only, never files) of a directory under the browse root; needs serve --allow-dir-browse.', self::listDirs(...)));
        $registry->add(MethodSpec::new('fs.search', Scope::Read, 'Workspace file names ranked against a partial (the web composer @-mention popup); jailed to the project root.', self::search(...)));
    }

    /**
     * The browser a dir-browse call runs under, or the refusal when browsing
     * is off.
     *
     * @throws RpcError
     */
    public static function browser(ServerConfig $config): DirectoryBrowser
    {
        if (!$config->dirBrowse || $config->browseRoot === null) {
            throw RpcError::of(ErrorCode::Forbidden, Lang::t('serve.dir_browse.disabled'), 'dir_browse_disabled');
        }
        try {
            return DirectoryBrowser::new($config->browseRoot);
        } catch (DirectoryBrowserException $e) {
            throw self::refusal($e);
        }
    }

    /** A {@see DirectoryBrowserException} as the RPC error a client branches on. */
    public static function refusal(DirectoryBrowserException $e): RpcError
    {
        return $e->reason === DirectoryBrowserException::REASON_OUTSIDE_ROOT
            ? RpcError::of(ErrorCode::Forbidden, $e->getMessage(), $e->reason)
            : RpcError::notFound($e->getMessage(), $e->reason);
    }

    /** @return array<string, mixed> */
    private static function listDirs(CallContext $call, Params $params): array
    {
        $browser = self::browser($call->server->config());
        try {
            $listing = $browser->list($params->optionalString('path', 4096) ?? '', $params->bool('showHidden'));
        } catch (DirectoryBrowserException $e) {
            throw self::refusal($e);
        }

        return ['root' => $browser->root(), ...$listing->toArray()];
    }

    /**
     * The `@`-popup listing for the workspace this call runs against.
     *
     * Git-backed roots answer through {@see MentionIndex} itself — the same
     * memoized listing and the same fuzzy ranking the TUI popup shows, so the
     * two surfaces cannot drift on what `@srch` floats. A root with no git
     * listing (not a repo, or git refusing) falls back to a bounded walk of
     * the directory tree: candidates pre-filtered to a case-insensitive
     * substring of the partial — the one stated divergence, a subsequence
     * the matcher would have taken but the substring screen never surfaced.
     * Every path the answer carries is workspace-relative, so nothing here
     * can name a location outside the root.
     *
     * @return array<string, mixed>
     *
     * @throws RpcError when this server serves no project root
     */
    private static function search(CallContext $call, Params $params): array
    {
        $root = FilesMethods::root($call);
        $query = \trim($params->optionalString('query', 4096) ?? '');
        $limit = $params->int('limit', self::SEARCH_DEFAULT_LIMIT, 1, self::SEARCH_MAX_LIMIT);

        if (MentionIndex::files($root) !== []) {
            $ranked = array_column(MentionIndex::rank($query, $root, $limit + 1), 'path');
        } else {
            $ranked = array_column(
                MentionIndex::rankPaths($query, self::walk($root, $query, $params->bool('showHidden')), $limit + 1),
                'path',
            );
        }

        return [
            'root' => $root,
            'matches' => array_values(array_slice($ranked, 0, $limit)),
            'truncated' => \count($ranked) > $limit,
        ];
    }

    /**
     * Root-relative paths of the regular files under the workspace root,
     * path-sorted: the non-git listing the ranked search reads. Dot entries
     * are skipped unless asked (so `.git` never surfaces its internals),
     * links are never followed (a link to outside is not a file of this
     * workspace), and the walk stops at {@see WALK_MAX_ENTRIES} read entries,
     * {@see WALK_MAX_CANDIDATES} candidates or {@see WALK_MAX_DEPTH} levels —
     * a huge tree answers with a cut list, not with a stalled server.
     *
     * @return list<string>
     */
    private static function walk(string $root, string $query, bool $showHidden): array
    {
        $needle = \mb_strtolower($query);
        $paths = [];
        $read = 0;

        /** @var list<array{0: string, 1: int}> $stack absolute directory, depth */
        $stack = [[$root, 0]];
        while ($stack !== [] && \count($paths) < self::WALK_MAX_CANDIDATES) {
            /** @var array{0: string, 1: int} $frame */
            $frame = \array_pop($stack);
            [$dir, $depth] = $frame;
            // Every frame is built by concatenating non-link components onto
            // the resolved root (links are skipped at readdir below), so it is
            // inside by construction; the re-check is the browser's own
            // discipline stated out loud, guarding a future edit that lets a
            // component become attacker-influenced.
            if (!ContainedPath::within($dir, $root)) {
                continue;
            }
            $handle = @\opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($entry = \readdir($handle)) !== false) {
                if (++$read > self::WALK_MAX_ENTRIES || \count($paths) >= self::WALK_MAX_CANDIDATES) {
                    \closedir($handle);

                    return self::sortedRelative($paths);
                }
                if ($entry === '.' || $entry === '..' || ($entry[0] === '.' && !$showHidden)) {
                    continue;
                }
                $absolute = $dir . '/' . $entry;
                if (\is_link($absolute)) {
                    continue;
                }
                if (\is_dir($absolute)) {
                    if ($depth < self::WALK_MAX_DEPTH) {
                        $stack[] = [$absolute, $depth + 1];
                    }

                    continue;
                }
                if (!\is_file($absolute)) {
                    continue;
                }
                $relative = \substr($absolute, \strlen($root) + 1);
                if ($needle !== '' && !\str_contains(\mb_strtolower($relative), $needle)) {
                    continue;
                }
                $paths[] = $relative;
            }
            \closedir($handle);
        }

        return self::sortedRelative($paths);
    }

    /**
     * The walk's candidates in listing order, so an empty partial's head is
     * stable across keystrokes.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function sortedRelative(array $paths): array
    {
        \sort($paths, SORT_STRING);

        return $paths;
    }
}
