<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use RuntimeException;
use SugarCraft\Testing\Lang;

/**
 * Byte-level decoder for the iTerm2 inline-image protocol.
 *
 * Reads the OSC 1337 form emitted by sugarcraft/candy-mosaic
 * (`\x1b]1337;File=k=v;k=v:<base64>` closed by BEL or ST) and its companion
 * control sequences (`Pop`, and `File=…;do=…` actions that carry no data).
 *
 * The width/height arguments are character-cell counts (matching candy-mosaic's
 * default sizing), exposed as {@see cellsWidth()} / {@see cellsHeight()}.
 *
 * Mirrors charmbracelet/candy-mosaic Iterm2Renderer (inverse).
 */
final class Iterm2Stream
{
    private const OSC = "\x1b]";

    private const PREFIX = '1337;';

    private const ST = "\x1b\\";

    /**
     * @param array<string, string> $params
     */
    private function __construct(
        private readonly string $command,
        private readonly array $params,
        private readonly string $payload,
    ) {
    }

    /**
     * Decode the first iTerm2 sequence found in a byte stream.
     */
    public static function decode(string $stream): self
    {
        $start = strpos($stream, self::OSC . self::PREFIX);
        if ($start === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.missing_osc'));
        }

        $bodyStart = $start + strlen(self::OSC . self::PREFIX);
        $end = self::findTerminator($stream, $bodyStart);
        if ($end === null) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.unterminated'));
        }

        [$command, $arguments] = self::splitCommand($end[0]);

        [$params, $payload] = self::parseFile($command, $arguments);

        return new self($command, $params, $payload);
    }

    /**
     * The OSC command verb, e.g. `File` or `Pop`.
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * The reassembled base64 payload (empty for control-only sequences).
     */
    public function payload(): string
    {
        return $this->payload;
    }

    public function hasPayload(): bool
    {
        return $this->payload !== '';
    }

    /**
     * The decoded PNG bytes.
     *
     * @throws MalformedGraphicsException for a data-less control sequence
     */
    public function png(): string
    {
        if ($this->payload === '') {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.no_payload'));
        }

        return $this->payload;
    }

    /** Declared width in character cells (`width=`). */
    public function cellsWidth(): ?int
    {
        return isset($this->params['width']) ? (int) $this->params['width'] : null;
    }

    /** Declared height in character cells (`height=`). */
    public function cellsHeight(): ?int
    {
        return isset($this->params['height']) ? (int) $this->params['height'] : null;
    }

    /**
     * The real pixel size carried by the PNG payload.
     *
     * @return array{int, int}
     */
    public function pixelDimensions(): array
    {
        $info = @getimagesizefromstring($this->png());
        if ($info === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.payload_not_image'));
        }

        return [$info[0], $info[1]];
    }

    public function toGdImage(): \GdImage
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException(Lang::t('graphics.gd.unavailable'));
        }

        $image = imagecreatefromstring($this->png());
        if (!$image instanceof \GdImage) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.payload_not_image'));
        }

        return $image;
    }

    /**
     * @return array{string, int}|null [body, offset after terminator]
     */
    private static function findTerminator(string $stream, int $from): ?array
    {
        $bel = strpos($stream, "\x07", $from);
        $st = strpos($stream, self::ST, $from);
        if ($bel === false && $st === false) {
            return null;
        }
        if ($st === false || ($bel !== false && $bel < $st)) {
            return [substr($stream, $from, $bel - $from), $bel + 1];
        }

        return [substr($stream, $from, $st - $from), $st + strlen(self::ST)];
    }

    /**
     * Split `File=k=v:…` into its verb (`File`) and remainder (`k=v:…`).
     *
     * @return array{string, string}
     */
    private static function splitCommand(string $body): array
    {
        $eq = strpos($body, '=');
        if ($eq === false) {
            return [$body, ''];
        }

        return [substr($body, 0, $eq), substr($body, $eq + 1)];
    }

    /**
     * Parse a `File=` sequence into key/value arguments and a decoded payload.
     *
     * A non-File verb (e.g. `Pop`) carries no arguments or data.
     *
     * @return array{array<string, string>, string}
     */
    private static function parseFile(string $command, string $arguments): array
    {
        if ($command !== 'File') {
            return [[], ''];
        }

        $colon = strpos($arguments, ':');
        if ($colon === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.missing_separator'));
        }

        $params = [];
        foreach (explode(';', substr($arguments, 0, $colon)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $params[substr($pair, 0, $eq)] = urldecode(substr($pair, $eq + 1));
        }

        $payload = self::decodeBase64(substr($arguments, $colon + 1));

        return [$params, $payload];
    }

    private static function decodeBase64(string $base64): string
    {
        if ($base64 === '') {
            return '';
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.iterm2.invalid_base64'));
        }

        return $decoded;
    }
}
