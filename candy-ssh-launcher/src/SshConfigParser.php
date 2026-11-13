<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\SshLauncher\Support\CommentStrip;

/**
 * Parses an OpenSSH config file (~/.ssh/config) into a list of Endpoints.
 *
 * Handles:
 * - `Host <pattern>` blocks (including `Host *`, which matches every host)
 * - Per-host options: HostName, User, Port, IdentityFile, ProxyJump
 * - Multi-pattern Host lines ("Host a b c") become one endpoint per pattern
 * - Both argument grammars ssh_config(5) allows: whitespace (`User bob`) and
 *   the equals form (`User=bob`, `Host=web1`) — "if the first character
 *   after the keyword is an equal sign it is ignored".
 *
 * Precedence follows ssh_config(5): "For each parameter, the first obtained
 * value will be used." Resolution walks ALL blocks in file order and, for
 * each endpoint, takes the first value a matching block defines. `Host *`
 * is therefore NOT a subordinate defaults section — it is simply
 * conventionally written last, which is what makes it lose. A `Host *`
 * written FIRST in the file wins over every later specific block.
 * IdentityFile is the documented exception: values accumulate across
 * matching blocks in file order (ssh tries them in that order).
 *
 * This is a DELIBERATE SUBSET of ssh_config(5), not the full grammar:
 * - Pattern matching is literal-or-bare-`*` only, and only literal-or-bare
 *   patterns MINT rows. OpenSSH's `?`, character classes, and negated
 *   `!patterns` are not interpreted — a `Host web*` block never
 *   cross-applies to the host `web1`, and since -021 it does not mint a
 *   row named `web*` either: a glob is not a hostname, exactly the reason
 *   `!patterns` were excluded (F-24). The block still carries its options
 *   for an exact-string match; only the mint is skipped.
 * - Argument values that sit entirely inside one matched pair of quotes
 *   (`IdentityFile "~/.ssh/id_ed25519"`) have that pair peeled before the
 *   value is stored, mirroring ssh_config(5) QUOTING. Embedded or
 *   unbalanced quotes stay literal bytes — this reader peels a pair or
 *   nothing, never re-lexes a line.
 * - `%h`, `%r`, `%u` and `%%` expand at import time inside HostName, User
 *   and IdentityFile values (ssh_config(5) TOKENS, resolved locally since
 *   no command-line destination exists yet: %h = the HostName or pattern,
 *   %r = the resolved user, falling back to the local login when the file
 *   sets none, %u = the local login name). Other `%` pairs stay literal.
 * - `Match` blocks and multi-line continuations are unimplemented.
 */
final class SshConfigParser
{
    /**
     * One keyword line: keyword, then EITHER whitespace or an equals sign
     * (with optional surrounding whitespace on both sides, as OpenSSH
     * tolerates), then the argument. `Host` headers route through the same
     * split so `Host=web1` opens a block instead of being dropped — the old
     * whitespace-only regex silently ignored equals forms, and an ignored
     * `Host=x` header let that block's options leak into the PREVIOUS
     * block's hosts. (2026-10-23, ssh_config(5) "ARGUMENTS": equals form is
     * legal for every keyword, Host included; verified against OpenSSH 9.x
     * `ssh -G`.)
     *
     * INTENTIONALLY PERMISSIVE edge, kept by design: a doubled separator
     * (`User == bob`) still matches — the first `=` is consumed as the
     * separator and `= bob` becomes the value, so the endpoint carries
     * user `= bob`. OpenSSH would reject that config; we accept it rather
     * than guess intent, and the failure surfaces late but LOUDLY at
     * connect time when ssh refuses the name. Refusing it here instead
     * would mean a new hard-error message in all 16 locales at once
     * (LocaleParityTest) — deliberately not taken.
     */
    private const KEYWORD_PATTERN = '/^(\w+)(?:\s*=\s*|\s+)(.*)$/';

    /**
     * Every Host block in file order — wildcard blocks included, because
     * precedence is positional, not categorical.
     *
     * @var list<array{patterns:list<string>, options:array<string,string|list<string>>}>
     */
    private array $blocks = [];

