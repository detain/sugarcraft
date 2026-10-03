<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
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
 *    resolves. A section whose text still holds an unresolved `[...]` pair
 *    could change if a later section defines its label (`array[0]` becomes
 *    a link once `[0]: /u` arrives), so it is held back, not answered, until
 *    a later definition resolves it or the document ends. A `]` with no `[`
 *    before it in the same block can never become a link and holds nothing.
 *    The first definition of a label wins, as in CommonMark.
 *
 * A held section is still rendered once, when it is confirmed, against the
 * definitions known then; the body is reused until a later definition
 * arrives. So a stream whose early section holds a stray `[1]` costs a
 * {@see finish()} preview no more than one without it: only the open tail
 * is parsed and rendered per frame, never the held sections behind it.
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
     * Confirmed sections not answered yet: a held one and every section
     * after it, which must wait so bodies leave in order. Every entry carries
     * its rendered body; `defs` is the size of {@see $known} that body was
     * rendered against, so a held body stays valid until a definition is
     * learned after it ({@see $known} only grows).
     *
     * @var list<array{source: string, body: string, held: bool, defs: int}>
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
            $this->queue[] = [
                'source' => $tail,
                'body' => $this->renderer->renderParsedSection($document),
                'held' => false,
                'defs' => \count($this->known),
            ];
        }

        $defs = \count($this->known);
        $bodies = [];
        foreach ($this->queue as $entry) {
            $bodies[] = $entry['held'] && $entry['defs'] !== $defs
                ? $this->renderer->renderParsedSection($this->renderer->parseSection($entry['source'], $this->known))
                : $entry['body'];
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
            'body' => $this->renderer->renderParsedSection($document),
            'held' => self::hasUnresolvedReference($document),
            'defs' => \count($this->known),
        ];
    }

    /**
     * Definitions were learned: re-render every held section against them,
     * releasing the ones they resolved and refreshing the rest.
     */
    private function retryHeld(): void
    {
        $defs = \count($this->known);
        foreach ($this->queue as $i => $entry) {
            if (!$entry['held'] || $entry['defs'] === $defs) {
                continue;
            }
            $document = $this->renderer->parseSection($entry['source'], $this->known);
            $this->queue[$i] = [
                'source' => $entry['source'],
                'body' => $this->renderer->renderParsedSection($document),
                'held' => self::hasUnresolvedReference($document),
                'defs' => $defs,
            ];
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
        while ($this->queue !== [] && !$this->queue[0]['held']) {
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
     * True when the parser left a `[` and a later `]` of the same block as
     * text: the only shape a later definition can still turn into a link. A
     * bracket it resolved became a link or image, a code span or block is
     * not Text, and a task-list marker is its own node. Brackets pair only
     * inside one block's inline content, so a `]` with no `[` before it in
     * its block (`x]`, `1) item]`) can never become a link. Over-matching (an
     * escaped `\[`, a pair holding a link) only holds a section longer.
     */
    private static function hasUnresolvedReference(Document $document): bool
    {
        /** @var \SplObjectStorage<AbstractBlock, true> $opened blocks whose text already holds a `[` */
        $opened = new \SplObjectStorage();
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Text) {
                continue;
            }
            $literal = $node->getLiteral();
            $open = strpos($literal, '[');
            $close = strrpos($literal, ']');
            if ($open === false && $close === false) {
                continue;
            }
            $block = self::blockOf($node) ?? $document;
            if ($close !== false && ($opened->contains($block) || ($open !== false && $open < $close))) {
                return true;
            }
            if ($open !== false) {
                $opened->attach($block, true);
            }
        }

        return false;
    }

    /** The block whose inline content holds $node. */
    private static function blockOf(Node $node): ?AbstractBlock
    {
        $parent = $node->parent();
        while ($parent !== null && !$parent instanceof AbstractBlock) {
            $parent = $parent->parent();
        }

        return $parent;
    }
}
