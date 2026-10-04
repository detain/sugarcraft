<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * Serves the web UI's built files from one directory (Appendix O §7.3):
 * `--web-root`, `SUGARCRUSH_SERVER_WEB_ROOT`, or the installed
 * `sugarcraft/sugar-crush-web` package's `dist/`.
 *
 * THE URL IS THE ATTACKER'S, so the file it names is resolved and then held to
 * the root with {@see ContainedPath::within()} — after symlinks, so a link in
 * the build pointing out of it is refused like a `..` would be. Dot-segments
 * and dotfiles are refused before that, NUL bytes outright. GET and HEAD only.
 *
 * A path with no file extension that names no file is the SPA's own route
 * (`/session/abc`), answered with `index.html` so a reload deep in the app
 * works; a missing `*.js` is a plain 404 rather than HTML a browser would try
 * to run as a script.
 *
 * NO WEB ROOT (the package is not installed, or `--no-web`): `/` answers a
 * one-page notice saying how to get the UI, and the API and WebSocket work
 * regardless.
 */
final class StaticFiles
{
    /** @var array<string, string> */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        'wasm' => 'application/wasm',
    ];

    /** Larger than any sane bundle file; a bigger one is not served from memory. */
    private const MAX_FILE_BYTES = 16 * 1024 * 1024;

    private function __construct(private readonly ?string $root)
    {
    }

    /** Files under $root, which must be an existing directory; null serves the notice page. */
    public static function new(?string $root): self
    {
        if ($root === null) {
            return new self(null);
        }

        $real = \realpath($root);
        if ($real === false || !\is_dir($real)) {
            throw new \InvalidArgumentException(\sprintf('web root "%s" is not a directory', $root));
        }

        return new self($real);
    }

    public function root(): ?string
    {
        return $this->root;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Responses::error(405, 'method_not_allowed', 'method not allowed', ['Allow' => 'GET, HEAD']);
        }

        $path = \rawurldecode($request->getUri()->getPath());
        if ($this->root === null) {
            return $path === '/' || $path === '/index.html'
                ? new Response(200, ['Content-Type' => self::TYPES['html'], 'Cache-Control' => 'no-store'], self::NOTICE_PAGE)
                : Responses::error(404, 'not_found', 'not found');
        }

        $relative = self::safeRelative($path);
        if ($relative === null) {
            return Responses::error(404, 'not_found', 'not found');
        }

        $file = $this->root . ($relative === '' ? '' : '/' . $relative);
        if (\is_dir($file)) {
            $file .= '/index.html';
        } elseif (!\is_file($file) && \pathinfo($relative, \PATHINFO_EXTENSION) === '') {
            $file = $this->root . '/index.html';
        }

        if (!\is_file($file) || !ContainedPath::within($file, $this->root)) {
            return Responses::error(404, 'not_found', 'not found');
        }

        $size = @\filesize($file);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return Responses::error(404, 'not_found', 'not found');
        }

        $body = @\file_get_contents($file);
        if ($body === false) {
            return Responses::error(404, 'not_found', 'not found');
        }

        $extension = \strtolower(\pathinfo($file, \PATHINFO_EXTENSION));
        $hashed = \str_starts_with($relative, 'assets/');

        // A HEAD answer keeps its Content-Length and loses the body in
        // react/http itself, so one response serves both methods.
        return new Response(
            200,
            [
                'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
                // Vite fingerprints everything under assets/; the shell page
                // must be re-fetched so a new build is picked up.
                'Cache-Control' => $hashed ? 'public, max-age=31536000, immutable' : 'no-cache',
            ],
            $body,
        );
    }

    /**
     * $path as a root-relative path with no leading slash, or null when it
     * holds a NUL, a dot-segment or a dotfile segment.
     */
    private static function safeRelative(string $path): ?string
    {
        if (\str_contains($path, "\0") || \str_contains($path, '\\')) {
            return null;
        }

        $segments = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (\str_starts_with($segment, '.')) {
                return null;
            }
            $segments[] = $segment;
        }

        return \implode('/', $segments);
    }

    private const NOTICE_PAGE = <<<'HTML'
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>sugarcrush serve</title></head>
<body style="font-family: system-ui, sans-serif; max-width: 40rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5">
<h1>sugarcrush serve</h1>
<p>The server is running, but no web UI is installed. Install it next to sugar-crush with</p>
<pre>composer require sugarcraft/sugar-crush-web</pre>
<p>or point <code>--web-root</code> at a build. The WebSocket endpoint <code>/ws</code> and the HTTP API under <code>/api/</code> work without it.</p>
</body>
</html>
HTML;
}