    /**
     * @return list<Endpoint>
     */
    public function parse(string $raw): array
    {
        $this->blocks = [];

        /** @var int|null $currentIndex Index into $blocks, null before the first Host header. */
        $currentIndex = null;

        foreach (explode("\n", $raw) as $rawLine) {
            $line = self::stripComment($rawLine);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(self::KEYWORD_PATTERN, $line, $m) !== 1) {
                // Bare `Host` (no argument) and unrecognisable lines match
                // nothing — same silence as before this rewrite.
                continue;
            }

            $keyword = strtolower($m[1]);
            $argument = trim($m[2]);

            if ($keyword === 'host') {
                if ($argument === '') {
                    continue;
                }
                // Split multi-pattern Host lines (e.g. "Host a b c") into
                // individual patterns, preserving left-to-right order.
                // Repeats — within one header line or across separate
                // blocks — collapse to a single endpoint in buildEndpoints.
                $patterns = $this->uniquePatterns(
                    preg_split('/\s+/', $argument) ?: []
                );
                $this->blocks[] = ['patterns' => $patterns, 'options' => []];
                $currentIndex = count($this->blocks) - 1;
                continue;
            }

            // Keyword VALUE lines (inside a Host block). Lines before the
            // first Host header, and empty arguments, are ignored.
            if ($currentIndex !== null && $argument !== '') {
                $currentOptions = &$this->blocks[$currentIndex]['options'];
                $this->applyKeyword($currentOptions, $keyword, $argument);
                unset($currentOptions);
            }
        }

