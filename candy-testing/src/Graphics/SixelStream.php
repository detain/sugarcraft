<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use SugarCraft\Testing\Lang;

/**
 * Byte-level decoder for the Sixel graphics protocol.
 *
 * Independent of candy-mosaic: it reads the exact wire form that
 * `SugarCraft\Core\Util\Ansi::sixel*` emits — a single DCS string carrying a
 * palette (`#idx;2;R;G;B`, percent), sixel bands of `?`..`~` column bytes, RLE
 * runs (`!count char`), graphics CR (`$`) and NL (`-`), terminated by ST.
 *
 * Parsing is strict and fail-fast: an unterminated string, a bad introducer, a
 * colour used before it is defined, or a run that overflows the declared raster
 * all raise {@see MalformedGraphicsException} rather than guessing.
 *
 * Mirrors charmbracelet/candy-mosaic SixelRenderer (inverse).
 */
final class SixelStream
{
    private const ESC = "\x1b";

    private const DCS = "\x1bP";

    private const ST = "\x1b\\";

    /** Lowest + highest Sixel data character (`?` = 0 bits … `~` = all six). */
    private const DATA_MIN = 0x3F;

    private const DATA_MAX = 0x7E;

    /** @var array<int, array{int, int, int}> palette index => RGB */
    private array $palette = [];

    /** @var list<list<array{int, int, int}|null>> row-major raster */
    private array $raster;

    private int $cursor = 0;

    private int $length;

    private int $column = 0;

    private int $band = 0;

    private ?int $color = null;

    private function __construct(
        private readonly int $pixelWidth,
        private readonly int $pixelHeight,
    ) {
        $this->raster = [];
        for ($y = 0; $y < $pixelHeight; $y++) {
            $this->raster[] = array_fill(0, $pixelWidth, null);
        }
    }

    /**
     * Decode a Sixel byte stream (which may be embedded in surrounding text).
     */
    public static function decode(string $stream): self
    {
        $start = strpos($stream, self::DCS);
        if ($start === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.missing_dcs_header'));
        }

        $bodyStart = $start + strlen(self::DCS);
        $end = strpos($stream, self::ST, $bodyStart);
        if ($end === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.unterminated'));
        }

        $body = substr($stream, $bodyStart, $end - $bodyStart);

        $decoder = new self(...self::parseHeader($body));
        $decoder->length = strlen($body);
        $decoder->consume($body);

        return $decoder;
    }

    public function width(): int
    {
        return $this->pixelWidth;
    }

    public function height(): int
    {
        return $this->pixelHeight;
    }

    /**
     * @return array<int, array{int, int, int}> palette index => RGB triple
     */
    public function palette(): array
    {
        return $this->palette;
    }

    public function grid(): PixelGrid
    {
        return PixelGrid::fromRgbGrid($this->raster);
    }

    /**
     * @return array{int, int, int}|null
     */
    public function pixel(int $x, int $y): ?array
    {
        return $this->raster[$y][$x] ?? null;
    }

    public function toGdImage(): \GdImage
    {
        return $this->grid()->toGdImage();
    }

    /**
     * Read the DCS parameter block and the `"` raster declaration.
     *
     * @return array{int, int} pixel [width, height]
     */
    private static function parseHeader(string $body): array
    {
        $introducer = strpos($body, 'q');
        if ($introducer === false) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.missing_introducer_q'));
        }

