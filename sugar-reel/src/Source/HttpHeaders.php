<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Source;

use SugarCraft\Reel\Lang;

/**
 * Immutable, already-validated HTTP request headers for a remote video source.
 *
 * WHY THIS CLASS EXISTS. A header string reaching ffmpeg is not inert data: ffmpeg
 * pastes it verbatim into the request it writes to the socket, so a value
 * containing CR or LF can terminate the header block and start smuggling a
 * second request line, an extra header, or a body onto the wire (HTTP request
 * smuggling / response splitting in miniature). The argv array handed to
 * `proc_open()` cannot inject an *argument* — no shell is involved — but it says
 * nothing about what the argument MEANS once ffmpeg writes it out. So the safety
 * question has to be answered before the value is ever stored, which is exactly
 * what a parse-at-the-boundary value object does: an `HttpHeaders` instance you
 * are holding has already been through {@see parse()}, and every emitter below
 * can assume its pairs are transport-safe without re-checking.
 *
 * Two input shapes are accepted, because both are how callers naturally think
 * about headers and neither can be confused with the other:
 *
 *   - `['Authorization' => 'Bearer …', 'Cookie' => 'sid=…']` (name => value)
 *   - `['Authorization: Bearer …', 'Cookie: sid=…']` (one field line each —
 *     the shape ffmpeg's own `-headers` option uses)
 *
 * A numeric array key always means the second shape: PHP casts `'123' => …`
 * to int 123, so a purely numeric header NAME cannot be expressed as
 * name => value here — supply it as a field line instead.
 *
 * `User-Agent` is deliberately pulled OUT of the generic `-headers` blob and
 * emitted as ffmpeg's dedicated `-user_agent` option instead: ffmpeg writes the
 * option as its own `User-Agent:` line, so leaving it in the blob too would put
 * two of them on the wire, which strict servers reject.
 */
final class HttpHeaders
{
    /**
     * CR, LF or NUL anywhere in a name or value is what makes the string unsafe
     * to emit. CR/LF end the HTTP header block (request smuggling); NUL is the
     * argv delimiter — exec-level C-string handling would silently truncate the
     * argument while our stored pair kept the full text (credential corruption).
     */
    private const LINE_BREAK = '/[\x00\r\n]/';

    /** A header field-name must be an RFC 7230 token: no separators, no space. */
    private const VALID_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /**
     * @param list<array{name: string, value: string}> $pairs Validated, in submission order.
     */
    private function __construct(private readonly array $pairs)
    {
    }

    /**
     * The empty set — no headers supplied, nothing to emit.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Validate raw caller-supplied headers into a trusted instance.
     *
     * @param array<array-key, string> $headers Name => value, or a list of "Name: value" lines.
     * @throws \InvalidArgumentException When a name is empty/not a token/contains a line break,
     *         when a value contains a line break, or when a field line has no colon.
     */
    public static function parse(array $headers): self
    {
        $pairs = [];
        foreach ($headers as $key => $item) {
            if (is_int($key)) {
                // A full "Name: value" field line — split on the FIRST colon only,
                // so a value containing a colon (a URL, a date) survives intact.
                $split = explode(':', (string) $item, 2);
                if (count($split) !== 2) {
                    throw new \InvalidArgumentException(Lang::t('header.malformed_field', [
                        'field' => self::quote((string) $item),
                    ]));
                }
                [$name, $value] = $split;
            } else {
                $name = (string) $key;
                $value = (string) $item;
            }

            $pairs[] = ['name' => self::checkedName($name), 'value' => self::checkedValue($name, $value)];
        }

        return new self($pairs);
    }

    /**
     * True when there is nothing to add to the request.
     */
    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }

    /**
     * The validated pairs, in submission order.
     *
     * @return list<array{name: string, value: string}>
     */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /**
     * The User-Agent value to pass as ffmpeg's `-user_agent`, or null when none was given.
     *
     * Last occurrence wins, matching how a receiver would read duplicate headers.
     */
    public function userAgent(): ?string
    {
        $agent = null;
        foreach ($this->pairs as $pair) {
            if (strcasecmp($pair['name'], 'User-Agent') === 0) {
                $agent = $pair['value'];
            }
        }

        return $agent;
    }

    /**
     * The value for ffmpeg's `-headers` option: every header except User-Agent,
     * each terminated by CRLF exactly as it will appear on the wire.
     *
     * Returns null when nothing is left to emit, so the caller omits the option
     * rather than passing ffmpeg an empty string.
     */
    public function toFfmpegHeaderString(): ?string
    {
        $lines = [];
        foreach ($this->pairs as $pair) {
            if (strcasecmp($pair['name'], 'User-Agent') === 0) {
                continue; // emitted as -user_agent, never twice
            }
            $lines[] = $pair['name'] . ': ' . $pair['value'] . "\r\n";
        }

        return $lines === [] ? null : implode('', $lines);
    }

    /**
     * The values for mpv's repeatable `--http-header-fields` option (one bare
     * `Name: value` per occurrence; mpv adds its own line terminators).
     *
     * Unlike {@see toFfmpegHeaderString()} this KEEPS User-Agent in the list —
     * mpv receives it as an ordinary field. Never pair this emitter with a
     * separate `--user-agent` option or the request goes out with two.
     *
     * @return list<string>
     */
    public function toMpvHeaderFields(): array
    {
        return array_map(
            static fn (array $pair): string => $pair['name'] . ': ' . $pair['value'],
            $this->pairs,
        );
    }

    /**
     * Trim and validate a header name, rejecting anything that is not a single
     * RFC 7230 token. The empty name and a name carrying CR/LF are the two ways
     * a "name" turns into a smuggled request line; a colon would let the name
     * itself declare a second field.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkedName(string $name): string
    {
        // Reject a line break in the RAW name before trimming — silently
        // sanitising "X-Foo\r\n" into "X-Foo" would smuggle nothing but would
        // also hide a caller bug; trim() is only for legitimate edge spaces.
        if (preg_match(self::LINE_BREAK, $name) === 1) {
            throw new \InvalidArgumentException(Lang::t('header.invalid_name', [
                'name' => self::quote($name),
            ]));
        }

        $trimmed = trim($name);
        if ($trimmed === '' || !preg_match(self::VALID_NAME, $trimmed)) {
            throw new \InvalidArgumentException(Lang::t('header.invalid_name', [
                'name' => self::quote($name),
            ]));
        }

        return $trimmed;
    }

    /**
     * Validate a header value, rejecting line breaks and NUL — the injection
     * and truncation vectors.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkedValue(string $name, string $value): string
    {
        if (preg_match(self::LINE_BREAK, $value) === 1) {
            throw new \InvalidArgumentException(Lang::t('header.invalid_value', [
                'name' => self::quote($name),
            ]));
        }

        return trim($value);
    }

    /**
     * Render a rejected value for an exception message without letting the
     * offending control characters break the log line they land in — or, in a
     * terminal library, paint one. Every C0 control and DEL gets an escaped
     * form; CR/LF/NUL keep their readable short names because that is what
     * callers usually tripped on.
     */
    private static function quote(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static fn (array $m): string => match ($m[0]) {
                "\r" => '\\r',
                "\n" => '\\n',
                "\0" => '\\0',
                default => '\\x' . str_pad(dechex(ord($m[0])), 2, '0', STR_PAD_LEFT),
            },
            $value,
        ) ?? $value;
    }
}
