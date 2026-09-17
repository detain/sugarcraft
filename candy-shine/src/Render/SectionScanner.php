<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

/**
 * Pull-agnostic section-splitting state machine for the E736 7.3 streaming
 * family ({@see \SugarCraft\Shine\Renderer::stream()} and
 * {@see \SugarCraft\Shine\Writer}).
 *
 * Feed bytes with {@see push()}; it answers every top-level section closed
 * by the assembled bytes so far. {@see finish()} declares end-of-document:
 * the trailing partial line is scanned and the still-open section (if any
 * content) is returned last.
 *
 * The state depends only on the assembled bytes, never on where a chunk
 * ended — which is what makes chunk-split invariance a structural property
 * rather than a hopeful one. Boundaries are a column-0 ATX heading preceded
 * by a blank line, outside fenced and raw-HTML blocks, and not directly
 * following a blockquote, list-item, indented-code, or table row (the
 * defensive refusals published by stream()).
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
    private ?string $rawTag = null;
    private ?string $lastNonBlank = null;
    private bool $sawBlank = false;

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
     * End of document: scan the unterminated trailing line (if any) and
     * return the last open section, or null when it holds no content.
     */
    public function finish(): ?string
    {
        if ($this->buffer !== '') {
            $this->scanLine($this->buffer);
            $this->buffer = '';
        }

        return trim($this->section) === '' ? null : $this->section;
    }

    /**
     * Feed one assembled line to the state machine. Returns the section text
     * closed by this line (the line itself becomes the head of a new
     * section), or null when no boundary was reached.
     */
    private function scanLine(string $line): ?string
    {
        $open = $line . "\n";

        if ($this->fenceChar !== null) {
            $this->section .= $open;
            if (preg_match('/^ {0,3}' . preg_quote($this->fenceChar, '/') . '{' . $this->fenceLen . ',}[ \t]*$/', $line) === 1) {
                $this->fenceChar = null;
                $this->fenceLen  = 0;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if ($this->rawTag !== null) {
            $this->section .= $open;
            if (stripos($line, '</' . $this->rawTag . '>') !== false) {
                $this->rawTag = null;
            }
            $this->lastNonBlank = trim($line) === '' ? $this->lastNonBlank : $line;
            return null;
        }
        if (trim($line) === '') {
            $this->section .= $open;
            $this->sawBlank = true;
            return null;
        }

        $isHeadingBoundary = $this->sawBlank
            && preg_match('/^#{1,6}([ \t]|$)/', $line) === 1
            && (
                $this->lastNonBlank === null
                || !(
                    preg_match('/^ {0,3}>/', $this->lastNonBlank) === 1
                    || preg_match('/^ {0,3}([-*+]|\d{1,9}[.)])([ \t]|$)/', $this->lastNonBlank) === 1
                    || preg_match('/^(?: {4}|\t)/', $this->lastNonBlank) === 1
                    || str_starts_with(ltrim($this->lastNonBlank), '|')
                )
            );
        if ($isHeadingBoundary) {
            $closed           = $this->section;
            $this->section    = $open;
            $this->sawBlank   = false;
            $this->lastNonBlank = null;
            return $closed;
        }

        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
            $this->fenceChar = $fence[1][0];
            $this->fenceLen  = strlen($fence[1]);
        } elseif (preg_match('/^ {0,3}<(script|pre|style|textarea)\b/i', $line, $html) === 1) {
            $this->rawTag = strtolower($html[1]);
            if (stripos($line, '</' . $this->rawTag . '>') !== false) {
                $this->rawTag = null; // self-contained on one line
            }
        }
        $this->section    .= $open;
        $this->lastNonBlank = $line;
        $this->sawBlank     = false;
        return null;
    }
}