        $rest = ltrim(substr($body, $introducer + 1));
        if (!str_starts_with($rest, '"')) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.missing_raster_declaration'));
        }

        $numbers = [];
        $offset = 1;
        $size = strlen($rest);
        while ($offset < $size && count($numbers) < 4) {
            if ($rest[$offset] === ';') {
                $offset++;
                continue;
            }
            if (!ctype_digit($rest[$offset])) {
                break;
            }
            $digits = '';
            while ($offset < $size && ctype_digit($rest[$offset])) {
                $digits .= $rest[$offset];
                $offset++;
            }
            $numbers[] = (int) $digits;
        }

        [$width, $height] = self::rasterFrom($numbers);

        return [$width, $height];
    }

    /**
     * `"Pan;Pad;PH;PV` (four values) or the terse `"W;H` (two values).
     *
     * @param list<int> $numbers
     * @return array{int, int}
     */
    private static function rasterFrom(array $numbers): array
    {
        $width = match (count($numbers)) {
            4 => $numbers[2],
            2 => $numbers[0],
            default => 0,
        };
        $height = match (count($numbers)) {
            4 => $numbers[3],
            2 => $numbers[1],
            default => 0,
        };

        if ($width <= 0 || $height <= 0) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.bad_raster'));
        }

        return [$width, $height];
    }

    private function consume(string $body): void
    {
        $this->cursor = strpos($body, '"') === false ? 0 : $this->skipRaster(strlen($body), $body);

        while ($this->cursor < $this->length) {
            $char = $body[$this->cursor];
            match ($char) {
                '#' => $this->readColor($body),
                '!' => $this->readRun($body),
                '$' => $this->carriageReturn(),
                '-' => $this->nextBand(),
                default => $this->readSixel($char),
            };
        }
    }

    /**
     * Position the cursor just past the `"…` raster declaration so the data loop
     * begins at the first palette/color command.
     */
    private function skipRaster(int $size, string $body): int
    {
        $quote = strpos($body, '"');
        $i = $quote + 1;
        while ($i < $size && (ctype_digit($body[$i]) || $body[$i] === ';')) {
            $i++;
        }

        return $i;
    }

    private function readColor(string $body): void
    {
        $this->cursor++; // consume '#'
        $numbers = $this->readNumberList($body);
        $index = $numbers[0] ?? null;
        if ($index === null) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.bad_color_introducer'));
        }

        $arity = count($numbers);
        if ($arity >= 5) {
            // `#idx;model;r;g;b` — models 1 and 2 carry RGB as 0..100 percent.
            [$model, $red, $green, $blue] = [$numbers[1], $numbers[2], $numbers[3], $numbers[4]];
            if ($model !== 1 && $model !== 2) {
                throw new MalformedGraphicsException(Lang::t('graphics.sixel.unknown_color_space', ['model' => $model]));
            }
            foreach ([$red, $green, $blue] as $component) {
                if ($component > 100) {
                    throw new MalformedGraphicsException(Lang::t('graphics.sixel.color_component_out_of_range', ['value' => $component]));
                }
            }
            $this->palette[$index] = [
                self::percentToByte($red),
                self::percentToByte($green),
                self::percentToByte($blue),
            ];
        } elseif ($arity !== 1) {
            // A bare `#idx` selects an existing register; two-to-four numbers are a
            // truncated/invalid colour definition and must not be guessed at.
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.bad_color_introducer'));
        }

        if (!isset($this->palette[$index])) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.undefined_color', ['index' => $index]));
        }

        $this->color = $index;
    }

    private function readRun(string $body): void
    {
        $this->cursor++; // consume '!'
        $count = $this->readUnsigned($body);
        if ($count === null || $count <= 0) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.rle_missing_count'));
        }

        // Canonical RLE is `!count<char>` with no separator; tolerate a stray `;`
        // or run of whitespace before the data byte (`!count char`), which some
        // encoders emit. None of these characters are data bytes, so the skip is
        // unambiguous and always terminates.
        while (true) {
            $peek = $body[$this->cursor] ?? '';
            if ($peek !== ';' && $peek !== ' ' && $peek !== "\t") {
                break;
            }
            $this->cursor++;
        }

        $char = $body[$this->cursor] ?? '';
        if ($char === '' || !$this->isDataChar($char)) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.rle_missing_data'));
        }
        $this->cursor++;

        $bits = ord($char) - self::DATA_MIN;
        for ($n = 0; $n < $count; $n++) {
            $this->putColumn($bits);
        }
    }

    private function readSixel(string $char): void
    {
        if (!$this->isDataChar($char)) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.unexpected_char', ['char' => $char]));
        }
        $this->cursor++;
        $this->putColumn(ord($char) - self::DATA_MIN);
    }

    private function carriageReturn(): void
    {
        $this->cursor++;
        $this->column = 0;
    }

    private function nextBand(): void
    {
        $this->cursor++;
        $this->band++;
        $this->column = 0;
    }

    /**
     * Paint one sixel column: bit 0 is the top row of the current 6-row band.
     */
    private function putColumn(int $bits): void
    {
        if ($this->color === null) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.color_not_selected'));
        }

        if ($this->column >= $this->pixelWidth) {
            throw new MalformedGraphicsException(Lang::t('graphics.sixel.rle_oversize', ['width' => $this->pixelWidth]));
        }

        $rgb = $this->palette[$this->color];
        $top = $this->band * 6;
        for ($offset = 0; $offset < 6; $offset++) {
            if (($bits & (1 << $offset)) === 0) {
                continue;
            }
            $row = $top + $offset;
            if ($row >= $this->pixelHeight) {
                throw new MalformedGraphicsException(Lang::t('graphics.sixel.raster_overflow', ['height' => $this->pixelHeight]));
            }
            $this->raster[$row][$this->column] = $rgb;
        }

        $this->column++;
    }

    private function isDataChar(string $char): bool
    {
        $code = ord($char);

        return $code >= self::DATA_MIN && $code <= self::DATA_MAX;
    }

    /**
     * @return list<int> every `;`-separated integer in the group
     */
    private function readNumberList(string $body): array
    {
        $numbers = [];
        while ($this->cursor < $this->length) {
            $value = $this->readUnsigned($body);
            if ($value === null) {
                break;
            }
            $numbers[] = $value;
            if (($body[$this->cursor] ?? '') === ';') {
                $this->cursor++;
                continue;
            }
            break;
        }

        return $numbers;
    }

    private function readUnsigned(string $body): ?int
    {
        $digits = '';
        while ($this->cursor < $this->length && ctype_digit($body[$this->cursor])) {
            $digits .= $body[$this->cursor];
            $this->cursor++;
        }

        return $digits === '' ? null : (int) $digits;
    }

    /**
     * Map a 0..100 percent channel to a 0..255 byte. The caller (`readColor`)
     * has already bounded the value, so this is a pure formatter.
     */
    private static function percentToByte(int $percent): int
    {
        return (int) round($percent * 255 / 100);
    }
}
