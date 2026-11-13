<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect;

/**
 * One ANSI escape sequence — its raw bytes paired with a decoded label
 * (e.g. `"SGR foreground red"`). The label is what makes CandyAnsiInspect
 * useful as a debugging tool: you can pipe styled output in and see
 * exactly which sequence produced what effect.
 *
 * Mirrors charmbracelet/sequin's annotated escape-sequence entries —
 * the raw column is what the wire carried, the label is the annotation.
 */
final class SequenceSegment extends Segment
{
    /**
     * The raw bytes are stored verbatim; the report renders them through
     * {@see printable()}, which tokenizes every control byte.
     *
     * @param ?int $offset absolute byte offset of this sequence's first input
     *                     byte in the parsed stream (null = hand-constructed,
     *                     untracked)
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $label,
        public readonly ?int $offset = null,
    ) {}

    public function raw(): string { return $this->bytes; }

    public function byteOffset(): ?int { return $this->offset; }

    public function describe(): string
    {
        $printable = self::printable($this->bytes);
        return $printable . '  ' . $this->label;
    }

    /**
     * Render bytes with control bytes replaced by visible tokens: `\x1b`
     * becomes `ESC`, every C0/DEL/C1 byte becomes its named token (`CR`,
     * `BEL`, `RI`, …), and printable ASCII plus well-formed UTF-8 runes
     * survive verbatim.
     *
     * This is the single choke point of the report's raw column and it runs
     * the same tokenizer as the label column (Inspector::sanitizeLabelBytes):
     * captured bytes must not be able to forge segment lines or mutate the
     * viewing terminal — a raw CR/LF would rewrite the report's own line
     * framing, a raw 0x9B would re-arm a CSI parser on an 8-bit-capable
     * terminal, and every BEL-normalised OSC raw would ring on render
     * (finding 002). `raw()` stays untouched for library consumers who need
     * the verbatim bytes; the tokenization is a display-layer contract, and
     * README's fidelity list discloses the raw-column spelling it imposes.
     */
    public static function printable(string $bytes): string
    {
        return Inspector::sanitizeLabelBytes($bytes);
    }
}
