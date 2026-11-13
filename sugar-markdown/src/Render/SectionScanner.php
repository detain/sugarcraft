<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Render;

/**
 * Pull-agnostic section-splitting state machine for the streaming
 * family ({@see \SugarCraft\Markdown\Renderer::stream()} and
 * {@see \SugarCraft\Markdown\Writer}, both through {@see SectionStream}).
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
 *    indented-code, or table row (the defensive refusals published by
 *    stream()), or
 *  - on the line straight after a closing code fence — an early audit
 *    caught a reply that puts its headings right under its code blocks
 *    having no boundary at all, so a long one re-rendered whole on every
 *    frame.
 *
 * A heading after a list item is a boundary: a column-0 heading behind a
 * blank line cannot continue an item (its content is indented past the
 * marker, and a blank line ends lazy continuation), and the item's last
 * line is no different from a continuation line. An earlier revision of
 * this scanner refused the boundary anyway; that refusal never held.
 * Refusing the post-list heading left a reply whose sections each end in
 * a list as one open tail, re-rendered whole on every frame.
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
    /**
     * Hard ceiling on what one {@see push()} keeps live: the open section
     * plus the unconsumed buffer tail each stay at roughly this size, and
     * everything beyond it leaves as provisional sections the consumer's
     * parser confirms or re-joins. Without it, a newline-less multi-MB
     * chunk grows the buffer until the process dies (SM-S03).
     */
    public const MAX_SECTION_BYTES = 1048576;

    private string $buffer = '';

    /**
     * Bytes at the head of {@see $buffer} already split into lines. Scanning
     * resumes here instead of re-slicing the buffer on every line, so one
     * large push costs linear — not quadratic — in its size (M3).
     */
    private int $consumed = 0;

    /**
     * The open section as a line list, never one grown string: after a
     * `clone`, an in-place `.=` on either side would copy the shared bytes
     * whole (PHP separates refcount>1 strings on write), which made the
     * per-frame clone preview ladder quadratic (SM-S15). Appending a line
     * here touches only that line; the section is joined once, when some
     * consumer asks for it.
     *
     * @var list<string>
     */
    private array $sectionLines = [];

    /** Bytes held by {@see $sectionLines}, kept so the cap test costs no join. */
    private int $sectionBytes = 0;

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
        while (($nl = strpos($this->buffer, "\n", $this->consumed)) !== false) {
            $line           = substr($this->buffer, $this->consumed, $nl - $this->consumed);
            $this->consumed = $nl + 1;
            $done           = $this->scanLine($line);
            if ($done !== null && trim($done) !== '') {
                $closed[] = $done;
            }
            if ($this->sectionBytes > self::MAX_SECTION_BYTES) {
                // SM-S03: the open section blew the cap without proposing a
                // boundary. Flush it provisionally at the line edge — the
                // consumer's parser confirms or re-joins the cut.
                $closed[]            = $this->joinSection();
                $this->sectionLines  = [];
                $this->sectionBytes  = 0;
            }
        }

        $length = strlen($this->buffer);
        $live   = $length - $this->consumed;
        while ($live > self::MAX_SECTION_BYTES) {
            // SM-S03: no line has completed under the tail, so the buffer
            // itself is the runaway. Cut a provisional section mid-line:
            // such a cut can never parse as a heading boundary, so the
            // consumer's confirm() always re-joins it — the scanner simply
            // stops holding bytes it may not hold.
            $closed[]         = substr($this->buffer, $this->consumed, self::MAX_SECTION_BYTES);
            $this->consumed  += self::MAX_SECTION_BYTES;
            $live            -= self::MAX_SECTION_BYTES;
        }
        if ($this->consumed >= $length) {
            $this->buffer   = '';
            $this->consumed = 0;
        } elseif ($this->consumed >= 8192 && $this->consumed * 2 >= $length) {
            // Drop the read head once it outweighs the live tail: the buffer
            // then stays proportional to unconsumed input.
            $this->buffer   = substr($this->buffer, $this->consumed);
            $this->consumed = 0;
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
        return $this->joinSection();
    }

    /** The open section's bytes, joined once for whoever asks (SM-S15). */
    private function joinSection(): string
    {
        return \count($this->sectionLines) === 1 ? $this->sectionLines[0] : implode('', $this->sectionLines);
    }

    /**
     * End of document: scan the unterminated trailing line (if any) and
     * return everything not yet returned, or null when it holds no content.
     *
     * When that last line is itself a boundary heading, the section it
     * closes and the heading are returned together. Rendering two sections
     * as one is always lawful, and dropping the first was an early-audit bug:
     * `stream(["Intro\n\n# F"])` used to yield only the heading.
     */
    public function finish(): ?string
    {
        $closed = null;
        if ($this->consumed < strlen($this->buffer)) {
            $closed = $this->scanLine(substr($this->buffer, $this->consumed));
        }
        $this->buffer   = '';
        $this->consumed = 0;
        $rest = ($closed ?? '') . $this->joinSection();
        $this->sectionLines = [];
        $this->sectionBytes = 0;

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
            $this->sectionLines[] = $open;
        $this->sectionBytes  += strlen($open);
            if (preg_match('/^ {0,3}' . preg_quote($this->fenceChar, '/') . '{' . $this->fenceLen . ',}[ \t]*$/', $line) === 1) {
                $this->fenceChar  = null;
                $this->fenceLen   = 0;
                $this->afterFence = true;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if ($this->rawEnd !== null) {
            $this->sectionLines[] = $open;
        $this->sectionBytes  += strlen($open);
            if (stripos($line, $this->rawEnd) !== false) {
                $this->rawEnd = null;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if (trim($line) === '') {
            $this->sectionLines[] = $open;
        $this->sectionBytes  += strlen($open);
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
                        || preg_match('/^(?: {4}|\t)/', $this->lastNonBlank) === 1
                        || str_starts_with(ltrim($this->lastNonBlank), '|')
                    )
                )
            )
        );
        if ($isHeadingBoundary) {
            $closed             = $this->joinSection();
            $this->sectionLines = [$open];
            $this->sectionBytes = strlen($open);
            $this->sawBlank     = false;
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
        $this->sectionLines[] = $open;
        $this->sectionBytes  += strlen($open);
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
