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
 * corrupting. The two engines are NOT interchangeable parsers, but the
 * file-level dialect laws — one document (no extra `---`/`...`), no merge
 * keys, no bare CR inside a line, no structural-indicator plain values,
 * no duplicate keys, null sentinels (`~`/`null`/empty) decoding to null —
 * are enforced BEFORE the engine is chosen, so a hostile or mistyped file
 * is refused identically either way. What still differs: the fallback
 * refuses an unquoted colon-containing key that symfony accepts (`e:f: hey`
 * is the key `e:f` under symfony), it keeps `true`-style value tokens as
 * literal text where symfony decodes them to a bool (the importer refuses
 * bools, so such a document imports on the fallback and is refused under
 * symfony; a decoded int stringifies on import and lands identically
 * either way), and symfony may wrap very long plain scalars across lines,
 * which the fallback refuses loudly rather than half-parses. A single
 * process always uses one engine for both directions, so its own
 * round-trips are exact.
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

        self::refuseDialectLaws($yaml);

        return self::parseFlat($yaml);
    }

    /**
     * File-level dialect laws shared by BOTH engines (kv-020, kv-012,
     * kv-071): the flat dialect is one mapping of pairs. `Yaml::parse`
     * hands multi-document streams to the first document only (or throws
     * "Multiple documents"), `<<` merge keys inject entries that are not
     * lines of the file, and the fallback historically FUSED a post-`...`
     * document into the first. A bare CR inside a line is a line break to
     * symfony and invisible garbage to the fallback, and an indicator-led
     * plain value (`&anchor v`, `[a]`, `*alias`) means structure to one
     * engine and a literal string to the other. Every one of these is a
     * smuggling door for a hostile file — refuse up front so the engine
     * choice cannot change the outcome.
     *
     * @throws \RuntimeException naming the construct (and line where known).
     */
    private static function refuseDialectLaws(string $yaml): void
    {
        if (\preg_match_all('/^[ \t]*---[ \t]*$/m', $yaml) > 1) {
            throw new \RuntimeException(
                'Syntax error: more than one document start marker (---); this dialect is a single mapping.'
            );
        }
        // Any content line after a document-end marker is a second document
        // the old marker-strip fused into the first.
        if (\preg_match('/^[ \t]*\.\.\.[ \t]*$\n(?:[ \t]*(?:#.*)?\n)*[ \t]*[^ \t\r\n#]/', $yaml) === 1) {
            throw new \RuntimeException(
                'Syntax error: content after a document end marker (...); this dialect is a single mapping.'
            );
        }
        if (\preg_match('/^[ \t]*<<[ \t]*:(?:[ \t]|$)/m', $yaml) === 1) {
            throw new \RuntimeException(
                'Syntax error: merge keys (<<) are not part of this dialect — they inject entries the file never names.'
            );
        }

        foreach (\explode("\n", $yaml) as $index => $line) {
            self::refuseLineLaws(\trim($line), $index + 1);
        }
    }

    /**
     * Per-line dialect laws (see refuseDialectLaws). A CRLF file stays
     * legal: trim() eats the trailing CR, so only a CR left INSIDE the
     * trimmed line is the illegal shape.
     */
    private static function refuseLineLaws(string $trimmed, int $lineNo): void
    {
        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed === '---' || $trimmed === '...') {
            return;
        }

        if (\strpos($trimmed, "\x0D") !== false) {
            throw new \RuntimeException(\sprintf(
                'Syntax error on line %d: a bare carriage return inside a line; end lines with LF (whole-file CRLF is fine) or spell CR as \\r inside a double-quoted scalar.',
                $lineNo,
            ));
        }

        $pair = self::pairPositions($trimmed);
        if ($pair === null) {
            return; // not a pair line; splitPair or the engine refuses garbage itself
        }
        self::refuseIndicatorToken($pair[0], 'key', $lineNo);
        self::refuseIndicatorToken($pair[1], 'value', $lineNo);
    }

    /**
     * The raw source text of a line's key and value positions (null for a
     * quoted key — its quotes ARE the shape law), or null when the line is
     * not a pair at all (structural garbage — splitPair or the engine will
     * refuse it on their own). Mirrors splitPair's key syntax exactly so
     * the preflight cannot disagree with the parser.
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    private static function pairPositions(string $trimmed): ?array
    {
        if ($trimmed[0] === '"' || $trimmed[0] === "'") {
            try {
                $end = self::findClosingQuote($trimmed, 0, $trimmed[0]);
            } catch (\RuntimeException) {
                return null;
            }
            $rest = \trim(\substr($trimmed, $end + 1));
            if ($rest === '' || $rest[0] !== ':') {
                return null;
            }

            return [null, \ltrim(\substr($rest, 1))];
        }

        if (\preg_match('/^([^:]+):(?:\s+(.*))?$/', $trimmed, $m) === 1) {
            return [\rtrim($m[1]), \ltrim($m[2] ?? '')];
        }

        return null;
    }

    /**
     * kv-071: an unquoted token starting with a YAML indicator character
     * means STRUCTURE to one engine and a literal string to the other —
     * `- a`/`-` is a sequence entry, `[`/`{` a flow collection, `*`/`&` an
     * alias/anchor, `>`/`|` a block scalar, `!` a tag, `%` a directive,
     * `@`/backtick reserved. symfony's own dumper never emits such a lead
     * unquoted (isPlainSafe quotes it), so refusing here costs nothing the
     * dialect ever writes. `!!binary ` stays legal — it is symfony's
     * inline control-byte shape that the fallback reads verbatim.
     */
    private static function refuseIndicatorToken(?string $token, string $position, int $lineNo): void
    {
        if ($token === null || $token === '' || $token[0] === '#') {
            return; // absent, or only a comment
        }
        if (\str_starts_with($token, '!!binary ')) {
            return;
        }

        $first = $token[0];
        $sequenceDash = $first === '-' && ($token === '-' || $token[1] === ' ' || $token[1] === "\t");
        if ($sequenceDash || \strpos('[{*&>|%@`!', $first) !== false) {
            throw new \RuntimeException(\sprintf(
                'Syntax error on line %d: a %s starting with "%s" is YAML structure, not this flat dialect — double-quote it to keep the literal.',
                $lineNo,
                $position,
                $first,
            ));
        }
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

        // Refuse the file-level dialect violations BEFORE choosing an engine
        // — under symfony a second document, a fused '...' tail, an
        // indicator-led value or a lone CR used to parse (or fail) in ways
        // the fallback never would (kv-012, kv-020, kv-071).
        self::refuseDialectLaws($yaml);

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
        $firstSeen = [];
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

            // kv-078: a repeated key silently LAST-WON on the old fallback
            // while symfony throws, so the same file imported different
            // values depending on an optional package. Refuse it here too.
            if (\array_key_exists($key, $firstSeen)) {
                throw new \RuntimeException(\sprintf(
                    'Syntax error on line %d: duplicate key "%s" (first defined on line %d); the last line would silently win.',
                    $lineNo,
                    $key,
                    $firstSeen[$key],
                ));
            }
            $firstSeen[$key] = $lineNo;

            $result[$key] = $val;
        }

        return $result;
    }

    /**
     * Split a `key: value` line, honouring quoted keys and values.
     *
     * @return array{0: string, 1: string|null}
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
     * The plain null sentinels (`~`, `null`/`Null`/`NULL`, empty — kv-017)
     * decode to null EXACTLY like symfony's engine does, so the importer's
     * refuse-null law fires identically whichever parser read the file; the
     * old fallback silently turned them into `""`. A quoted `"~"` is a
     * deliberate string and must survive verbatim, which is exactly what
     * the emitter promises when it quotes those shapes. A `#` introduced by
     * whitespace opens a trailing comment on a plain token and after a
     * closing quote (kv-018 — the old fallback kept both as literal value
     * text that symfony drops); `foo#bar` has no space before the hash and
     * keeps it.
     */
    private static function parseValueToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        if ($token[0] === '"' || $token[0] === "'") {
            $end = self::findClosingQuote($token, 0, $token[0]);
            $tail = \trim(\substr($token, $end + 1));
            if ($tail !== '' && $tail[0] !== '#') {
                // Trailing junk after the closing quote: refuse rather than
                // silently truncating. A '#'-led tail is a comment.
                throw new \RuntimeException('unexpected text after the closing quote');
            }

            return self::unescapeQuoted(\substr($token, 0, $end + 1));
        }

        if ($token[0] === '#') {
            return null; // the 'value' was a comment all along
        }

        if (\preg_match('/\s+#/', $token, $cm, \PREG_OFFSET_CAPTURE) === 1) {
            $token = \rtrim(\substr($token, 0, $cm[0][1]));
            if ($token === '') {
                return null;
            }
        }

        if ($token === '~' || $token === 'null' || $token === 'Null' || $token === 'NULL') {
            return null;
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

        if ($token[0] === '!') {
            // Any other explicit tag (`!!str`, `!foo`) is beyond this
            // dialect — symfony coerces or throws depending on the tag, so
            // the line preflight already refused the shape on both routes.
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

    /**
     * Unwrap and unescape a quoted scalar.
     *
     * Single quotes: doubling only (`''` → `'`). Double quotes: the FULL
     * YAML escape set (kv-072) — `\0 \a \b \t \n \v \f \r \e \<space> \" \/
     * \\` plus the named marks `\N` (NEL) `\_` (NBSP) `\L` (LS) `\P` (PS)
     * and `\xHH`/`\uXXXX`/`\UXXXXXXXX` with JSON surrogate pairing. The old
     * machine re-EMITTED an unrecognised `\q` as literal `\q` while symfony
     * refuses the document, so the same file decoded to two different
     * values depending on an optional package; unknown escapes now throw.
     */
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
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            if ($i + 1 >= $len) {
                throw new \RuntimeException('a double-quoted scalar ends with an incomplete escape sequence');
            }
            $next = $inner[++$i];
            $out .= match ($next) {
                '0' => "\0",
                'a' => "\x07",
                'b' => "\x08",
                't' => "\t",
                'n' => "\n",
                'v' => "\x0B",
                'f' => "\x0C",
                'r' => "\r",
                'e' => "\x1B",
                ' ' => ' ',
                '"' => '"',
                '/' => '/',
                '\\' => '\\',
                'N' => self::utf8(0x85),
                '_' => self::utf8(0xA0),
                'L' => self::utf8(0x2028),
                'P' => self::utf8(0x2029),
                'x' => self::expandCode($inner, $i, 2),
                'u' => self::expandCode($inner, $i, 4),
                'U' => self::expandCode($inner, $i, 8),
                default => throw new \RuntimeException(\sprintf(
                    'unknown escape sequence "\\%s" in a double-quoted scalar',
                    $next,
                )),
            };
        }

        return $out;
    }

    /**
     * Read a `\x`/`\u`/`\U` escape starting just after its marker letter
     * ($i points at the letter; it advances past every consumed digit).
     * Surrogate pairing follows JSON rules: a high surrogate must be
     * followed immediately by a `\uDC00..\uDFFF` low half — unpaired,
     * low-alone, or beyond U+10FFFF all throw.
     */
    private static function expandCode(string $inner, int &$i, int $width): string
    {
        $hex = \substr($inner, $i + 1, $width);
        if (\preg_match('/^[0-9A-Fa-f]{' . $width . '}$/', $hex) !== 1) {
            throw new \RuntimeException(\sprintf(
                'invalid \\%s escape: expected %d hex digits',
                $width === 2 ? 'x' : ($width === 4 ? 'u' : 'U'),
                $width,
            ));
        }
        $i += $width;
        $cp = (int) \hexdec($hex);

        if ($cp >= 0xD800 && $cp <= 0xDBFF) {
            $tail = \substr($inner, $i + 1, 6);
            if (\preg_match('/^\\\\u([0-9A-Fa-f]{4})$/', $tail, $m) !== 1) {
                throw new \RuntimeException('unpaired high surrogate in a double-quoted scalar');
            }
            $low = (int) \hexdec($m[1]);
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw new \RuntimeException('a high surrogate must be followed by a low-surrogate \\u escape');
            }
            $i += 6;

            return self::utf8(0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00));
        }
        if ($cp >= 0xDC00 && $cp <= 0xDFFF) {
            throw new \RuntimeException('lone low surrogate in a double-quoted scalar');
        }
        if ($cp > 0x10FFFF) {
            throw new \RuntimeException('an escape beyond U+10FFFF is not a character');
        }

        return self::utf8($cp);
    }

    /**
     * Minimal UTF-8 encoder — the parser carries no ext-mbstring
     * dependency, so codepoints are built byte by byte.
     */
    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
            . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }
}
