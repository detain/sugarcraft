<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Util\Json;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\SshLauncher\Support\CommentStrip;

/**
 * Loads a wishlist directory from disk.
 *
 * Two formats are accepted (auto-detected by extension):
 *
 *   - **JSON** (`wishlist.json`) — a top-level array of objects:
 *     ```json
 *     [
 *       { "name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy" },
 *       { "name": "staging",    "host": "stage.example.com" }
 *     ]
 *     ```
 *   - **YAML-ish flat list** (`wishlist.yml`, `wishlist.yaml`) — one
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
 * Entry keys are checked against a versioned allowlist ({@see self::KNOWN_FIELDS}):
 * an unknown key is warned by name on stderr and ignored (it used to vanish
 * without a word — a dropped `proxy_jump:` silently removed the bastion,
 * -004); spellings that case-fold onto a documented field after `_`-removal
 * get a louder near-miss warning naming the field. Documented aliases
 * ({@see self::FIELD_ALIASES}: `proxy_jump`, plural/snake identity spellings)
 * resolve behind their canonical key — canonical wins when both appear.
 * A block mapping indented under a list field is refused by name (field and
 * line) — it used to flatten silently (F-19).
 */
final class Config
{
    /**
     * Versioned entry-key allowlist (v1 = every field buildEndpoint reads).
     * Keys outside this set — and outside the alias table — warn by name and
     * are ignored (-004: silence turned a typo into a wrong connection).
     * New fields land here in the same commit that implements them.
     *
     * @var list<string>
     */
    private const KNOWN_FIELDS = [
        'name', 'host', 'port', 'user', 'description', 'options',
        'identityFiles', 'proxyJump', 'flags', 'remoteCommand',
    ];

    /**
     * Documented alternative spellings, resolved BEHIND their canonical key
     * (canonical wins when both are present): `proxy_jump`/`identity_files`
     * are the upstream array spellings (-004's migration footgun),
     * `identity_file`/`identityFile` the historically accepted singulars,
     * `remote_command` the ssh-config-ish snake of `remoteCommand` (-007).
     *
     * @var array<string,string>
     */
    private const FIELD_ALIASES = [
        'identity_files' => 'identityFiles',
        'identity_file'  => 'identityFiles',
        'identityFile'   => 'identityFiles',
        'proxy_jump'     => 'proxyJump',
        'remote_command' => 'remoteCommand',
    ];

    /**
     * Load-time diagnostics stream (duplicate names, unknown keys). STDERR
     * unless a test swaps in a capture handle — warnings must not need an
     * opt-in at the call site or they degrade to silence, the very failure
     * -004/-100 close.
     *
     * @var resource|null
     */
    private static $warningStream = null;

    /** Test seam: route load warnings to $stream (null restores STDERR). */
    public static function setWarningStream($stream): void
    {
        self::$warningStream = $stream;
    }

    private static function warn(string $message): void
    {
        fwrite(self::$warningStream ?? STDERR, $message . "\n");
    }

