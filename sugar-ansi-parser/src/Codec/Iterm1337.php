<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Codec;

/**
 * iTerm2 / WezTerm OSC 1337 inline-image decoder.
 *
 * The parser already delivers OSC payloads to
 * {@see \SugarCraft\AnsiParser\Parser\Handler::oscDispatch()}; this codec is
 * the missing decode arm for the `1337` family — the fix for callers that
 * adopt it. (candy-ansi-inspect does not yet: it still swallows 1337
 * payloads today; see README "Decoders".) Wire form (as emitted by
 * sugar-core's `Ansi::iterm2InlineImage()` and sugar-image's
 * `Iterm2Renderer`):
 *
 *   OSC 1337 ; File = name=<urlencoded>;size=<bytes>;inline=1;… : <base64> BEL
 *
 * and the control forms (`1337;Pop`, `1337;SetMark`, …).
 *
 * {@see fromDcs()} covers the DCS arm — but only OUT OF BAND: under tmux the
 * whole OSC rides inside `ESC P tmux; <ESC-doubled inner> ESC \`, and
 * decoding that envelope requires the doubled `ESC ] 1337;` introducer to
 * still be present in the data argument. This lib's own parser never
 * delivers such a payload: the DcsString row treats 0x1B as a dispatch
 * terminator (Transitions), so ESC bytes cannot exist inside a
 * parser-produced DCS payload and the in-parser route to the tmux arm is
 * dead. fromDcs() is therefore for caller-assembled or externally-produced
 * payloads (another parser, a captured stream) — unwrapping is
 * deterministic: un-double ESC, find the inner `ESC ] 1337;`, take the body
 * up to BEL/ST.
 *
 * Malformed input never throws: a missing `:` separator, a parameter without
 * `=`, a negative `size=`, or a payload that stops short of its declared size
 * is recorded in {@see malformed()} and the fields that DID parse
 * are still exposed (APC/OSC payloads are attacker-controlled on untrusted
 * streams; a codec must not be a crash surface).
 */
final class Iterm1337
{
    private const OSC_PREFIX = '1337;';

    /** Longest name/argument excerpt `describe()` will quote, in bytes. */
    private const LABEL_BYTES = 80;

    /**
     * @param array<string, string> $args Percent-decoded `key=value` parameters
     *                                    (`+` kept verbatim; File only).
     */
    private function __construct(
        private readonly string $command,
        private readonly array $args,
        private readonly string $payload,
        private readonly ?string $malformed,
    ) {
    }

    /**
     * Decode an OSC payload (the string `oscDispatch()` receives). Returns
     * null when the payload is not a 1337 command.
     */
    public static function parse(string $data): ?self
    {
        if (!str_starts_with($data, self::OSC_PREFIX)) {
            return null;
        }

        return self::parseBody(substr($data, \strlen(self::OSC_PREFIX)));
    }

    /**
     * Decode the DCS arm: a tmux passthrough envelope (`ESC P tmux; … ESC \`)
     * carrying an OSC 1337. Returns null for any other DCS.
     *
     * Reachable only OUT OF BAND (finding sugar-ansi-parser-016): this lib's
     * parser dispatches DCS the moment it sees 0x1B, so its payloads carry no
     * ESC for the doubled introducer. Feed this from caller-assembled or
     * externally-produced data — never expect it to fire from a
     * dcsDispatch() this parser made.
     */
    public static function fromDcs(int $final, string $data): ?self
    {
        if ($final !== \ord('t') || !str_starts_with($data, 'mux;')) {
            return null;
        }

        // tmux doubles every ESC inside the envelope (TmuxPassthroughDecorator);
        // un-double first so the inner introducer/terminator are legible.
        $inner = str_replace("\x1b\x1b", "\x1b", substr($data, 4));
        $opener = "\x1b]1337;";
        $start = strpos($inner, $opener);
        if ($start === false) {
            return null;
        }
        $start += \strlen($opener);

        $bel = strpos($inner, "\x07", $start);
        $st = strpos($inner, "\x1b\\", $start);
        if ($bel === false && $st === false) {
            $end = \strlen($inner); // Truncated inner sequence — take what arrived.
        } elseif ($bel === false) {
            $end = $st;
        } elseif ($st === false) {
            $end = $bel;
        } else {
            $end = min($bel, $st);
        }

        return self::parseBody(substr($inner, $start, $end - $start));
    }

