<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Render;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Normalizer\TextNormalizer;
use League\CommonMark\Reference\ReferenceMap;
use SugarCraft\Markdown\Renderer;

/**
 * Incremental section renderer shared by the streaming family
 * ({@see Renderer::stream()}, {@see \SugarCraft\Markdown\Writer}) and by any
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
 *  - **Link reference definitions**. A definition applies to
 *    the whole document. Every section is parsed with the definitions of
 *    the sections before it, so a reference to an earlier definition
 *    resolves. A section whose text still holds an unresolved `[...]` pair
 *    could change if a later section defines its label (`array[0]` becomes
 *    a link once `[0]: /u` arrives), so it is held back, not answered, until
 *    a later definition resolves it or the document ends. A `]` with no `[`
 *    before it in the same block can never become a link and holds nothing.
 *    The first definition of a label wins, as in CommonMark.
 *
 * A confirmed section that still carries an unresolved pair is rendered
 * against the definitions known when it was confirmed, then held: its entry
 * stores the normalized labels its pairs could take ({@see $queue}), and it
 * is re-parsed and re-rendered only when a definition names one of them.
 * A definition event therefore costs O(1) for every held section it cannot
 * resolve, and one section's re-renders are bounded by the definitions
 * naming its own labels — not by the document's definition count, and not
 * by the number of chunks. (A pair whose `[` and `]` sit in different text
 * runs carries a label the parsed tree no longer shows verbatim, so such an
 * entry stays pessimistic and re-renders on every definition event.) The
 * open tail alone is parsed afresh per frame. A preview built with
 * `(clone $stream)->finish()` therefore replays the held sections exactly as
 * the full stream would (M4).
 */
final class SectionStream
{
    /** Beyond this many distinct candidate labels an entry is treated as wild. */
    private const MAX_WATCH_LABELS = 256;

    /** CommonMark label normalization, the same steps the reference map keys by. */
    private static TextNormalizer $normalizer;

    private SectionScanner $scanner;

    /** Every definition of the confirmed sections so far, first per label. */
    private ReferenceMap $known;

    /**
     * The normalized labels {@see $known} holds, in the order learned —
     * the watermark slice an entry has not been rendered against yet.
     *
     * @var list<string>
     */
    private array $learned = [];

    /** Source of sections whose proposed end the parser refused, waiting to lead the next one. */
    private string $unconfirmed = '';

    /**
     * Confirmed sections not answered yet: a held one and every section
     * after it, which must wait so bodies leave in order. Every entry carries
     * its rendered body; `defs` is how many definitions had been learned when
     * that body was rendered, and `watch` the normalized labels of the pairs
     * still unresolved in it (null: the entry cannot narrow its interest,
     * every new definition may change it). A held body stays valid across
     * definition events none of its labels appear in ({@see $known} only
     * grows, so its watermark just moves).
     *
     * @var list<array{source: string, body: string, held: bool, defs: int, watch: list<string>|null}>
     */
    private array $queue = [];

    public function __construct(private readonly Renderer $renderer)
    {
        $this->scanner = new SectionScanner();
        $this->known = new ReferenceMap();
        self::$normalizer ??= new TextNormalizer();
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
            // No definition can arrive after the document ends: the tail's
            // unresolved pairs stay literal, its body is final as rendered.
            $this->queue[] = [
                'source' => $tail,
                'body' => $this->renderer->renderParsedSection($document),
                'held' => false,
                'defs' => \count($this->learned),
                'watch' => [],
            ];
        }

        // Definitions learned from the tail can still resolve held entries.
        $this->retryHeld();

        $bodies = [];
        foreach ($this->queue as $entry) {
            $bodies[] = $entry['body'];
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
        // SM-S03 refusals the parser could only echo, proven from bytes:
        //  - a mid-line provisional cut: $source has no line ending, so
        //    $headingLine continues its last line and can never open a
        //    column-0 ATX heading;
        //  - a next-section head that is not ATX-shaped at all: the probe's
        //    only candidate heading line is $headingLine.
        // Skipping the parse keeps capped-blob re-joins O(1); when either
        // test is inconclusive the full probe below arbitrates as always.
        if ($headingLine !== '' && (!str_ends_with($source, "\n")
            || preg_match('/^ {0,3}#{1,6}([ \t]|$)/', $headingLine) !== 1)) {
            $this->unconfirmed = $source;

            return;
        }
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
        $labels = self::unresolvedLabels($document);
        $this->queue[] = [
            'source' => $source,
            'body' => $this->renderer->renderParsedSection($document),
            'held' => $labels !== [],
            'defs' => \count($this->learned),
            'watch' => $labels,
        ];
    }

