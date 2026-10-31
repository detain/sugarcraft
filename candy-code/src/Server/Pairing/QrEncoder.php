<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Pairing;

/**
 * A minimal real QR encoder for pairing codes: byte mode, error correction
 * level M, versions 1-10 (payloads up to 213 bytes — a sign-in URL with its
 * one-time code fits long before that, and anything longer is refused rather
 * than silently truncated).
 *
 * The plan's R13 ruling forbids a third-party QR dependency, so this encoder
 * implements ISO/IEC 18004 directly: Reed-Solomon over GF(256) (primitive
 * polynomial 0x11D), the 8 evaluation masks with the standard penalty rules,
 * and the BCH(15,5)/BCH(18,6) format and version information. Its test pins
 * each produced matrix against a reference fixture generated once with a
 * known independent tool, so the encoder is never tested against itself.
 *
 * {@see encode()} answers the grid as a list of rows, each a list of 0|1
 * module values (1 = dark), without any quiet zone; {@see QrTerminal} pads.
 */
final class QrEncoder
{
    /** Highest version this encoder produces; drives the refusal above. */
    public const MAX_VERSION = 10;

    /**
     * ECC-M byte-mode payload capacity of the whole supported range:
     * the byte capacity of version 10 with a 16-bit count indicator.
     */
    public const MAX_PAYLOAD_BYTES = 213;

    /**
     * ECC-M block structure per version (1-10): groups of
     * [blocks, total codewords per block, data codewords per block].
     * Transcribed from ISO/IEC 18004 Table 9 (the same table the reference
     * fixtures were built with; {@see QrEncoderTest} re-derives capacity from
     * it, so a transcription slip goes red on the boundary vectors).
     *
     * @var array<int, list<array{int, int, int}>>
     */
    private const BLOCKS = [
        1 => [[1, 26, 16]],
        2 => [[1, 44, 28]],
        3 => [[1, 70, 44]],
        4 => [[2, 50, 32]],
        5 => [[2, 67, 43]],
        6 => [[4, 43, 27]],
        7 => [[4, 49, 31]],
        8 => [[2, 60, 38], [2, 61, 39]],
        9 => [[3, 58, 36], [2, 59, 37]],
        10 => [[4, 69, 43], [1, 70, 44]],
    ];

    /**
     * Alignment-pattern centre coordinates per version (ISO 18004 Table E.1
     * truncated to versions 1-10; version 1 has none).
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** XOR mask applied to the raw BCH format bits (ISO 18004 8.9). */
    private const FORMAT_MASK = 0b101010000010010;

    /** Generator of the BCH(15,5) format polynomial (10100110111). */
    private const FORMAT_GENERATOR = 0b10100110111;

    /** Generator of the BCH(18,6) version polynomial (x^12+x^11+x^10+x^9+x^8+x^5+x^2+1). */
    private const VERSION_GENERATOR = 0b1111100100101;

    /** GF(256) primitive polynomial x^8 + x^4 + x^3 + x^2 + 1. */
    private const GF_PRIMITIVE = 0x11D;

    /** Padding codewords that fill the data region after the terminator. */
    private const PAD_A = 0xEC;

    private const PAD_B = 0x11;

    /** @var list<int>|null */
    private static ?array $gfExp = null;

    /** @var list<int>|null */
    private static ?array $gfLog = null;

    /** @var array<int, list<int>> */
    private static array $generatorPolys = [];

    private function __construct()
    {
    }

    /**
     * Encode a payload as a module grid, auto-selecting the smallest fitting
     * version and the least-penalised mask (ties keep the lower mask number).
     *
     * @param string   $payload   bytes to encode; 1..213 of them
     * @param int|null $forcedMask 0-7 to pin the mask (tests/reproducers);
     *                             null selects the least-penalised one
     * @return array{version: int, size: int, mask: int, modules: list<list<int>>}
     */
    public static function encode(string $payload, ?int $forcedMask = null): array
    {
        $length = \strlen($payload);
        if ($length === 0) {
            throw new \InvalidArgumentException('QrEncoder: refusing to encode an empty payload.');
        }
        if ($forcedMask !== null && ($forcedMask < 0 || $forcedMask > 7)) {
            throw new \InvalidArgumentException('QrEncoder: mask must be 0..7, got ' . $forcedMask . '.');
        }
        $version = self::versionFor($length);
        $codewords = self::buildCodewords($payload, $version);
        [$grid, $size, $reserved] = self::buildFunctionPattern($version);
        $grid = self::placeData($codewords, $grid, $reserved, $size);
        $mask = $forcedMask ?? self::bestMask($grid, $reserved, $size);
        $grid = self::applyMask($grid, $reserved, $size, $mask);
        self::writeFormat($grid, $size, $mask);
        if ($version >= 7) {
            self::writeVersion($grid, $size, $version);
        }

        return ['version' => $version, 'size' => $size, 'mask' => $mask, 'modules' => $grid];
    }

