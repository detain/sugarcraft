<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

/**
 * Pull-agnostic section-splitting state machine for the E736 7.3 streaming
 * family ({@see \SugarCraft\Shine\Renderer::stream()} and
 * {@see \SugarCraft\Shine\Writer}, both through {@see SectionStream}).
 *
 * Feed bytes with {@see push()}; it answers every top-level section closed
 * by the assembled bytes so far. {@see finish()} declares end-of-document:
 * the trailing partial line is scanned and everything not yet returned (if
 * any content) is returned last.
 *
 * The state depends only on the assembled bytes, never on where a chunk
 * ended — which is what makes chunk-split invariance a structural property
 * rather than a hopeful one. Boundaries are a column-0 ATX heading outside
 * fenced and raw-HTML blocks that is either
 *  - preceded by a blank line, and not directly following a blockquote,
 *    list-item, indented-code, or table row (the defensive refusals
 *    published by stream()), or
 *  - on the line straight after a closing code fence (audit 15b-31: a reply
 *    that puts its headings right under its code blocks had no boundary at
 *    all, so a long one re-rendered whole on every frame).
 *
 * A boundary here is a PROPOSAL. The scanner reads lines, not CommonMark
 * block structure, so {@see SectionStream} has the parser confirm each one
 * before it renders a section on its own.
 *
 * Sections are returned without the blank-line filtering trivia: empty
 * sections are dropped here so both consumers see the same non-blank
 * sequence.
 */
final class SectionScanner
{
    private string $buffer = '';
    private string $section = '';
    private ?string $fenceChar = null;
    private int $fenceLen = 0;

    /** Text that ends the raw-HTML block being read (CommonMark HTML block types 1-5), compared case-insensitively. */
    private ?string $rawEnd = null;
    private ?string $lastNonBlank = null;
    private bool $sawBlank = false;

    /** The previous line closed a code fence. */
    private bool $afterFence = false;

    /**
     * Absorb one input chunk; answer every section the assembled bytes have
     * newly proven complete (non-blank, in order).
     *
     * @return list<string>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $closed = [];
        while (($nl = strpos($this->buffer, "\n")) !== false) {
            $line           = substr($this->buffer, 0, $nl);
            $this->buffer   = substr($this->buffer, $nl + 1);
            $done           = $this->scanLine($line);
            if ($done !== null && trim($done) !== '') {
                $closed[] = $done;
            }
        }

        return $closed;
    }

    /**
     * The section still being read: everything after the last boundary
     * {@see push()} reported, up to the last complete line. When push() has
     * just closed a section, this starts with the heading line that closed
     * it.
     */
    public function openSection(): string
    {
        return $this->section;
    }

    /**
     * End of document: scan the unterminated trailing line (if any) and
     * return everything not yet returned, or null when it holds no content.
     *
     * When that last line is itself a boundary heading, the section it
     * closes and the heading are returned together. Rendering two sections
     * as one is always lawful, and dropping the first was audit 15b-31:
     * `stream(["Intro\n\n# F"])` used to yield only the heading.
     */
    public function finish(): ?string
    {
        $closed = null;
        if ($this->buffer !== '') {
            $closed       = $this->scanLine($this->buffer);
            $this->buffer = '';
        }
        $rest = ($closed ?? '') . $this->section;
        $this->section = '';

        return trim($rest) === '' ? null : $rest;
    }

    /**
     * Feed one assembled line to the state machine. Returns the section text
     * closed by this line (the line itself becomes the head of a new
     * section), or null when no boundary was reached.
     */
    private function scanLine(string $line): ?string
    {
        $open = $line . "\n";
        $afterFence = $this->afterFence;
        $this->afterFence = false;

        if ($this->fenceChar !== null) {
            $this->section .= $open;
            if (preg_match('/^ {0,3}' . preg_quote($this->fenceChar, '/') . '{' . $this->fenceLen . ',}[ \t]*$/', $line) === 1) {
                $this->fenceChar  = null;
                $this->fenceLen   = 0;
                $this->afterFence = true;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if ($this->rawEnd !== null) {
            $this->section .= $open;
            if (stripos($line, $this->rawEnd) !== false) {
                $this->rawEnd = null;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if (trim($line) === '') {
            $this->section .= $open;
            $this->sawBlank = true;
            return null;
        }

        $isHeading = preg_match('/^#{1,6}([ \t]|$)/', $line) === 1;
        $isHeadingBoundary = $isHeading && (
            $afterFence
            || (
                $this->sawBlank
                && (
                    $this->lastNonBlank === null
                    || !(
                        preg_match('/^ {0,3}>/', $this->lastNonBlank) === 1
                        || preg_match('/^ {0,3}([-*+]|\d{1,9}[.)])([ \t]|$)/', $this->lastNonBlank) === 1
                        || preg_match('/^(?: {4}|\t)/', $this->lastNonBlank) === 1
                        || str_starts_with(ltrim($this->lastNonBlank), '|')
                    )
                )
            )
        );
        if ($isHeadingBoundary) {
            $closed           = $this->section;
            $this->section    = $open;
            $this->sawBlank   = false;
            $this->lastNonBlank = null;
            return $closed;
        }

        // A backtick fence's info string cannot contain a backtick: "```a`b"
        // is a paragraph holding a code span, and reading it as an opener
        // would put every later fence line out of step with the parser.
        if (preg_match('/^ {0,3}(`{3,})[^`]*$|^ {0,3}(~{3,})/', $line, $fence) === 1) {
            $run = ($fence[1] ?? '') !== '' ? $fence[1] : $fence[2];
            $this->fenceChar = $run[0];
            $this->fenceLen  = strlen($run);
        } elseif (($end = self::rawHtmlEnd($line)) !== null) {
            // Self-contained on one line: the end marker follows the start.
            $this->rawEnd = stripos($line, $end, strpos($line, '<') + 1) === false ? $end : null;
        }
        $this->section    .= $open;
        $this->lastNonBlank = $line;
        $this->sawBlank     = false;
        return null;
    }

    /**
     * The end marker of a CommonMark HTML block of types 1-5 that $line
     * starts, or null. These are the HTML blocks a blank line does not end
     * (types 6 and 7 do, and a boundary needs one), so a heading inside one
     * is not a heading.
     */
    private static function rawHtmlEnd(string $line): ?string
    {
        if (preg_match('/^ {0,3}<(script|pre|style|textarea)\b/i', $line, $tag) === 1) {
            return '</' . strtolower($tag[1]) . '>';
        }

        return match (true) {
            preg_match('/^ {0,3}<!--/', $line) === 1          => '-->',
            preg_match('/^ {0,3}<\?/', $line) === 1           => '?>',
            preg_match('/^ {0,3}<!\[CDATA\[/', $line) === 1   => ']]>',
            preg_match('/^ {0,3}<![A-Za-z]/', $line) === 1    => '>',
            default                                           => null,
        };
    }
}
