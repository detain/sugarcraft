<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Util\Json;

/**
 * Loads a wishlist directory from disk.
 *
 * Two formats are accepted (auto-detected by extension):
 *
 *   - **JSON** (`wishlist.json`) — a top-level array of objects: @rename-keep
 *     ```json
 *     [
 *       { "name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy" },
 *       { "name": "staging",    "host": "stage.example.com" }
 *     ]
 *     ```
 *   - **YAML-ish flat list** (`wishlist.yml`, `wishlist.yaml`) — one @rename-keep
 *     endpoint per `- name:` block. Parsed by a tiny built-in
 *     reader so we don't drag in `ext-yaml` for what's effectively
 *     a config file:
 *     ```yaml
 *     - name: production
 *       host: prod.example.com
 *       port: 2222
 *       user: deploy
 *     - name: staging
 *       host: stage.example.com
 *     ```
 *
 * Unknown keys are ignored so the format can grow without breaking
 * older configs.
 */
final class Config
{
    /**
     * @return list<Endpoint>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('config.not_found', ['path' => $path]));
        }
        $raw = self::readOrThrow($path);
        return self::parse($raw, $path);
    }

    /**
     * Read a config file or refuse loudly. The old `(string) file_get_contents`
     * cast turned a read failure (permissions, I/O error, race with a
     * deletion) into an empty string, which parsed into ZERO endpoints and
     * surfaced as a confusing 'cancelled.' with exit 130 — the user could
     * not tell a broken file from an aborted picker (2026-10-23). The call
     * is @-suppressed because file_get_contents emits an E_WARNING before
     * returning false; the false check below is the loud channel by design
     * (PHPUnit's failOnWarning is blind to suppressed diagnostics, so the
     * pin tests exercise the refusal, not the warning).
     */
    private static function readOrThrow(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(Lang::t('config.unreadable', ['path' => $path]));
        }
        return $raw;
    }

    /**
     * @return list<Endpoint>
     */
    public static function parse(string $raw, string $hintPath = 'wishlist'): array
    {
        $ext = strtolower((string) pathinfo($hintPath, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'json' => self::parseJson($raw),
            'yml', 'yaml' => self::parseYaml($raw),
            default => str_starts_with(ltrim($raw), '[') || str_starts_with(ltrim($raw), '{')
                ? self::parseJson($raw)
                : self::parseYaml($raw),
        };
        return array_values(array_map(self::buildEndpoint(...), $rows));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function parseJson(string $raw): array
    {
        // Guarded decode via sugar-core's SSOT helper: JSON_THROW_ON_ERROR
        // rejects malformed input (\JsonException) and a non-array top level
        // is rejected loudly (\RuntimeException) instead of silently becoming
        // a surprising shape. Both map back to the localized config error the
        // old json_decode()->!is_array() check raised, with the original
        // failure chained as the cause.
        try {
            $decoded = Json::decodeArray($raw);
        } catch (\JsonException | \RuntimeException $e) {
            throw new \RuntimeException(Lang::t('config.json_top_level'), 0, $e);
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException(Lang::t('config.json_entry_object'));
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Tiny YAML-flat-list parser. Supports the subset documented
     * in the class doc — one `- key: value` block per endpoint,
     * optional 2-space-indented continuation keys, optional
     * 4-space-indented `- value` items under a value-less key
     * (used for `options:` lists), and `#` line comments.
     * Anything more sophisticated (anchors, multi-doc, nested
     * mappings) belongs in a real YAML lib; we don't need it for
     * a hosts directory.
     *
     * @return list<array<string,mixed>>
     */
    private static function parseYaml(string $raw): array
    {
        $rows = [];
        $current = null;
        // Tracks the most recent value-less key whose nested `- item`
        // lines should append into a list. Reset whenever we hit
        // anything else (a new entry header, a non-empty key:value, etc.).
        $listKey = null;
        foreach (explode("\n", $raw) as $rawLine) {
            // Normalize tab indentation to spaces so tab-indented
            // continuation keys parse instead of aborting.
            $line = str_replace("\t", '    ', $rawLine);
            $line = preg_replace('/\s+#.*$/', '', $line) ?? $line;
            // Trim trailing whitespace from the whole line
            $line = rtrim($line);

            // Skip empty/whitespace-only lines
            if ($line === '') {
                continue;
            }

            // Skip YAML document markers (--- and ...)
            if ($line === '---' || $line === '...') {
                continue;
            }

            // Skip bare comment lines (just # with possible leading whitespace)
            if (str_starts_with($line, '#')) {
                continue;
            }

            // Nested string list under the active $listKey:
            // `    - ServerAliveInterval=30`
            if ($listKey !== null && $current !== null
                && preg_match('/^\s{2,}-\s+(\S.*?)\s*$/', $line, $m)) {
                /** @var list<mixed> $bucket */
                $bucket = is_array($current[$listKey] ?? null) ? $current[$listKey] : [];
                $bucket[] = self::yamlScalar($m[1]);
                $current[$listKey] = $bucket;
                continue;
            }
            // Any other line ends list-collection mode.
            $listKey = null;
            if (preg_match('/^-\s+(\w+):\s*(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $rows[] = $current;
                }
                $current = [];
                if ($m[2] === '') {
                    $current[$m[1]] = [];
                    $listKey = $m[1];
                } else {
                    $current[$m[1]] = self::yamlScalar($m[2]);
                }
                continue;
            }
            if (preg_match('/^\s+(\w+):\s*(.*)$/', $line, $m)) {
                if ($current === null) {
                    throw new \RuntimeException(Lang::t('config.yaml_continuation'));
                }
                if ($m[2] === '') {
                    $current[$m[1]] = [];
                    $listKey = $m[1];
                } else {
                    $current[$m[1]] = self::yamlScalar($m[2]);
                }
                continue;
            }
            // Genuinely unparseable line — this is a config error.
            throw new \RuntimeException(Lang::t('config.yaml_unparseable', ['line' => $rawLine]));
        }
        if ($current !== null) {
            $rows[] = $current;
        }
        return $rows;
    }

    private static function yamlScalar(string $v): mixed
    {
        $v = trim($v);
        if ($v === '' || strtolower($v) === 'null' || $v === '~') {
            return null;
        }
        if ((str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            return substr($v, 1, -1);
        }
        // YAML inline flow collections (`[a, b]`, `{k: v}`) are valid YAML
        // this flat parser cannot represent — silently carrying them as the
        // literal string would put "[a, b]" into an ssh argv token. Refuse
        // with the fix spelled out instead (2026-10-23, C6). Quoted forms
        // ("[a,b]") are ordinary scalars and keep passing through above.
        if (str_starts_with($v, '[') || str_starts_with($v, '{')) {
            throw new \RuntimeException(Lang::t('config.yaml_inline_flow', ['value' => $v]));
        }
        if (preg_match('/^-?\d+$/', $v)) {
            return (int) $v;
        }
        if ($v === 'true' || $v === 'yes')  return true;
        if ($v === 'false' || $v === 'no')  return false;
        return $v;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function buildEndpoint(array $row): Endpoint
    {
        if (!isset($row['name'], $row['host'])) {
            throw new \RuntimeException(Lang::t('config.entry_missing_field'));
        }
        // Reject non-scalar field shapes BEFORE the (string)/(int) casts below.
        // Without these guards a nested array (e.g. `name: [foo]`) would silently
        // cast to the literal "Array", smuggling `ssh user@Array` into the argv —
        // the same silent-cast footgun the candy-minesweeper Board scalar-shape guards
        // close. isset() already excludes null, so the optional fields are only
        // checked when actually present.
        self::assertScalar('name', $row['name']);
        self::assertScalar('host', $row['host']);
        if (isset($row['port'])) {
            self::assertScalar('port', $row['port']);
        }
        if (isset($row['user'])) {
            self::assertScalar('user', $row['user']);
        }
        if (isset($row['description'])) {
            self::assertScalar('description', $row['description']);
        }
        if (isset($row['proxyJump'])) {
            self::assertScalar('proxyJump', $row['proxyJump']);
        }
        $opts = [];
        foreach (self::normalizeList($row['options'] ?? null) as $o) {
            self::assertScalar('options', $o);
            $opts[] = (string) $o;
        }
        $identityFiles = [];
        if (isset($row['identityFiles'])) {
            foreach (self::normalizeList($row['identityFiles']) as $f) {
                self::assertScalar('identityFiles', $f);
                $identityFiles[] = (string) $f;
            }
        } elseif (isset($row['identity_file']) && $row['identity_file'] !== null) {
            self::assertScalar('identity_file', $row['identity_file']);
            $identityFiles[] = (string) $row['identity_file'];
        } elseif (isset($row['identityFile']) && $row['identityFile'] !== null) {
            self::assertScalar('identityFile', $row['identityFile']);
            $identityFiles[] = (string) $row['identityFile'];
        }
        return new Endpoint(
            name:         (string) $row['name'],
            host:         (string) $row['host'],
            // parsePort (not a bare (int) cast) refuses "notanumber"→0,
            // -1 and out-of-range values loudly, naming the host.
            port:         isset($row['port']) ? Endpoint::parsePort((string) $row['name'], $row['port']) : 22,
            user:         isset($row['user']) ? (string) $row['user'] : null,
            identityFiles: $identityFiles,
            description:  isset($row['description']) ? (string) $row['description'] : null,
            proxyJump:     isset($row['proxyJump']) && $row['proxyJump'] !== null ? (string) $row['proxyJump'] : null,
            options:      $opts,
        );
    }

    /**
     * Guard that a config field is a scalar before it is `(string)`/`(int)`
     * cast into the ssh(1) argv. A non-scalar value (array/object) would
     * otherwise cast silently — an array becomes the literal "Array", which
     * is a config-injection footgun; throw loudly instead.
     *
     * Booleans are `is_scalar` but have no sane string form here: the tiny
     * YAML parser maps `yes`/`no` to bool, and without this leg a config
     * `name: yes` reached ssh as the literal host user "1" (2026-10-23,
     * C5). Numeric fields go through parsePort which rejects bools too.
     *
     * @throws \InvalidArgumentException when $value is not a usable scalar
     */
    private static function assertScalar(string $field, mixed $value): void
    {
        if (!is_scalar($value) || is_bool($value)) {
            throw new \InvalidArgumentException(
                Lang::t('config.field_not_scalar', ['field' => $field, 'type' => get_debug_type($value)])
            );
        }
    }

    /**
     * Accept both the documented nested-list form and the scalar shorthand
     * (`options: ServerAliveInterval=30` is one item, not silently dropped).
     * null and the empty list-mode placeholder both normalize to []
     * (2026-10-23 ruling: previously a scalar value here vanished without a
     * word — a documented config key must never no-op silently).
     *
     * @return list<mixed>
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return [];
        }
        return [$value];
    }

    /**
     * Import endpoints from an OpenSSH config file (~/.ssh/config).
     *
     * Parses `Host` blocks (whitespace or equals form) and their
     * HostName/User/Port/IdentityFile/ProxyJump options. Precedence is
     * POSITIONAL first-obtained across all matching blocks in file order —
     * `Host *` is not a global-defaults section that per-host options
     * "override"; a `Host *` written before a specific block supplies the
     * first-obtained value and WINS for every host (see SshConfigParser's
     * class docblock, 2026-10-23 truth rewrite). Host patterns become the
     * endpoint `name`; HostName (or the pattern itself if no HostName is
     * given) becomes the `host`.
     *
     * @return list<Endpoint>
     */
    public static function importFromSshConfig(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('config.not_found', ['path' => $path]));
        }
        $raw = self::readOrThrow($path);
        $parser = new SshConfigParser();
        return $parser->parse($raw);
    }
}
