<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

/**
 * Two single-slot memos for the compositor's per-row tokenize: one for the
 * background, one for the (chromed) foreground.
 *
 * A modal re-composited every frame over the same screen re-split and
 * re-segmented the whole backdrop each time (rank 35). The split and the
 * segment lists are a pure function of the text, so the last result is kept
 * and reused while the text is byte-identical. Being pure, the memo is safe
 * to share between a veil and every clone it makes, which is why Veil carries
 * it through mutate() and rebuild() (including withoutSession(), so the
 * per-layer clones a stack pass makes still hit it).
 *
 * @internal
 */
final class SegmentMemo
{
    /** @var array<string, string> slot => text the held result was built from */
    private array $keys = [];

    /** @var array<string, array{lines: list<string>, segs: array<int, list<array{kind:string,bytes:string,width:int,id:string}>>, widths: list<int>, width: int}> */
    private array $values = [];

    /** @var array<string, int> slot => rebuilds (diagnostic for tests) */
    private array $misses = ['background' => 0, 'foreground' => 0];

    /**
     * Lines, per-row segment lists, per-row widths and the widest row of
     * $text, from the slot's memo when $text is what it last held.
     *
     * @param 'background'|'foreground' $slot
     * @return array{lines: list<string>, segs: array<int, list<array{kind:string,bytes:string,width:int,id:string}>>, widths: list<int>, width: int}
     */
    public function segment(string $slot, string $text): array
    {
        if (isset($this->keys[$slot]) && $this->keys[$slot] === $text) {
            return $this->values[$slot];
        }

        $lines  = Lines::split($text);
        $segs   = [];
        $widths = [];
        foreach ($lines as $r => $line) {
            $rowSegs    = MarkedFrame::segments($line);
            $segs[$r]   = $rowSegs;
            $widths[$r] = MarkedFrame::measureSegments($rowSegs);
        }

        $this->misses[$slot] = ($this->misses[$slot] ?? 0) + 1;
        $this->keys[$slot] = $text;

        return $this->values[$slot] = [
            'lines'  => $lines,
            'segs'   => $segs,
            'widths' => $widths,
            'width'  => $widths === [] ? 0 : \max($widths),
        ];
    }

    /** How many times a slot had to re-segment (tests pin the memo with it). */
    public function misses(string $slot): int
    {
        return $this->misses[$slot] ?? 0;
    }
}