    /** Smallest supported version whose byte capacity holds $payloadBytes. */
    public static function versionFor(int $payloadBytes): int
    {
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            if ($payloadBytes <= self::capacity($v)) {
                return $v;
            }
        }

        throw new \InvalidArgumentException(
            'QrEncoder: a ' . $payloadBytes . ' byte payload exceeds the ' . self::MAX_PAYLOAD_BYTES
                . ' byte capacity of version ' . self::MAX_VERSION . ' at error level M.',
        );
    }

    /** Maximum byte-mode payload for a supported version at ECC level M. */
    public static function capacity(int $version): int
    {
        $dataCodewords = self::dataCodewords($version);
        $countBits = $version >= 10 ? 16 : 8;

        return \intdiv($dataCodewords * 8 - 4 - $countBits, 8);
    }

    /**
     * The 15-bit format information value for ECC level M and a given mask:
     * the BCH(15,5) codeword XORed with the standard mask pattern.
     */
    public static function formatBits(int $mask): int
    {
        if ($mask < 0 || $mask > 7) {
            throw new \InvalidArgumentException('QrEncoder: mask must be 0..7, got ' . $mask . '.');
        }

        return self::bchFormat($mask) ^ self::FORMAT_MASK;
    }

    /** The 18-bit version information value for versions 7-10. */
    public static function versionBits(int $version): int
    {
        if ($version < 7 || $version > self::MAX_VERSION) {
            throw new \InvalidArgumentException(
                'QrEncoder: version information exists for versions 7 and up, not ' . $version . '.',
            );
        }
        $rem = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($rem & (1 << $i)) !== 0) {
                $rem ^= self::VERSION_GENERATOR << ($i - 12);
            }
        }

        return ($version << 12) | $rem;
    }

    /** @return int total data codewords (ECC-M) for a supported version */
    public static function dataCodewords(int $version): int
    {
        $groups = self::BLOCKS[$version] ?? throw new \InvalidArgumentException(
            'QrEncoder: version ' . $version . ' is outside the supported 1..' . self::MAX_VERSION . ' range.',
        );
        $data = 0;
        foreach ($groups as [$blocks, , $perBlock]) {
            $data += $blocks * $perBlock;
        }

        return $data;
    }

    /**
     * Data bit stream (mode, count, payload, terminator, pad) split into RS
     * blocks, then the interleaved codeword sequence: all data codewords
     * round-robin, then all ECC codewords round-robin.
     *
     * @return list<int> codeword bytes in placement order
     */
    private static function buildCodewords(string $payload, int $version): array
    {
        $dataCount = self::dataCodewords($version);
        $countBits = $version >= 10 ? 16 : 8;

        $bits = [];
        self::pushBits($bits, 0b0100, 4); // byte mode
        self::pushBits($bits, \strlen($payload), $countBits);
        foreach (\str_split($payload) as $byte) {
            self::pushBits($bits, \ord($byte), 8);
        }
        // ISO 18004 uses "capacity" for the DATA-codeword room only (7.4.9/7.4.10);
        // the error correction codewords are appended later, never padded here.
        $dataCapacity = $dataCount * 8;
        self::pushBits($bits, 0, \min(4, $dataCapacity - \count($bits))); // terminator
        // Byte align. A full 00000000 filler codeword is also appended when the
        // stream is already aligned — the reference-tool (segno 1.6.6) stream
        // convention the committed fixtures pin. The result stays a fully valid
        // RS codeword sequence (scanners read the count field, never the
        // filler); ISO 7.4.10 would omit this byte for aligned streams.
        self::pushBits($bits, 0, 8 - (\count($bits) % 8));
        $pad = 0;
        while (\count($bits) < $dataCapacity) {
            self::pushBits($bits, $pad % 2 === 0 ? self::PAD_A : self::PAD_B, 8);
            $pad++;
        }

        $codewords = self::bitsToBytes($bits);
        $blocksData = [];
        $blocksEcc = [];
        $offset = 0;
        foreach (self::BLOCKS[$version] as [$blocks, $total, $perBlock]) {
            for ($b = 0; $b < $blocks; $b++) {
                $data = \array_slice($codewords, $offset, $perBlock);
                $offset += $perBlock;
                $blocksData[] = $data;
                $blocksEcc[] = self::reedSolomon($data, $total - $perBlock);
            }
        }

        $interleaved = [];
        $maxData = 0;
        foreach ($blocksData as $block) {
            $maxData = \max($maxData, \count($block));
        }
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocksData as $block) {
                if (isset($block[$i])) {
                    $interleaved[] = $block[$i];
                }
            }
        }
        $maxEcc = \count($blocksEcc[0]);
        for ($i = 0; $i < $maxEcc; $i++) {
            foreach ($blocksEcc as $ecc) {
                if (isset($ecc[$i])) {
                    $interleaved[] = $ecc[$i];
                }
            }
        }

        return $interleaved;
    }

    /** @param list<int> $bits */
    private static function pushBits(array &$bits, int $value, int $count): void
    {
        for ($i = $count - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param list<int> $bits
     * @return list<int>
     */
    private static function bitsToBytes(array $bits): array
    {
        $bytes = [];
        for ($i = 0, $n = \count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $bytes[] = $byte;
        }

        return $bytes;
    }

    /**
     * Reed-Solomon ECC codewords for one block (remainder of the message
     * polynomial divided by the generator of the requested degree).
     *
     * @param list<int> $data
     * @return list<int> most significant ECC byte first
     */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        $generator = \array_reverse(self::generatorPolynomial($eccCount)); // leading 1 first
        $remainder = \array_fill(0, $eccCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            \array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                $logFactor = self::gfLog()[$factor];
                for ($i = 0; $i < $eccCount; $i++) {
                    $coeff = $generator[$i + 1];
                    $remainder[$i] ^= $coeff === 0 ? 0 : self::gfExp()[(int) (($logFactor + self::gfLog()[$coeff]) % 255)];
                }
            }
        }

        return $remainder;
    }

    /** @return list<int> generator polynomial coefficients for degree $count */
    private static function generatorPolynomial(int $count): array
    {
        if (isset(self::$generatorPolys[$count])) {
            return self::$generatorPolys[$count];
        }
        $poly = [1];
        for ($i = 0; $i < $count; $i++) {
            $next = \array_fill(0, \count($poly) + 1, 0);
            $alpha = self::gfExp()[$i];
            foreach ($poly as $j => $coeff) {
                $next[$j] ^= self::gfMultiply($coeff, $alpha);
                $next[$j + 1] ^= $coeff;
            }
            $poly = $next;
        }

        return self::$generatorPolys[$count] = $poly;
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::gfExp()[(self::gfLog()[$a] + self::gfLog()[$b]) % 255];
    }

    /** @return list<int> */
    private static function gfExp(): array
    {
        if (self::$gfExp === null) {
            $exp = [];
            $x = 1;
            for ($i = 0; $i < 256; $i++) {
                $exp[$i] = $x;
                $x <<= 1;
                if (($x & 0x100) !== 0) {
                    $x ^= self::GF_PRIMITIVE;
                }
            }
            self::$gfExp = $exp;
            $log = [];
            for ($i = 0; $i < 255; $i++) {
                $log[$exp[$i]] = $i;
            }
            self::$gfLog = $log;
        }

        return self::$gfExp;
    }

    /** @return list<int> */
    private static function gfLog(): array
    {
        self::gfExp();

        return self::$gfLog ?? [];
    }

    private static function bchFormat(int $mask): int
    {
        // ECC level M is the two-bit indicator 00, so the 5 data bits are
        // just the 3 mask bits.
        $rem = $mask << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem & (1 << $i)) !== 0) {
                $rem ^= self::FORMAT_GENERATOR << ($i - 10);
            }
        }

        return ($mask << 10) | $rem;
    }

    /**
     * The module grid of finder patterns, separators, timing patterns,
     * alignment patterns, the dark module and the reserved format/version
     * areas, as a placement map: true = not usable for data.
     *
     * @return array{list<list<int>>, int, list<list<bool>>} [grid, size, placement map]
     */
    private static function buildFunctionPattern(int $version): array
    {
        $size = 17 + 4 * $version;
        $reserved = [];
        for ($r = 0; $r < $size; $r++) {
            $reserved[$r] = \array_fill(0, $size, false);
        }

        // Finder patterns with their separators at the three corners.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $row = $top + $r;
                    $col = $left + $c;
                    if ($row < 0 || $col < 0 || $row >= $size || $col >= $size) {
                        continue;
                    }
                    $reserved[$row][$col] = true;
                }
            }
        }

        // Timing patterns and the column/row 8 strips they belong to.
        for ($i = 0; $i < $size; $i++) {
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        // Alignment patterns, except where a finder already covers them.
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $rowCentre) {
            foreach ($centres as $colCentre) {
                if (($rowCentre === 6 && $colCentre === 6)
                    || ($rowCentre === 6 && $colCentre === $size - 7)
                    || ($rowCentre === $size - 7 && $colCentre === 6)
                ) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $reserved[$rowCentre + $r][$colCentre + $c] = true;
                    }
                }
            }
        }

        // Format information areas (including the always-dark module).
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Version information areas (versions 7+).
        if ($version >= 7) {
            for ($r = 0; $r < 6; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    $reserved[$r][$size - 11 + $c] = true;
                    $reserved[$size - 11 + $c][$r] = true;
                }
            }
        }

        $grid = [];
        for ($r = 0; $r < $size; $r++) {
            $grid[$r] = \array_fill(0, $size, 0);
        }
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $onEdge = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                    $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $grid[$top + $r][$left + $c] = ($onEdge || $inCore) ? 1 : 0;
                }
            }
        }
        for ($i = 8; $i < $size - 8; $i++) {
            $grid[6][$i] = $i % 2 === 0 ? 1 : 0;
            $grid[$i][6] = $i % 2 === 0 ? 1 : 0;
        }
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $rowCentre) {
            foreach ($centres as $colCentre) {
                if (($rowCentre === 6 && $colCentre === 6)
                    || ($rowCentre === 6 && $colCentre === $size - 7)
                    || ($rowCentre === $size - 7 && $colCentre === 6)
                ) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $ring = \max(\abs($r), \abs($c));
                        $grid[$rowCentre + $r][$colCentre + $c] = ($ring === 1) ? 0 : 1;
                    }
                }
            }
        }
        // The dark module is written by writeFormat() only: during mask
        // evaluation the symbol does not carry format information yet
        // (ISO 18004 7.8.3, and the reference implementation agrees).

        return [$grid, $size, $reserved];
    }

    /**
     * The interleaved codewords placed in the upward/downward two-column
     * zigzag, MSB first, remainder bits zero (they still take part in
     * masking, as the spec requires).
     *
     * @param list<int>        $codewords
     * @param list<list<int>>  $grid    function patterns already drawn
     * @param list<list<bool>> $reserved placement map
     */
    private static function placeData(array $codewords, array $grid, array $reserved, int $size): array
    {
        $bitList = [];
        foreach ($codewords as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bitList[] = ($byte >> $i) & 1;
            }
        }
        $totalModules = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c]) {
                    $totalModules++;
                }
            }
        }
        for ($i = \count($bitList), $n = $totalModules; $i < $n; $i++) {
            $bitList[] = 0;
        }

        $index = 0;
        $upward = true;
        $right = $size - 1;
        while ($right >= 1) {
            if ($right === 6) {
                $right--; // the vertical timing column never takes part in a pair
            }
            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;
                foreach ([$right, $right - 1] as $col) {
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $grid[$row][$col] = $bitList[$index++];
                }
            }
            $upward = !$upward;
            $right -= 2;
        }

        return $grid;
    }

    /**
     * @param list<list<int>>  $grid
     * @param list<list<bool>> $reserved
     * @return list<list<int>>
     */
    private static function applyMask(array $grid, array $reserved, int $size, int $mask): array
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                if (self::maskCondition($mask, $r, $c)) {
                    $grid[$r][$c] ^= 1;
                }
            }
        }

        return $grid;
    }

    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (\intdiv($row, 2) + \intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            7 => (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            default => throw new \InvalidArgumentException('QrEncoder: mask must be 0..7.'),
        };
    }

    /**
     * @param list<list<int>>    $grid
     * @param list<list<bool>>   $reserved
     */
    private static function bestMask(array $grid, array $reserved, int $size): int
    {
        $best = 0;
        $bestPenalty = \PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $penalty = self::penalty(self::applyMask($grid, $reserved, $size, $mask), $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $mask;
            }
        }

        return $best;
    }

    /**
     * The four ISO 18004 penalty rules: long runs, 2x2 blocks, finder-like
     * 1:1:3:1:1 sequences and dark-module ratio bands.
     *
     * @param list<list<int>> $grid
     */
    private static function penalty(array $grid, int $size): int
    {
        $penalty = 0;
        $dark = 0;

        for ($r = 0; $r < $size; $r++) {
            $runLength = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($grid[$r][$c] === $grid[$r][$c - 1]) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $runLength = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($grid[$r][$c] === $grid[$r - 1][$c]) {
                    $runLength++;
                } else {
                    if ($runLength >= 5) {
                        $penalty += 3 + ($runLength - 5);
                    }
                    $runLength = 1;
                }
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
        }

        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $value = $grid[$r][$c];
                if ($value === $grid[$r][$c + 1] && $value === $grid[$r + 1][$c] && $value === $grid[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        $needleA = [1, 0, 1, 1, 1, 0, 1];
        for ($line = 0; $line < $size; $line++) {
            $row = [];
            $col = [];
            for ($i = 0; $i < $size; $i++) {
                $row[] = $grid[$line][$i];
                $col[] = $grid[$i][$line];
            }
            $penalty += 40 * self::finderishRuns($row);
            $penalty += 40 * self::finderishRuns($col);
            $dark += \array_sum($row);
        }

        $percent = $dark * 100 / ($size * $size);
        $penalty += 10 * \floor(\abs($percent - 50) / 5);

        return (int) $penalty;
    }

    /**
     * Occurrences of the 1:1:3:1:1 finder-like sequence (7 modules) in one
     * line, each worth +40 when it is preceded or followed by a 4-module
     * light area. An area running off the symbol edge counts as light, and
     * an occurrence that does not score still shifts the scan by four (the
     * pattern overlaps itself there), matching the reference implementation.
     *
     * @param list<int> $line
     */
    private static function finderishRuns(array $line): int
    {
        $pattern = [1, 0, 1, 1, 1, 0, 1];
        $length = \count($line);
        $needle = \count($pattern);
        $count = 0;
        $idx = 0;
        while ($idx <= $length - $needle) {
            $hit = true;
            for ($j = 0; $j < $needle; $j++) {
                if ($line[$idx + $j] !== $pattern[$j]) {
                    $hit = false;
                    break;
                }
            }
            if (!$hit) {
                $idx++;
                continue;
            }
            $before = \array_slice($line, \max($idx - 4, 0), \min(4, $idx));
            $after = \array_slice($line, $idx + $needle, 4);
            if ($before === [] || \array_sum($before) === 0 || $after === [] || \array_sum($after) === 0) {
                $count++;
                $idx += $needle;
                continue;
            }
            $idx += 4;
        }

        return $count;
    }

    /**
     * @param list<list<int>> $grid
     */
    private static function writeFormat(array &$grid, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);
        $positions = [
            [0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8],
            [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0],
        ];
        foreach ($positions as $i => [$row, $col]) {
            $grid[$row][$col] = ($bits >> $i) & 1;
        }
        for ($i = 0; $i < 8; $i++) {
            $grid[8][$size - 1 - $i] = ($bits >> $i) & 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $grid[$size - 15 + $i][8] = ($bits >> $i) & 1;
        }
        $grid[$size - 8][8] = 1;
    }

    /**
     * @param list<list<int>> $grid
     */
    private static function writeVersion(array &$grid, int $size, int $version): void
    {
        // ISO 18004 Figure 27: the upper-right block is 6 rows x 3 columns —
        // bit (3i + j) lands at row i, column size-11+j; the lower-left block
        // is its transpose (row size-11+j, column i).
        $bits = self::versionBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $row = \intdiv($i, 3);
            $col = $size - 11 + ($i % 3);
            $grid[$row][$col] = $bit;
            $grid[$col][$row] = $bit;
        }
    }
}