        return $this->buildEndpoints();
    }

    /**
     * Strip a trailing '#' comment from a config line — quote-aware (F-18).
     *
     * Delegates to the single shared home, {@see CommentStrip::strip()}
     * (finding candy-ssh-launcher-065): the byte-identical copy Config used
     * to carry now rides the same machine, so F-18-class fixes land once.
     */
    private static function stripComment(string $line): string
    {
        return CommentStrip::strip($line);
    }

    /**
     * Deduplicate Host-line pattern tokens, preserving file order.
     *
     * @param array<string> $tokens
     * @return list<string>
     */
    private function uniquePatterns(array $tokens): array
    {
        $seen = [];
        $unique = [];
        foreach ($tokens as $token) {
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $unique[] = $token;
        }

        return $unique;
    }

    /**
     * Peel one matched pair of surrounding quotes from an argument value
     * (ssh_config(5) QUOTING, -021). A value that merely CONTAINS quotes
     * is left byte-exact; only a complete `"…"` / `'…'` wrapper peels.
     */
    private static function unwrapQuoted(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    /**
     * Expand the ssh_config(5) TOKENS this importer can resolve locally
     * (-021): %h = the host the entry points at, %r = its remote user
     * (the local login when the file sets none — the deliberate
     * simplification of a self-referential `%r` inside `User`), %u = the
     * local login, %% = a literal percent. Every other `%` pair stays
     * untouched, matching OpenSSH's behavior for unknown tokens.
     */
    private static function expandTokens(string $value, string $host, ?string $user): string
    {
        if (!str_contains($value, '%')) {
            return $value;
        }
        $local = self::localUserName();
        return (string) preg_replace_callback(
            '/%([hrump%])/',
            static fn(array $m): string => match ($m[1]) {
                '%' => '%',
                'h' => $host,
                'r' => $user ?? $local,
                'u' => $local,
                default => $m[0],
            },
            $value
        );
    }

    /** Login name of the user running the import (posix, USER/USERNAME env fallback). */
    private static function localUserName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name']) && $pw['name'] !== '') {
                return (string) $pw['name'];
            }
        }
        return (string) (getenv('USER') ?: getenv('USERNAME') ?: '');
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function applyKeyword(array &$options, string $key, string $value): void
    {
        // -021: a value wrapped entirely in one matched quote pair loses the
        // pair BEFORE anything else interprets it — `IdentityFile "~/.ssh/…"
        // must reach IdentityPath::expand as a tilde path, not a quoted one.
        $value = self::unwrapQuoted($value);
        match ($key) {
            // First-obtained-wins within the block too (ssh_config.5):
            // a repeated scalar keyword keeps its first value.
            'hostname' => $options['hostname'] ??= $value,
            'user' => $options['user'] ??= $value,
            'port' => $options['port'] ??= $value,
            'identityfile' => $this->appendList($options, 'identityfile', $value),
            'proxyjump' => $options['proxyjump'] ??= $value,
            default => null,
        };
    }

    /**
     * @return list<Endpoint>
     */
    private function buildEndpoints(): array
    {
        $endpoints = [];
        $minted = [];
        foreach ($this->blocks as $block) {
            foreach ($block['patterns'] as $pattern) {
                if ($pattern === '*') {
                    // A wildcard pattern never names an endpoint of its own;
                    // it only contributes options (see resolveOptions).
                    continue;
                }
                if (str_starts_with($pattern, '!')) {
                    // F-24: a '!pattern' is an ssh_config(5) EXCLUSION token,
                    // never a hostname. Minting it offered a selectable row
                    // that dispatched the literal '!internal'. The token stays
                    // in the block's pattern list, so resolveOptions matching
                    // is unaffected — only the mint is skipped.
                    continue;
                }
                if (strpbrk($pattern, '*?[') !== false) {
                    // -021: a glob pattern (`*.internal`, `web?`, `[ab]host`)
                    // is a matcher, not a hostname — minting it offered a row
                    // that dispatches the literal glob. Same reasoning (and
                    // same placement) as the '!'-exclusion skip above: the
                    // token stays in the pattern list for exact matching,
                    // only the mint is skipped.
                    continue;
                }
                if (isset($minted[$pattern])) {
                    // BLOCK-level collapse (README "a repeated Host pattern
                    // reuses its block"): the same pattern in a second,
                    // separate Host block does not mint a second entry —
                    // resolveOptions already merged both blocks under
                    // first-obtained-wins when the first one was minted.
                    continue;
                }
                $minted[$pattern] = true;
                $endpoints[] = $this->makeEndpoint($pattern, $this->resolveOptions($pattern));
            }
        }
        return array_values(array_filter($endpoints, fn(Endpoint $e) => $e->host !== ''));
    }

    /**
     * Resolve the effective options for one host pattern by walking every
     * block in file order (ssh_config.5 first-obtained-value semantics).
     *
     * @return array<string,string|list<string>>
     */
    private function resolveOptions(string $pattern): array
    {
        $resolved = [];
        foreach ($this->blocks as $block) {
            $applies = in_array('*', $block['patterns'], true)
                || in_array($pattern, $block['patterns'], true);
            if (!$applies) {
                continue;
            }
            foreach ($block['options'] as $key => $value) {
                if ($key === 'identityfile') {
                    // Accumulates across matching blocks, first-seen first,
                    // without duplicates — exactly what ssh(1) does.
                    /** @var list<string> $existing */
                    $existing = $resolved['identityfile'] ?? [];
                    /** @var list<string> $incoming */
                    $incoming = is_array($value) ? $value : [$value];
                    foreach ($incoming as $file) {
                        if (!in_array($file, $existing, true)) {
                            $existing[] = $file;
                        }
                    }
                    if ($existing !== []) {
                        $resolved['identityfile'] = $existing;
                    }
                    continue;
                }
                $resolved[$key] ??= $value;
            }
        }
        return $resolved;
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function makeEndpoint(string $hostPattern, array $options): Endpoint
    {
        $host = $options['hostname'] ?? $hostPattern;
        // parsePort enforces 1-65535 and refuses junk loudly, naming the
        // host — an ssh_config "Port 99999" must not become a silent 0.
        $port = isset($options['port'])
            ? Endpoint::parsePort($hostPattern, $options['port'])
            : 22;
        $user = $options['user'] ?? null;

        // -021: tokens resolve once host+user are known, before the tilde
        // leg of the identity path runs (`IdentityFile /home/%r/.ssh/%h`
        // must reach IdentityPath::expand as concrete bytes).
        $host = self::expandTokens((string) $host, (string) $host, $user !== null ? (string) $user : null);
        if ($user !== null) {
            // A self-referential %r inside User has no file-set answer, so
            // it resolves through the local-login fallback (null user).
            $user = self::expandTokens((string) $user, $host, null);
        }

        $identityFiles = [];
        if (isset($options['identityfile']) && is_array($options['identityfile'])) {
            foreach ($options['identityfile'] as $f) {
                $identityFiles[] = IdentityPath::expand(
                    self::expandTokens((string) $f, $host, $user !== null ? (string) $user : null)
                );
            }
        }

        return new Endpoint(
            name: $hostPattern,
            host: $host,
            port: $port,
            user: $user !== null ? (string) $user : null,
            identityFiles: $identityFiles,
            proxyJump: isset($options['proxyjump']) ? (string) $options['proxyjump'] : null,
        );
    }

    /**
     * @param array<string,string|list<string>> $options
     * @param list<string> $value
     */
    private function appendList(array &$options, string $key, string $value): void
    {
        if (!isset($options[$key])) {
            /** @var array<string, list<string>> $options */
            $options[$key] = [];
        }
        // -016: same in-place append + shared cap as the wishlist reader.
        // A thousand repeated IdentityFile lines is a broken file, and the
        // old copy-per-item write-back made collecting one key quadratic.
        $bucket = &$options[$key];
        if (count($bucket) >= Config::MAX_LIST_ITEMS) {
            throw new \RuntimeException(Lang::t('config.list_too_long', [
                'field' => $key,
                'limit' => (string) Config::MAX_LIST_ITEMS,
            ]));
        }
        $bucket[] = $value;
        unset($bucket);
    }
}
