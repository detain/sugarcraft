<?php

declare(strict_types=1);

namespace SugarCraft\Kv;

/**
 * The flat scalar YAML dialect shared by `candy-kv` export and import.
 *
 * candy-kv YAML is a single-level `key: value` map with reserved metadata
 * prefixes:
 *
 *   skate_ttl_<key>: <seconds>     per-entry TTL
 *   skate_binary_<key>: 1          entry value is base64 of raw bytes
 *
 * When symfony/yaml is present both build() and parse() route through it
 * (declared as a `suggest` + dev dependency — the same component upstream
 * skate's family uses). Without it, the fallback emitter/parser below
 * implement exactly this dialect — including proper double-quote escaping
 * (backslashes, newlines, control characters) and quoted-key support — so
 * an export of `C:\temp` or a multi-line value reimports intact instead of
 * corrupting. The two engines are NOT interchangeable parsers, though each
 * reads its own output exactly: the fallback refuses an unquoted
 * colon-containing key that symfony accepts (`e:f: hey` is the key `e:f`
 * under symfony), it keeps `true`-style value tokens as literal text where
 * symfony decodes them to a bool (the importer refuses bools, so such a
 * document imports on the fallback and is refused under symfony; a decoded
 * int stringifies on import and lands identically either way), and symfony
 * may wrap very long plain scalars across lines, which the fallback
 * refuses loudly rather than half-parses. A single process always uses one
 * engine for both directions, so its own round-trips are exact.
 * {@see \SugarCraft\Kv\Import\YamlImporter::importFromString()} is where
 * the bool refusal lands.
 *
 * @internal This class is the export/import wire format, not a public API.
 */
final class YamlDialect
{
    /** Prefix for per-key TTL metadata lines. */
    public const TTL_PREFIX = 'skate_ttl_';

    /** Prefix for per-key binary-flag metadata lines. */
    public const BINARY_PREFIX = 'skate_binary_';

    /** Reserved JSON envelope keys. */
    public const JSON_TTL_KEY = '_ttl';

    public const JSON_BINARY_KEY = '_binary';

    /**
     * Render a flat key => scalar map as YAML with the strict fallback
     * emitter (the published behaviour when symfony/yaml is absent; the
     * dialect contract itself, unit-testable either way).
     *
     * @param array<string, string|int> $flat
     */
    public static function buildFallback(array $flat): string
    {
        $lines = [];
        foreach ($flat as $key => $value) {
            $lines[] = self::emitScalarKey((string) $key) . ': ' . self::emitScalarValue($value);
        }

        return $lines === [] ? '' : \implode("\n", $lines) . "\n";
    }

    /**
     * Parse with the strict fallback parser (dialect contract, unit-testable
     * regardless of whether symfony/yaml is installed).
     *
     * @return array<string, mixed>
     */
    public static function parseFallback(string $yaml): array
    {
        if (\trim($yaml) === '') {
            return [];
        }

        return self::parseFlat($yaml);
    }

    public static function build(array $flat): string
    {
        if ($flat === []) {
            return '';
        }

        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::dump($flat, 2, 4);
        }

