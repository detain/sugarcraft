<?php

declare(strict_types=1);

namespace SugarCraft\Code\LSP;

/**
 * The pinned release manifest behind `lsp install` (C-TOOL-5.c) — the ONLY
 * places {@see LspInstaller} may ever fetch from.
 *
 * PINNED BY CONSTRUCTION. Every entry is a compile-time constant: a language
 * maps to a package version (npm arm) or a release target maps to one exact
 * asset URL + sha256 (binary arm). Nothing reads a registry, a version list,
 * or a user-supplied URL at install time, so the widest thing a corrupt call
 * site can choose is another already-pinned line of this file.
 *
 * THE ALLOWLIST IS DOUBLE. {@see isAllowedUrl()} re-checks every URL the
 * manifest hands out at the moment of use — scheme must be exactly https, the
 * host must be on {@see ALLOWED_DOWNLOAD_HOSTS}, and the path must be a
 * rust-analyzer release asset under its own repository. The constant table is
 * the intent; this gate is the enforcement, and it fails CLOSED: a future
 * edit that fattens a line here cannot quietly turn the installer into a
 * general-purpose downloader.
 *
 * WHY TWO ARMS. rust-analyzer ships a self-contained binary, so it downloads
 * as one checksummed `.gz`. The other servers are npm packages; the install
 * runs `npm --ignore-scripts` under a pinned version, where npm's own
 * integrity store does the checksumming and no arbitrary URL is fetched at
 * all. Windows release zips exist upstream but need an unzip arm this
 * installer does not carry, and gopls is a `go install` away rather than a
 * download — both are honest refusals from this table, not gaps to fill with
 * a wildcard.
 */
final class LspReleases
{
    /** The only hosts a binary download may name; enforced by {@see isAllowedUrl()}. */
    public const ALLOWED_DOWNLOAD_HOSTS = ['github.com', 'objects.githubusercontent.com'];

    /** The upstream release {@see BINARY_SERVERS} assets are cut from. */
    public const RUST_ANALYZER_RELEASE_TAG = '2026-10-26';

    private const BINARY_ASSET_BASE = 'https://github.com/rust-lang/rust-analyzer/releases/download/';

    /**
     * npm-arm servers: language => package, pinned version, and the server
     * binary the package's `node_modules/.bin` link exposes.
     *
     * @var array<string, array{package: string, version: string, binary: string}>
     */
    private const NPM_SERVERS = [
        'php' => ['package' => 'intelephense', 'version' => '1.18.5', 'binary' => 'intelephense'],
        'typescript' => ['package' => 'typescript-language-server', 'version' => '6.0.2', 'binary' => 'typescript-language-server'],
        'python' => ['package' => 'pyright', 'version' => '1.1.414', 'binary' => 'pyright-langserver'],
    ];

    /**
     * Binary-arm servers: language => "<os>|<arch>" => asset + sha256 of the
     * asset AS PUBLISHED (the `.gz`, checked before any decompression).
     *
     * musl and 32-bit builds exist upstream but no target here probes libc,
     * so they are deliberately absent; Windows has no arm because there is no
     * zip reader. Unknown target => {@see binaryEntry()} answers null and the
     * installer refuses.
     *
     * @var array<string, array<string, array{asset: string, sha256: string}>>
     */
    private const BINARY_SERVERS = [
        'rust' => [
            'linux|x86_64' => [
                'asset' => 'rust-analyzer-x86_64-unknown-linux-gnu.gz',
                'sha256' => '4c83773525f93b636ed79761389568b4d2a89c05fa7ecc440245032d49ad80ce',
            ],
            'linux|aarch64' => [
                'asset' => 'rust-analyzer-aarch64-unknown-linux-gnu.gz',
                'sha256' => '1b225c4c9ae329ca96e8a75a5bbcb5562a7f1d25c57c8b55d3e02299a3dbe836',
            ],
            'darwin|x86_64' => [
                'asset' => 'rust-analyzer-x86_64-apple-darwin.gz',
                'sha256' => 'b3e4904877bcf7013a4a342c449cff1bf0ea0b91d5a1a30ac8df3d32142519c0',
            ],
            'darwin|aarch64' => [
                'asset' => 'rust-analyzer-aarch64-apple-darwin.gz',
                'sha256' => '8fb90cb58f5910d8d8b24ac1a93cca49f053365cbf0eb3c682144ad0a0027dcf',
            ],
        ],
    ];

    /** The binary name each binary-arm language installs. */
    private const BINARY_NAMES = ['rust' => 'rust-analyzer'];

    private function __construct()
    {
    }

    /**
     * Every language `lsp install` can serve, in table order.
     *
     * @return list<string>
     */
    public static function installableLanguages(): array
    {
        return ['php', 'typescript', 'python', 'rust'];
    }

    /**
     * The pinned npm entry for $language, or null when its arm is not npm.
     *
     * @return ?array{package: string, version: string, binary: string}
     */
    public static function npmEntry(string $language): ?array
    {
        return self::NPM_SERVERS[$language] ?? null;
    }

    /**
     * The pinned binary entry for $language on "<os>|<arch>", or null when
     * there is none — an unknown language, target, or both.
     *
     * The URL is re-checked through {@see isAllowedUrl()} on the way out:
     * this method CANNOT hand a URL the gate would refuse, even if the
     * constant above were ever edited into a shape the gate rejects.
     *
     * @return ?array{url: string, asset: string, sha256: string, binary: string}
     */
    public static function binaryEntry(string $language, string $os, string $arch): ?array
    {
        $entry = self::BINARY_SERVERS[$language][$os . '|' . $arch] ?? null;
        if ($entry === null) {
            return null;
        }
        $url = self::BINARY_ASSET_BASE . rawurlencode(self::RUST_ANALYZER_RELEASE_TAG) . '/' . $entry['asset'];
        if (!self::isAllowedUrl($url)) {
            return null;
        }

        return [
            'url' => $url,
            'asset' => $entry['asset'],
            'sha256' => $entry['sha256'],
            'binary' => self::BINARY_NAMES[$language] ?? $language,
        ];
    }

    /**
     * Whether $url is on the allowlist: https only, host exactly one of
     * {@see ALLOWED_DOWNLOAD_HOSTS} (no userinfo, no port, no lookalike
     * suffix), and a rust-analyzer release asset shape on that host. On
     * github.com the path must BE the release route — repository, date-grammar
     * tag, published `.gz` spelling. objects.githubusercontent.com is reached
     * only as GitHub's own redirect target, so it must carry the release-asset
     * bucket path AND name this exact asset family in its `filename` query.
     * Both shapes fail CLOSED on anything else, and the redirect guard of
     * {@see LspInstaller} re-runs this check on every hop.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $asset = '#^rust-analyzer-[A-Za-z0-9_-]+\.gz$#';

        if ($host === 'github.com') {
            return preg_match('#^/rust-lang/rust-analyzer/releases/download/[0-9]{4}-[0-9]{2}-[0-9]{2}/#', $path) === 1
                && preg_match($asset, substr($path, (int) strrpos($path, '/') + 1)) === 1;
        }

        if ($host === 'objects.githubusercontent.com') {
            if (strpos($path, '/github-production-release-asset') !== 0) {
                return false;
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            $filename = $query['filename'] ?? null;

            return \is_string($filename) && preg_match($asset, $filename) === 1;
        }

        return false;
    }
}