    private static function parseBody(string $body): ?self
    {
        if ($body === '') {
            return null;
        }

        $eq = strpos($body, '=');
        $command = $eq === false ? $body : substr($body, 0, $eq);

        if ($command !== 'File') {
            return new self($command, [], '', null);
        }

        $arguments = substr($body, $eq + 1);
        $colon = strpos($arguments, ':');
        $malformed = null;
        if ($colon === false) {
            $argString = $arguments;
            $payload = '';
            $malformed = 'missing payload separator';
        } else {
            $argString = substr($arguments, 0, $colon);
            $payload = substr($arguments, $colon + 1);
        }

        $args = [];
        foreach (explode(';', $argString) as $pair) {
            if ($pair === '') {
                continue;
            }
            $separator = strpos($pair, '=');
            if ($separator === false) {
                $malformed ??= 'parameter without "="';
                continue;
            }
            $args[substr($pair, 0, $separator)] = rawurldecode(substr($pair, $separator + 1));
        }

        // `urldecode()` would fold `+` into a space and synthesise control
        // bytes from %XX pairs into filename data; the protocol percent-
        // encodes only, so rawurldecode keeps `+` verbatim (%XX still decodes,
        // and describe() escapes whatever the decoded value contains).
        $size = $args['size'] ?? null;
        if (\is_string($size) && preg_match('/^-\d+$/', $size) === 1) {
            $malformed ??= 'negative size';
        }
        if ($colon !== false
            && \is_string($size)
            && preg_match('/^\d+$/', $size) === 1
            && self::estimateBytes($payload) < (int) $size
        ) {
            // The parser stops collecting at its 64 KiB cap (and a stream can
            // end early), leaving decoders blind to the dropped excess. A
            // payload that stops short of its declared size says so here.
            $malformed ??= 'payload truncated';
        }

        return new self($command, $args, $payload, $malformed);
    }

    /** Verb before the first `=` (`File`, `Pop`, `SetMark`, …); '' for an empty body. */
    public function command(): string
    {
        return $this->command;
    }

    public function isFile(): bool
    {
        return $this->command === 'File';
    }

    public function isPop(): bool
    {
        return $this->command === 'Pop';
    }

    /** @return array<string, string> Percent-decoded File parameters (`+` kept verbatim), in wire order. */
    public function args(): array
    {
        return $this->args;
    }

    public function arg(string $key): ?string
    {
        return $this->args[$key] ?? null;
    }

    /** Original file name (`name=`), percent-decoded (`+` kept verbatim); null when absent. */
    public function name(): ?string
    {
        return $this->args['name'] ?? null;
    }

    /** Declared file size in bytes (`size=`); null when absent or not a non-negative decimal. */
    public function declaredSize(): ?int
    {
        $raw = $this->args['size'] ?? null;
        if ($raw === null || $raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    /** `inline=1` — render inline instead of saving to disk. Absent means 0. */
    public function inline(): bool
    {
        return ($this->args['inline'] ?? '') === '1';
    }

    /** Requested width (`width=`), verbatim: `N`, `Npx` or `N%`. */
    public function width(): ?string
    {
        return $this->args['width'] ?? null;
    }

    /** Requested height (`height=`), verbatim: `N`, `Npx` or `N%`. */
    public function height(): ?string
    {
        return $this->args['height'] ?? null;
    }

    /** `preserveAspectRatio=1|0`; null when absent or not 1/0. */
    public function preserveAspectRatio(): ?bool
    {
        return match ($this->args['preserveAspectRatio'] ?? null) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /** Payload container (`type=tiff`, …); absent means PNG (protocol default). */
    public function type(): ?string
    {
        return $this->args['type'] ?? null;
    }

    /** Base64 payload that followed the `:` separator. */
    public function payloadBase64(): string
    {
        return $this->payload;
    }

    /** Length of the base64 payload in characters. */
    public function payloadLength(): int
    {
        return \strlen($this->payload);
    }

    /** Decoded payload size without decoding it (base64 arithmetic). */
    public function estimatedBytes(): int
    {
        return self::estimateBytes($this->payload);
    }

    private static function estimateBytes(string $payload): int
    {
        $length = \strlen($payload);
        if ($length === 0) {
            return 0;
        }
        $padding = substr_count(substr($payload, -2), '=');

        return intdiv($length * 3, 4) - $padding;
    }

    /** Reason the wire form was malformed, or null when it parsed cleanly. */
    public function malformed(): ?string
    {
        return $this->malformed;
    }

    /**
     * One-line, control-byte-safe summary. The name is percent-decoded on the
     * way in, so it is escaped here before reaching a terminal label.
     */
    public function describe(): string
    {
        if (!$this->isFile()) {
            // Via fromDcs the DCS body admits 0x00-0x17 Put
            // bytes (the OSC path is protected — Transitions drops them in
            // OscString), so the command verb needs the same escape as the
            // percent-decoded name before reaching a log label.
            return 'iTerm2 ' . ($this->command === '' ? '(empty)' : self::safe($this->command));
        }

        $parts = ['iTerm2 inline image'];
        $name = $this->name();
        if ($name !== null) {
            $parts[] = '"' . self::safe($name) . '"';
        }
        if ($this->declaredSize() !== null) {
            $parts[] = 'size=' . $this->declaredSize();
        }
        if ($this->inline()) {
            $parts[] = 'inline=1';
        }
        $parts[] = $this->estimatedBytes() . ' bytes';

        $label = implode(' ', $parts);

        return $this->malformed === null ? $label : $label . ' (malformed: ' . $this->malformed . ')';
    }

    private static function safe(string $bytes): string
    {
        return LabelSanitizer::clip($bytes, self::LABEL_BYTES);
    }
}