        return self::buildFallback($flat);
    }

    /**
     * Parse YAML text into a flat array (values may be string|int|null).
     *
     * @return array<array-key, mixed>
     * @throws \RuntimeException on unparseable input.
     */
    public static function parse(string $yaml): array
    {
        if (\trim($yaml) === '') {
            return [];
        }

        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            // The dialect is one flat document; the fallback has always
            // skipped `---`/`...` marker lines, and symfony refuses files
            // with a trailing marker pair outright ("Multiple documents").
            // Stripping keeps both engines at identical accept/refuse shape.
            $yaml = \preg_replace('/^[ \t]*(?:---|\.\.\.)[ \t]*$/m', '', $yaml) ?? $yaml;
            try {
                $data = \Symfony\Component\Yaml\Yaml::parse($yaml);
            } catch (\Throwable $e) {
                // Relay symfony's own diagnosis (it names the offending
                // line and nearby text) — replacing it with a bare
                // 'Syntax error' threw away the only location the caller
                // could act on. First line only: symfony appends the whole
                // fragment with newlines.
                $reason = \strtok($e->getMessage(), "\n") ?: 'unparseable document';
                throw new \RuntimeException('Syntax error: ' . $reason, 0, $e);
            }
            if ($data === null) {
                return [];
            }
            if (!\is_array($data)) {
                throw new \RuntimeException(
                    'Syntax error: expected a mapping of key: value entries, got ' . \get_debug_type($data) . '.'
                );
            }

            return $data;
        }

        return self::parseFlat($yaml);
    }

    /**
     * Does this key need to be refused at export time because the envelope
     * would corrupt it on reimport (it collides with a reserved form)?
     *
     * The two formats reserve DIFFERENT namespaces: the JSON envelope rides
     * `_ttl`/`_binary` keys, the YAML dialect rides `skate_ttl_`/
     * `skate_binary_` line prefixes. Checking one format's reserves against
     * the other used to refuse legitimate entries (`skate_ttl_backup` could
     * not be exported as JSON at all). Pass the export format to scope the
     * check; null checks every reserved family (legacy callers).
     *
     * @param string|null $format 'json', 'yaml', 'yml', or null for all.
     * @return string|null the refusal reason, or null when the key is safe.
     */
    public static function reservedKeyReason(string $key, ?string $format = null): ?string
    {
        $json = $format === null || $format === 'json';
        $yaml = $format === null || $format === 'yaml' || $format === 'yml';

        if ($json && ($key === self::JSON_TTL_KEY || $key === self::JSON_BINARY_KEY)) {
            return \sprintf('the key "%s" is reserved by the JSON export envelope and cannot round-trip', $key);
        }
        if ($yaml && (\str_starts_with($key, self::TTL_PREFIX) || \str_starts_with($key, self::BINARY_PREFIX))) {
            return \sprintf('the key "%s" collides with the YAML metadata prefixes and cannot round-trip', $key);
        }

        return null;
    }

    /**
     * Decode a base64 binary-envelope value, refusing invalid input loudly
     * (storing the broken text as 'binary' would silently corrupt the entry).
     */
    public static function decodeBinary(string $b64, string $key): string
    {
        $raw = \base64_decode($b64, true);
        if ($raw === false) {
            throw new \RuntimeException(\sprintf("Binary entry '%s' is not valid base64.", $key));
        }

        return $raw;
    }

    // -------------------------------------------------------------------------
    // Fallback emitter
    // -------------------------------------------------------------------------

    private static function emitScalarKey(string $key): string
    {
        if ($key !== '' && \preg_match('/^[A-Za-z0-9_\-.@]+$/', $key) === 1) {
            return $key;
        }

        return self::doubleQuote($key);
    }

    private static function emitScalarValue(string|int $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        if (self::isPlainSafe($value)) {
            return $value;
        }

        return self::doubleQuote($value);
    }

    /**
     * Conservative plain-scalar safety test: quote anything with a colon,
     * hash, backslash, quote, control byte, leading/trailing space, leading
     * indicator, reserved word reading, or numeric-like shape.
     */
    private static function isPlainSafe(string $v): bool
    {
        if ($v === '') {
            return false;
        }
        if (\preg_match('/[\x00-\x1f\x7f:\\\\"\'#]/', $v) === 1) {
            return false;
        }
        if ($v !== \trim($v)) {
            return false;
        }
        if (\preg_match('/^[- >*!&%@`{}\[\],?|]/', $v) === 1) {
            return false;
        }
        if (\in_array(\strtolower($v), ['~', 'null', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return false;
        }
        if (\preg_match('/^[0-9+\-.eE]+$/', $v) === 1) {
            return false;
        }

        return true;
    }

    private static function doubleQuote(string $value): string
    {
        $escaped = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            $escaped .= match ($c) {
                "\\" => '\\\\',
                '"' => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\0" => '\\0',
                default => (\ord($c) < 0x20 || \ord($c) === 0x7f)
                    ? \sprintf('\\x%02X', \ord($c))
                    : $c,
            };
        }

        return '"' . $escaped . '"';
    }

    // -------------------------------------------------------------------------
    // Fallback parser
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function parseFlat(string $yaml): array
    {
        $result = [];
        $lineNo = 0;
        foreach (\explode("\n", $yaml) as $line) {
            $lineNo++;
            $trimmed = \trim($line);
            if ($trimmed === '' || \str_starts_with($trimmed, '#')) {
                // Blank AND whitespace-only lines carry no pair; feeding an
                // empty string to splitPair read offset 0 of nothing.
                continue;
            }

            if ($trimmed === '---' || $trimmed === '...') {
                continue;
            }

            try {
                [$key, $val] = self::splitPair($trimmed);
            } catch (\RuntimeException $e) {
                // One bare 'Syntax error' for a 500-line file told the user
                // nothing they could fix; every dialect error now names its
                // line and its reason.
                throw new \RuntimeException(
                    \sprintf('Syntax error on line %d: %s', $lineNo, $e->getMessage()),
                    0,
                    $e,
                );
            }
            $result[$key] = $val;
        }

        return $result;
    }

    /**
     * Split a `key: value` line, honouring quoted keys and values.
     *
     * @return array{0: string, 1: string}
     * @throws \RuntimeException when the line is not a flat pair (the
     *         message names the defect; parseFlat prepends the line number).
     */
    private static function splitPair(string $trimmed): array
    {
        // Quoted key form: "..." or '...' followed by ': ' or ':'.
        if ($trimmed[0] === '"' || $trimmed[0] === "'") {
            $quote = $trimmed[0];
            $end = self::findClosingQuote($trimmed, 0, $quote);
            $keySrc = \substr($trimmed, 0, $end + 1);
            $rest = \trim(\substr($trimmed, $end + 1));
            if ($rest === '' || $rest[0] !== ':') {
                throw new \RuntimeException("expected ':' after the quoted key");
            }
            $key = self::unescapeQuoted($keySrc);
            $valueRaw = \trim(\substr($rest, 1));

            return [$key, self::parseValueToken($valueRaw)];
        }

        // Plain key: everything up to the first ':' that is followed by a
        // space or end-of-line (a plain scalar may contain colons otherwise).
        if (\preg_match('/^([^:]+):(?:\s+(.*))?$/', $trimmed, $m) === 1) {
            $key = $m[1];
            $valueRaw = \trim($m[2] ?? '');
            if ($key === '' || $key !== \rtrim($key)) {
                throw new \RuntimeException('missing key');
            }

            return [$key, self::parseValueToken($valueRaw)];
        }

        // A block-scalar continuation line (indent + text) is only legal
        // under a parser we do not have — refuse loudly like before.
        if (\str_starts_with($trimmed, '- ') || $trimmed === '-') {
            throw new \RuntimeException('sequence entries are not part of this dialect');
        }

        throw new \RuntimeException('not a key: value pair');
    }

    /**
     * Resolve a value token (plain / single-quoted / double-quoted).
     *
     * The null sentinels (`~`, bare `null`) and integer coercion apply ONLY
     * to plain tokens — a quoted `"~"` or `"3600"` is a deliberate string
     * and must survive verbatim, which is exactly what the emitter promises
     * when it quotes those shapes.
     */
    private static function parseValueToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        if ($token[0] === '"' || $token[0] === "'") {
            $end = self::findClosingQuote($token, 0, $token[0]);
            if ($end !== \strlen($token) - 1) {
                // Trailing junk after the closing quote: refuse rather than
                // silently truncating.
                throw new \RuntimeException('unexpected text after the closing quote');
            }

            return self::unescapeQuoted($token);
        }

        if ($token === '~' || $token === 'null') {
            return '';
        }

        // Symfony's dumper tags control-byte strings as `!!binary <base64>`
        // (always inline — verified against the component). Accept that
        // shape so the fallback can read symfony's output verbatim.
        if (\str_starts_with($token, '!!binary ')) {
            $decoded = \base64_decode(\substr($token, 9), true);
            if ($decoded === false) {
                throw new \RuntimeException('invalid base64 in a !!binary value');
            }

            return $decoded;
        }

        if (\str_starts_with($token, '!!')) {
            // Any other explicit tag is beyond this dialect — refuse loudly.
            throw new \RuntimeException('unsupported YAML tag');
        }

        return $token;
    }

    /**
     * Index of the closing $quote starting from an opening quote at $start.
     * Handles YAML doubling ('' inside single quotes, \" inside double).
     */
    private static function findClosingQuote(string $text, int $start, string $quote): int
    {
        $len = \strlen($text);
        for ($i = $start + 1; $i < $len; $i++) {
            if ($text[$i] === '\\' && $quote === '"') {
                $i++; // skip escaped char
                continue;
            }
            if ($text[$i] !== $quote) {
                continue;
            }
            if ($quote === "'" && $i + 1 < $len && $text[$i + 1] === "'") {
                $i++; // doubled single quote
                continue;
            }

            return $i;
        }

        throw new \RuntimeException('unterminated quoted string');
    }

    private static function unescapeQuoted(string $quoted): string
    {
        $quote = $quoted[0];
        $inner = \substr($quoted, 1, -1);

        if ($quote === "'") {
            return \str_replace("''", "'", $inner);
        }

        $out = '';
        $len = \strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $c = $inner[$i];
            if ($c !== '\\' || $i + 1 >= $len) {
                $out .= $c;
                continue;
            }
            $next = $inner[++$i];
            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '0' => "\0",
                '\\' => '\\',
                '"' => '"',
                'x' => self::expandHex($inner, $i),
                default => '\\' . $next,
            };
        }

        return $out;
    }

    /**
     * Read \xHH starting just after the 'x' marker (index advanced by caller).
     */
    private static function expandHex(string $inner, int &$i): string
    {
        $hex = \substr($inner, $i + 1, 2);
        if (\preg_match('/^[0-9A-Fa-f]{2}$/', $hex) !== 1) {
            throw new \RuntimeException('invalid hex escape');
        }
        $i += 2;

        return \chr((int) \hexdec($hex));
    }
}