    /**
     * Report every per-entry key outside the allowlist before the row is
     * built (-004). The key is sanitized as untrusted config bytes: it
     * reaches the terminal verbatim, not only through bin's boundary.
     *
     * @param array<string,mixed> $row
     */
    private static function auditRowKeys(array $row): void
    {
        foreach (array_keys($row) as $key) {
            $key = (string) $key;
            if (in_array($key, self::KNOWN_FIELDS, true) || isset(self::FIELD_ALIASES[$key])) {
                continue;
            }
            $fold = strtolower(str_replace('_', '', $key));
            $nearmiss = null;
            foreach (self::KNOWN_FIELDS as $field) {
                if (strtolower($field) === $fold) {
                    $nearmiss = $field;
                    break;
                }
            }
            self::warn($nearmiss === null
                ? Lang::t('config.unknown_key', ['key' => Sanitize::untrusted($key)])
                : Lang::t('config.unknown_key_nearmiss', [
                    'key'   => Sanitize::untrusted($key),
                    'field' => $nearmiss,
                ]));
        }
    }

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
        $endpoints = [];
        $names = [];
        foreach ($rows as $index => $row) {
            // -052: the throw names the position and the missing field —
            // the only config error that used to point at nothing.
            if (!isset($row['name'], $row['host'])) {
                throw new \RuntimeException(Lang::t('config.entry_missing_field', [
                    'index' => (string) ($index + 1),
                    'field' => isset($row['name']) ? 'host' : 'name',
                ]));
            }
            $endpoint = self::buildEndpoint($row);
            // -100: names are identifiers (upstream); duplicate rows still
            // load — the picker cannot tell them apart, so the file must.
            if (isset($names[$endpoint->name])) {
                self::warn(Lang::t('config.duplicate_name', ['name' => Sanitize::untrusted($endpoint->name)]));
            }
            $names[$endpoint->name] = true;
            $endpoints[] = $endpoint;
        }
        return $endpoints;
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
        /** Field whose list mode the current line just ended (F-19 witness). */
        $pendingList = null;
        /** Indent (in spaces) at which the active list key was opened. */
        $listKeyIndent = 0;
        // -026: the reader counts physical lines so every diagnostic can
        // point at a real number instead of pasting the offending content
        // into a slot the message calls "line".
        foreach (explode("\n", $raw) as $lineNo => $rawLine) {
            // -066: normalize tab indentation to spaces in the LEADING
            // indent only — a tab inside a quoted scalar is data, and the
            // ssh_config ingest path rewrites no bytes mid-value, so one
            // value must not mean two things depending on the reader.
            $line = self::stripComment(self::expandLeadingTabs($rawLine));
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

            $indent = strlen($line) - strlen(ltrim($line, ' '));

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
            // Any other line ends list-collection mode — but remember the
            // field that was open: an indented `Key: value` continuation
            // arriving deeper than the list field is a block mapping this
            // reader refuses by name (F-19), not a silent flatten.
            $pendingList = $listKey;
            $listKey = null;
            if (preg_match('/^-\s+(\w+):\s*(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $rows[] = $current;
                }
                $current = [];
                if ($m[2] === '') {
                    $current[$m[1]] = [];
                    $listKey = $m[1];
                    $listKeyIndent = $indent;
                } else {
                    $current[$m[1]] = self::yamlScalar($m[2]);
                }
                continue;
            }
            if (preg_match('/^\s+(\w+):\s*(.*)$/', $line, $m)) {
                if ($current === null) {
                    throw new \RuntimeException(Lang::t('config.yaml_continuation'));
                }
                if ($pendingList !== null && $indent > $listKeyIndent) {
                    // A `Key: value` indented under a list field (options:,
                    // identityFiles:) is a block mapping; this flat reader
                    // would otherwise drop it silently and leave the list
                    // empty. Refuse naming the field, the line number, and
                    // the sanitized content (-011/-026).
                    throw new \RuntimeException(Lang::t('config.yaml_block_mapping', [
                        'field' => $pendingList,
                        'line'  => (string) ($lineNo + 1),
                        'found' => self::diagnostic($rawLine),
                    ]));
                }
                if ($m[2] === '') {
                    $current[$m[1]] = [];
                    $listKey = $m[1];
                    $listKeyIndent = $indent;
                } else {
                    $current[$m[1]] = self::yamlScalar($m[2]);
                }
                continue;
            }
            // Genuinely unparseable line — this is a config error. The
            // message carries the physical line number and the sanitized
            // content (-011/-026), never raw bytes into a number slot.
            throw new \RuntimeException(Lang::t('config.yaml_unparseable', [
                'line'  => (string) ($lineNo + 1),
                'found' => self::diagnostic($rawLine),
            ]));
        }
        if ($current !== null) {
            $rows[] = $current;
        }
        return $rows;
    }

    /**
     * Make one physical config line safe to embed in a single-line
     * exception message (-026). The fuzz corpus produced payloads with
     * NUL, C1, and invalid UTF-8; the CLI happens to fence its stderr
     * today, but Config::parse() is the advertised programmatic API and
     * must sanitize at the throw site so every caller inherits the
     * guarantee.
     *
     * Sanitize::untrusted() drops escape sequences, C0 (except TAB),
     * DEL and C1, but it deliberately preserves CR — fine for a frame,
     * wrong for a one-line message, so CR becomes a space here rather
     * than being deleted (deletion splices the surrounding text into a
     * fake word; the config line never contains a real LF because the
     * reader already split on it). Finally invalid UTF-8 byte sequences
     * are stripped so a consumer validating the message as UTF-8 is
     * never handed a broken string (sugar-core requires ext-mbstring).
     */
    private static function diagnostic(string $rawLine): string
    {
        $safe = Sanitize::untrusted(trim($rawLine));
        $safe = str_replace("\r", ' ', $safe);

        return mb_convert_encoding($safe, 'UTF-8', 'UTF-8');
    }

    /**
     * Strip a trailing '#' comment from a config line — quote-aware (F-18).
     *
     * Delegates to the single shared home, {@see CommentStrip::strip()}
     * (finding candy-ssh-launcher-065): the byte-identical copy SshConfigParser
     * used to carry now rides the same machine, so F-18-class fixes land once.
     */
    private static function stripComment(string $line): string
    {
        return CommentStrip::strip($line);
    }

    /**
     * Expand tab characters that sit in a line's LEADING indentation to
     * four spaces each, iteratively, leaving every byte from the first
     * non-(space|tab) character onward untouched (-066).
     */
    private static function expandLeadingTabs(string $line): string
    {
        return (string) preg_replace_callback(
            '/^(?:[ ]*\t)+/',
            static fn (array $m): string => str_replace("\t", '    ', $m[0]),
            $line,
            1
        );
    }

    /**
     * Inner slice of a quoted scalar, found with the same escape rules as
     * the comment scanner ({@see CommentStrip::strip()}): inside double
     * quotes a backslash consumes the next character, inside single quotes
     * a doubled '' stays inside. Throws when the opening quote never
     * closes — an unterminated quote names a broken config, not a hostname
     * with a quote byte glued to it (-068).
     *
     * @throws \RuntimeException when the opening quote never closes
     */
    private static function quotedInner(string $v, string $quote): string
    {
        $length = strlen($v);
        for ($i = 1; $i < $length; $i++) {
            $ch = $v[$i];
            if ($quote === '"' && $ch === '\\') {
                $i++; // consume the escaped pair
                continue;
            }
            if ($ch !== $quote) {
                continue;
            }
            if ($quote === "'" && $i + 1 < $length && $v[$i + 1] === "'") {
                $i++; // doubled '' — an escaped quote, stays inside
                continue;
            }

            return substr($v, 1, $i - 1);
        }

        throw new \RuntimeException(Lang::t('config.yaml_unterminated_quote', [
            'found' => self::diagnostic($v),
        ]));
    }

    /**
     * Resolve the double-quote escape pairs the scanner recognized: `\"`
     * becomes `"` and `\\` becomes `\`; a backslash before anything else
     * rides verbatim (this subset invents no other escapes, -069).
     */
    private static function unescapeDouble(string $inner): string
    {
        $out = '';
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            if ($inner[$i] === '\\' && $i + 1 < $length
                && ($inner[$i + 1] === '"' || $inner[$i + 1] === '\\')) {
                $out .= $inner[++$i];
                continue;
            }
            $out .= $inner[$i];
        }

        return $out;
    }

    private static function yamlScalar(string $v): mixed
    {
        $v = trim($v);
        if ($v === '' || strtolower($v) === 'null' || $v === '~') {
            return null;
        }
        // -068/-069: a leading quote opens a quoted scalar that MUST close
        // under the subset's escape rules (\\-pairs in double quotes, doubled
        // '' in single); an unclosed quote is a config error, never a value,
        // so `host: "unclosed` cannot dispatch a quote-prefixed hostname.
        // Closing scalars are unescaped — `a\"b` means `a"b`, the same bytes
        // real YAML (and OpenSSH argv) would yield.
        if (($v[0] ?? '') === '"' || ($v[0] ?? '') === "'") {
            $quote = $v[0];
            $inner = self::quotedInner($v, $quote);

            return $quote === '"' ? self::unescapeDouble($inner) : str_replace("''", "'", $inner);
        }
        // YAML inline flow collections (`[a, b]`, `{k: v}`) are valid YAML
        // this flat parser cannot represent — silently carrying them as the
        // literal string would put "[a, b]" into an ssh argv token. Refuse
        // with the fix spelled out instead (2026-10-23). Quoted forms
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
        // -004: keys outside the allowlist (and the documented aliases) are
        // named on stderr before the row is built — an ignored typo must be
        // visible, not silently wrong.
        self::auditRowKeys($row);
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
        // proxyJump resolves through the alias chain: canonical first, the
        // upstream `proxy_jump` spelling behind it (FIELD_ALIASES precedence).
        $proxyJump = isset($row['proxyJump']) && $row['proxyJump'] !== null
            ? $row['proxyJump']
            : ($row['proxy_jump'] ?? null);
        if ($proxyJump !== null) {
            self::assertScalar('proxyJump', $proxyJump);
        }
        $opts = [];
        foreach (self::normalizeList($row['options'] ?? null) as $o) {
            self::assertScalar('options', $o);
            $opts[] = (string) $o;
        }
        // -033: the `flags` lane is the same list shape as `options`, but the
        // entries are ssh(1) flag LETTERS (`t`, `N`) — the dash is added at
        // argv build, where the leading-dash guard rejects payload smuggling.
        $flags = [];
        foreach (self::normalizeList($row['flags'] ?? null) as $f) {
            self::assertScalar('flags', $f);
            $flags[] = (string) $f;
        }
        // -007: remoteCommand resolves through the alias chain like proxyJump:
        // canonical first, upstream-ish `remote_command` spelling behind it.
        $remoteCommand = isset($row['remoteCommand']) && $row['remoteCommand'] !== null
            ? $row['remoteCommand']
            : ($row['remote_command'] ?? null);
        if ($remoteCommand !== null) {
            self::assertScalar('remoteCommand', $remoteCommand);
        }
        $identityFiles = [];
        // F-4: a leading ~ on an identity path expands here, against $HOME
        // (passwd fallback, loud refusal) — the same semantics the ssh_config
        // importer applies, now shared via IdentityPath. Everything else,
        // including every other field, passes verbatim to the argv.
        if (isset($row['identityFiles']) && $row['identityFiles'] !== null) {
            foreach (self::normalizeList($row['identityFiles']) as $f) {
                self::assertScalar('identityFiles', $f);
                $identityFiles[] = IdentityPath::expand((string) $f);
            }
        } elseif (isset($row['identity_files']) && $row['identity_files'] !== null) {
            foreach (self::normalizeList($row['identity_files']) as $f) {
                self::assertScalar('identity_files', $f);
                $identityFiles[] = IdentityPath::expand((string) $f);
            }
        } elseif (isset($row['identity_file']) && $row['identity_file'] !== null) {
            self::assertScalar('identity_file', $row['identity_file']);
            $identityFiles[] = IdentityPath::expand((string) $row['identity_file']);
        } elseif (isset($row['identityFile']) && $row['identityFile'] !== null) {
            self::assertScalar('identityFile', $row['identityFile']);
            $identityFiles[] = IdentityPath::expand((string) $row['identityFile']);
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
            proxyJump:     $proxyJump !== null ? (string) $proxyJump : null,
            options:      $opts,
            flags:        $flags,
            remoteCommand: $remoteCommand !== null ? (string) $remoteCommand : null,
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
     * `name: yes` reached ssh as the literal host user "1" (2026-10-23).
     * Numeric fields go through parsePort which rejects bools too.
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