    /**
     * Definitions were learned: re-render every held section a newly learned
     * label can actually resolve, releasing the ones they resolved and
     * refreshing the rest. Entries none of the new labels appear in keep
     * their cached body; only their watermark moves, so unrelated
     * definitions cost O(1) per held section.
     */
    private function retryHeld(): void
    {
        $defs = \count($this->learned);
        foreach ($this->queue as $i => $entry) {
            if (!$entry['held'] || $entry['defs'] === $defs) {
                continue;
            }
            $fresh = \array_slice($this->learned, $entry['defs'], $defs - $entry['defs']);
            if ($entry['watch'] !== null && \array_intersect($entry['watch'], $fresh) === []) {
                $this->queue[$i]['defs'] = $defs;

                continue;
            }
            $document = $this->renderer->parseSection($entry['source'], $this->known);
            $labels = self::unresolvedLabels($document);
            $this->queue[$i] = [
                'source' => $entry['source'],
                'body' => $this->renderer->renderParsedSection($document),
                'held' => $labels !== [],
                'defs' => $defs,
                'watch' => $labels,
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
        foreach ($document->getReferenceMap() as $normalizedLabel => $reference) {
            if (!$this->known->contains($reference->getLabel())) {
                $this->known->add($reference);
                // The map keys by the normalized label, the same spelling
                // an unresolved candidate is normalized to.
                $this->learned[] = (string) $normalizedLabel;
            }
        }
    }

    /**
     * The normalized labels a later definition could still turn into a link
     * inside this document: every still-unresolved `[...]` pair the parser
     * left as text — the only shape a definition can change. A bracket it
     * resolved became a link or image, a code span or block is not Text, and
     * a task-list marker is its own node. Brackets pair only inside one
     * block's inline content, so a `]` with no `[` before it in its block
     * (`x]`, `1) item]`) can never become a link.
     *
     * Returns null when some pair cannot be narrowed: its `[` and `]` sit in
     * different text runs, and the raw source of the label between them (any
     * inline markup verbatim) is not recoverable from the tree — such an
     * entry re-renders on every definition event, and beyond
     * {@see MAX_WATCH_LABELS} candidates an entry opts into the same
     * pessimism rather than pay a quadratic scan. Over-matching (an escaped
     * `\[`, a pair holding a link) only holds a section longer; missing a
     * candidate would change bytes, so nothing here under-matches.
     *
     * @return list<string>|null empty: nothing is held
     */
    private static function unresolvedLabels(Document $document): ?array
    {
        /** @var \SplObjectStorage<AbstractBlock, true> $opened blocks whose last text run ended inside a `[` */
        $opened = new \SplObjectStorage();
        /** @var array<string, true> $labels normalized candidate labels, deduplicated */
        $labels = [];
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Text) {
                continue;
            }
            $literal = $node->getLiteral();
            if (!str_contains($literal, '[') && !str_contains($literal, ']')) {
                continue;
            }
            $block = self::blockOf($node) ?? $document;
            $pending = $opened->contains($block);
            /** @var list<int> $stack unmatched `[` offsets in this literal */
            $stack = [];
            $length = strlen($literal);
            for ($p = 0; $p < $length; $p++) {
                $byte = $literal[$p];
                if ($byte === '[') {
                    $stack[] = $p;
                    continue;
                }
                if ($byte !== ']') {
                    continue;
                }
                if ($stack !== []) {
                    $open = (int) array_pop($stack);
                    $label = self::$normalizer->normalize(substr($literal, $open + 1, $p - $open - 1));
                    if (!isset($labels[$label])) {
                        if (\count($labels) >= self::MAX_WATCH_LABELS) {
                            return null;
                        }
                        $labels[$label] = true;
                    }
                    continue;
                }
                if ($pending) {
                    // Closes a `[` from an earlier text run: the raw label
                    // spans markup this scan cannot see. Wild.
                    return null;
                }
            }
            if ($stack !== []) {
                $opened->attach($block, true);
            } elseif ($pending) {
                $opened->detach($block);
            }
        }

        return array_map('strval', array_keys($labels));
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
