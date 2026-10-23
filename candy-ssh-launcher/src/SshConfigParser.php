<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use RuntimeException;

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
 * - Pattern matching is literal-or-bare-`*` only. OpenSSH's `?`, character
 *   classes, and negated `!patterns` are not interpreted — a `Host web*`
 *   block never cross-applies to the host `web1`; only the exact string
 *   `web*` (or the universal bare `*`) matches.
 * - Quoted arguments, `Match` blocks, and multi-line continuations are
 *   unimplemented.
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
            $line = preg_replace('/\s+#.*$/', '', $rawLine) ?? $rawLine;
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
     * @param array<string,string|list<string>> $options
     */
    private function applyKeyword(array &$options, string $key, string $value): void
    {
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

        $identityFiles = [];
        if (isset($options['identityfile']) && is_array($options['identityfile'])) {
            foreach ($options['identityfile'] as $f) {
                $identityFiles[] = $this->expandPath((string) $f);
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

    private function expandPath(string $path): string
    {
        if (strncmp($path, '~', 1) !== 0) {
            return $path;
        }

        // Parse ~user/path or ~/path using a simple string scan instead of regex
        $len = strlen($path);
        if ($len >= 2 && $path[1] === '/') {
            // ~/path — current user's home
            return $this->homeDirectory($path) . substr($path, 1);
        }

        // ~user/path — find the first slash
        $slashPos = strpos($path, '/');
        if ($slashPos === false) {
            // ~user with no slash — entire path is the user name
            $user = substr($path, 1);
            $rest = '';
        } else {
            $user = substr($path, 1, $slashPos - 1);
            $rest = substr($path, $slashPos);
        }

        if ($user === '') {
            // Edge case: just ~ (the ~/... shape was handled above)
            return $this->homeDirectory($path) . $rest;
        }

        // ~user/path — try to resolve via posix_getpwnam
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($user);
            if ($pw !== false && isset($pw['dir'])) {
                return $pw['dir'] . $rest;
            }
        }

        // Cannot resolve ~user — return path unchanged rather than
        // producing garbage like <home>user/...
        return $path;
    }

    /**
     * Home directory for `~` expansion: $HOME, then the passwd entry of the
     * effective user, then a loud refusal. The old code silently substituted
     * the literal '/root', so an import run without $HOME would have hunted
     * identity files under /root (2026-10-23).
     */
    private function homeDirectory(string $path): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) && $pw['dir'] !== '') {
                return rtrim($pw['dir'], '/');
            }
        }

        throw new RuntimeException(Lang::t('config.home_unset', ['path' => $path]));
    }

    /**
     * @param array<string,string|list<string>> $options
     * @param list<string> $value
     */
    private function appendList(array &$options, string $key, string $value): void
    {
        if (!isset($options[$key])) {
            $options[$key] = [];
        }
        /** @var list<string> $bucket */
        $bucket = $options[$key];
        $bucket[] = $value;
        $options[$key] = $bucket;
    }
}
