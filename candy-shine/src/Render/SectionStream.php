<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Reference\ReferenceMap;
use SugarCraft\Shine\Renderer;

/**
 * Incremental section renderer shared by the E736 7.3 streaming family
 * ({@see Renderer::stream()}, {@see \SugarCraft\Shine\Writer}) and by any
 * caller that keeps a growing document's rendering between frames.
 *
 * Feed Markdown with {@see push()}; it answers the rendered body of every
 * section that is final, in document order. {@see finish()} declares
 * end-of-document and answers the rest. The bodies concatenated equal
 * `$renderer->render($document)` before that method's document-scope
 * post-processing (rtrim, affixes, indent, margin), for every chunk split.
 *
 * Two things a section rendered on its own can get wrong, and how this
 * class keeps them out:
 *  - **The boundary.** {@see SectionScanner} reads lines, not block
 *    structure, so its boundaries are proposals. Each one is confirmed by
 *    parsing the section together with the heading line that would start
 *    the next: only when the parser makes that line a top-level heading are
 *    all blocks before it closed, so the next section starts from the same
 *    state the whole document would be in. A refused proposal is merged
 *    into the following section.
 *  - **Link reference definitions** (audit 15b-30). A definition applies to
 *    the whole document. Every section is parsed with the definitions of
 *    the sections before it, so a reference to an earlier definition
 *    resolves. A section whose text still holds a bracket the parser left
 *    unresolved could change if a later section defines it, so it is held
 *    back, not answered, until a later definition resolves it or the
 *    document ends. The first definition of a label wins, as in CommonMark.
 *
 * Cloning is cheap and gives an independent copy, so a caller can render a
 * preview of the open tail with `(clone $stream)->finish()` and keep
 * feeding the original.
 */
final class SectionStream
{
    private SectionScanner $scanner;

    /** Every definition of the confirmed sections so far, first per label. */
    private ReferenceMap $known;

    /** Source of sections whose proposed end the parser refused, waiting to lead the next one. */
    private string $unconfirmed = '';

    /**
     * Confirmed sections not answered yet: a held one (null body) and every
     * section after it, which must wait so bodies leave in order.
     *
     * @var list<array{source: string, body: ?string}>
     */
    private array $queue = [];

    public function __construct(private readonly Renderer $renderer)
    {
        $this->scanner = new SectionScanner();
        $this->known = new ReferenceMap();
    }

    public function __clone()
    {
        $this->scanner = clone $this->scanner;
        $this->known = clone $this->known;
    }

    /**
     * Absorb one chunk; answer the bodies of the sections it made final.
     *
     * @return list<string>
     */
    public function push(string $chunk): array
    {
        $closed = $this->scanner->push($chunk);
        if ($closed === []) {
            return [];
        }

        $definitions = \count($this->known);
        foreach ($closed as $i => $section) {
            $next = $closed[$i + 1] ?? $this->scanner->openSection();
            $nl = strpos($next, "\n");
            $this->confirm($this->unconfirmed . $section, $nl === false ? $next : substr($next, 0, $nl + 1));
        }
        if (\count($this->known) > $definitions) {
            $this->retryHeld();
        }

        return $this->release();
    }

    /**
     * End of document: answer every body not answered yet. Held sections are
     * rendered with every definition the document has. Terminal: a stream
     * is not fed after this.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        $tail = $this->unconfirmed . ($this->scanner->finish() ?? '');
        $this->unconfirmed = '';
        if (trim($tail) !== '') {
            // Parsed first: its definitions apply to the held sections too.
            $document = $this->renderer->parseSection($tail, $this->known);
            $this->learn($document);
            $this->queue[] = ['source' => $tail, 'body' => $this->renderer->renderParsedSection($document)];
        }

        $bodies = [];
        foreach ($this->queue as $entry) {
            $bodies[] = $entry['body'] ?? $this->renderer->renderParsedSection(
                $this->renderer->parseSection($entry['source'], $this->known),
            );
        }
        $this->queue = [];

        return $bodies;
    }

    /**
     * Confirm the proposed boundary after $source (the next section starts
     * with $headingLine) and queue $source as a section, or keep it to lead
     * the next one.
     */
    private function confirm(string $source, string $headingLine): void
    {
        $probe = $source . $headingLine;
        if (!str_ends_with($probe, "\n")) {
            $probe .= "\n";
        }
        $document = $this->renderer->parseSection($probe, $this->known);
        $heading = $document->lastChild();
        // The parser numbers lines from 1 and ends one at \r\n, \r or \n.
        $lines = preg_match_all('/\r\n|\r|\n/', $probe);
        if (!$heading instanceof Heading || $heading->getStartLine() !== $lines) {
            $this->unconfirmed = $source;

            return;
        }
        $this->unconfirmed = '';
        $heading->detach();
        $this->learn($document);
        $this->queue[] = [
            'source' => $source,
            'body' => self::hasUnresolvedBracket($document) ? null : $this->renderer->renderParsedSection($document),
        ];
    }

    /** A later definition can resolve a held section: render the ones it now can. */
    private function retryHeld(): void
    {
        foreach ($this->queue as $i => $entry) {
            if ($entry['body'] !== null) {
                continue;
            }
            $document = $this->renderer->parseSection($entry['source'], $this->known);
            if (!self::hasUnresolvedBracket($document)) {
                $this->queue[$i]['body'] = $this->renderer->renderParsedSection($document);
            }
        }
    }

    /**
     * Take the leading run of rendered sections off the queue.
     *
     * @return list<string>
     */
    private function release(): array
    {
        $bodies = [];
        while ($this->queue !== [] && $this->queue[0]['body'] !== null) {
            $bodies[] = array_shift($this->queue)['body'];
        }

        return $bodies;
    }

    /** Add the definitions $document met that no earlier section made. */
    private function learn(Document $document): void
    {
        foreach ($document->getReferenceMap() as $reference) {
            if (!$this->known->contains($reference->getLabel())) {
                $this->known->add($reference);
            }
        }
    }

    /**
     * True when the parser left a `]` as text. A bracket it resolved became a
     * link or image, a code span or block is not Text, and a task-list
     * marker is its own node, so a literal `]` is the only place a later
     * definition could still make a link. Over-matching (an escaped `\]`, a
     * stray bracket) only holds a section longer.
     */
    private static function hasUnresolvedBracket(Document $document): bool
    {
        foreach ($document->iterator() as $node) {
            if ($node instanceof Text && str_contains($node->getLiteral(), ']')) {
                return true;
            }
        }

        return false;
    }
}
