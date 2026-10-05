<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Support\Directories\DirectoryBrowser;
use SugarCraft\Crush\Support\Directories\DirectoryBrowserException;

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
 */
final class FsMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('fs.listDirs', Scope::Read, 'The child directories (names only, never files) of a directory under the browse root; needs serve --allow-dir-browse.', self::listDirs(...)));
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
}
