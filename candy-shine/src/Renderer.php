<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Shine\Render\AutolinkMarker;
use SugarCraft\Shine\Render\BlockContext;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Shine\Render\BlockStack;
use SugarCraft\Shine\Render\SectionStream;
use SugarCraft\Shine\Style\StyleCascade;
use SugarCraft\Shine\Style\StyleSheet;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Table\Table as SprinklesTable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentPreParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Autolink\EmailAutolinkParser;
use League\CommonMark\Extension\CommonMark\Parser\Inline\AutolinkParser;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table as MdTable;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Extension\DescriptionList\DescriptionList as MdDescriptionList;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\DescriptionList\Node\Description;
use League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Reference\ReferenceMapInterface;

/**
 * Markdown → ANSI renderer. Parses input with `league/commonmark` and
 * walks the resulting AST, producing styled strings via the {@see Theme}.
 *
 * Block-level nodes return text that already ends in trailing newlines;
 * inline nodes return inline fragments. Unknown node types fall back to
 * concatenating their children — graceful degradation for any extension
 * the renderer doesn't know about.
 */
final class Renderer
{
    private const EMOJI_SHORTCODE_PATTERN = '/:([a-z0-9_+-]+):/i';

    /**
     * Curated emoji layer — merged OVER {@see GithubEmoji::MAP} in
     * {@see expandEmojiShortcodes()}, so these bytes stay whatever they
     * have always been even where GitHub maps the same code differently
     * (:email:/:phone: here are receiver glyphs, GitHub's are envelopes
     * and telephones; :heart:/:warning: carry the emoji presentation
     * selector U+FE0F). Every key MUST match EMOJI_SHORTCODE_PATTERN's
     * capture class — a key with stray whitespace is dead code (the
     * leading-space ` headphones` defect of earlier rounds proved it;
     * EmojiParityTest censuses the shape so it cannot return).
     */
    private const HOUSE_EMOJI = [
        'smile'      => '😄', 'grin'       => '😁',
        'heart'      => '❤️', 'fire'       => '🔥',
        'rocket'     => '🚀', 'star'       => '⭐',
        'thumbsup'   => '👍', 'thumbsdown' => '👎',
        'check'      => '✅', 'x'          => '❌',
        'warning'    => '⚠️', 'info'       => 'ℹ️',
        'tada'       => '🎉', 'sparkles'   => '✨',
        'candy'      => '🍬', 'sugar'      => '🍭',
        'honey'      => '🍯',
        'clap'       => '👏', 'eyes'       => '👀',
        'tongue'     => '👅', 'wink'       => '😉',
        'sob'        => '😭', 'sleeping'   => '😴',
        'zzz'        => '💤', 'headphones' => '🎧',
        'mail'       => '📧', 'email'      => '📧',
        'phone'      => '📞', 'camera'     => '📷',
        'gift'       => '🎁', 'pencil'     => '📝',
        'hammer'     => '🔨', 'wrench'     => '🔧',
        'bug'        => '🐛', 'dragon'     => '🐉',
        'koala'      => '🐨', 'tiger'      => '🐯',
        'rabbit'     => '🐰', 'snake'      => '🐍',
    ];

    public readonly Theme $theme;
    /**
     * CommonMark parser, built lazily on first {@see render()} and cached
     * on the instance. Left null by the constructor so the chainable
     * `with*()` builders (which spin up a fresh instance via {@see copy()})
     * do NOT rebuild the parser/Environment on every call — only an actual
     * render pays that cost, once per instance.
     */
    private ?MarkdownParser $parser = null;

    /**
     * Link reference definitions {@see parseSection()} seeds into the parse
     * in flight, null outside one. Only the stream family sets it.
     */
    private ?ReferenceMapInterface $seedReferences = null;
    private readonly ?int $wrapWidth;
    private readonly bool $emitHyperlinks;
    private readonly ?string $baseUrl;
    private readonly bool $tableWrap;
    private readonly bool $inlineTableLinks;
    private readonly bool $tableColumnBudget;

    /** E43 step 1 (fence half): clip rendered fence lines at the available width. */
    private readonly bool $fenceColumnBudget;
    private readonly bool $preservedNewLines;
    private readonly bool $expandEmoji;
    private readonly bool $sanitize;
    private readonly bool $textIsPlain;
    private bool $inTableCell = false;

    /**
     * The theme's `headingCase` while a heading's inline content renders,
     * null elsewhere. Applied per Text node by {@see renderText()}, before
     * styling, so the transform never reaches SGR or OSC-8 bytes.
     */
    private ?string $textCase = null;

    /**
     * The source lines of the document {@see render()} is rendering when
     * {@see withPreservedNewLines()} is on, null otherwise. Read by
     * {@see renderChildren()} to restore the blank lines between blocks.
     *
     * @var list<string>|null
     */
    private ?array $sourceLines = null;

    /** Active block context stack for indent/width computation. */
    private ?BlockStack $blockStack = null;

    /** Cascading stylesheet for per-depth block styling. */
    private ?StyleSheet $styleSheet = null;

    public function __construct(
        ?Theme $theme = null,
        ?int $wrapWidth = null,
        bool $emitHyperlinks = true,
        ?string $baseUrl = null,
        bool $tableWrap = false,
        bool $inlineTableLinks = true,
        bool $tableColumnBudget = false,
        bool $preservedNewLines = false,
        bool $expandEmoji = false,
        bool $sanitize = true,
        bool $fenceColumnBudget = false,
    ) {
        $this->theme = $theme ?? Theme::ansi();
        $this->wrapWidth = ($wrapWidth !== null && $wrapWidth > 0) ? $wrapWidth : null;
        $this->emitHyperlinks = $emitHyperlinks;
        $this->baseUrl = $baseUrl;
        $this->tableWrap = $tableWrap;
        $this->inlineTableLinks = $inlineTableLinks;
        $this->tableColumnBudget = $tableColumnBudget;
        $this->preservedNewLines = $preservedNewLines;
        $this->expandEmoji = $expandEmoji;
        $this->sanitize = $sanitize;
        $this->fenceColumnBudget = $fenceColumnBudget;
        $this->textIsPlain = $this->theme->text === null || $this->isPlainStyle($this->theme->text);
    }

    /**
     * Lazily build (and memoise) the CommonMark parser. Deferring
     * construction out of `__construct()` means the `with*()` builders no
     * longer rebuild the whole Environment + extension set on every call —
     * the parser is assembled exactly once, on the first render of an
     * instance. Extension set is identical to the previous eager build, so
     * render output is byte-for-byte unchanged.
     */
    private function parser(): MarkdownParser
    {
        if ($this->parser === null) {
            $env = new Environment();
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new TableExtension());
            $env->addExtension(new TaskListExtension());
            $env->addExtension(new StrikethroughExtension());
            $env->addExtension(new AutolinkExtension());
            $env->addExtension(new DescriptionListExtension());
            // Tag autolinks so renderLink() can tell `<a@b.c>` from
            // `[a@b.c](mailto:a@b.c)` — the parser builds both as the same
            // Link. One priority above each wrapped parser so it runs first.
            $env->addInlineParser(new AutolinkMarker(new AutolinkParser()), 51);
            $env->addInlineParser(new AutolinkMarker(new EmailAutolinkParser()), 1);
            // Fires once per parse, before any line is read, on the fresh
            // map the parse resolves references against. Seeded entries go
            // in first, so the section's own duplicates lose to them, which
            // is CommonMark's first-definition-wins across the whole document.
            $env->addEventListener(DocumentPreParsedEvent::class, function (DocumentPreParsedEvent $event): void {
                if ($this->seedReferences === null) {
                    return;
                }
                $map = $event->getDocument()->getReferenceMap();
                foreach ($this->seedReferences as $reference) {
                    if (!$map->contains($reference->getLabel())) {
                        $map->add($reference);
                    }
                }
            });
            $this->parser = new MarkdownParser($env);
        }
        return $this->parser;
    }

    public static function ansi(): self  { return new self(Theme::ansi());  }
    public static function plain(): self { return new self(Theme::plain()); }

    /**
     * Top-level convenience: render Markdown with a one-shot
     * `Renderer` instance and return the ANSI string. Mirrors
     * glamour's package-level `Render` function.
     *
     * Pass a Theme to pick a stock or custom theme; default ansi.
     * For repeated rendering with the same theme, build a Renderer
     * directly and reuse it (the parser is cached per instance).
     */
    public static function renderMarkdown(string $markdown, ?Theme $theme = null): string
    {
        return (new self($theme ?? Theme::ansi()))->render($markdown);
    }
    public static function ascii(): self { return new self(Theme::ascii()); }

    /**
     * Render Markdown and write the ANSI output straight to a stream
     * (default {@see STDOUT}). Mirrors charmbracelet/glamour's package-level
     * `Write` function.
     *
     * Returns the number of bytes written — equal to the rendered length on
     * success. Fails loud: a non-resource or read-only stream throws before
     * any rendering work happens, and a failed or short write throws once
     * the write is attempted.
     *
     * @param resource|mixed $output Writable stream resource; default STDOUT.
     * @return int Bytes written.
     */
    public function write(string $markdown, mixed $output = STDOUT): int
    {
        if (!is_resource($output)) {
            throw new \InvalidArgumentException(Lang::t('renderer.stream_invalid'));
        }
        $mode = (string) (stream_get_meta_data($output)['mode'] ?? '');
        if (!preg_match('/[waxc#]|\\+/', $mode)) {
            throw new \InvalidArgumentException(Lang::t('renderer.stream_not_writable', ['mode' => $mode]));
        }
        $rendered = $this->render($markdown);
        $written  = @fwrite($output, $rendered);
        if ($written === false || $written < strlen($rendered)) {
            throw new \RuntimeException(Lang::t('renderer.write_failed', ['bytes' => strlen($rendered)]));
        }
        return $written;
    }

    /**
     * Incremental render channel (E736 7.3): consumes Markdown input as a
     * stream of string chunks and yields styled ANSI chunks as complete
     * top-level block sections finish — the sugar-ecosystem port of
     * glamour's render-then-print block-by-block intent, so a caller can
     * forward bytes while the tail of the document is still arriving.
     *
     * Lawful invariant, pinned by tests: chunk boundaries never influence
     * output. For every split of the same input bytes,
     *   implode('', iterator_to_array($r->stream($chunks))) === $r->render($bytes)
     * holds byte-for-byte.
     *
     * Input is buffered only until the next PROVABLE top-level boundary:
     * a column-0 ATX heading outside fenced and raw-HTML blocks, either
     * preceded by a blank line and not directly following a blockquote,
     * indented-code, or table row (defensive refusals — such a
     * heading can never be absorbed across the cut, but the hold keeps the
     * contract airtight against parser subtleties), or straight after a
     * closing code fence. The parser confirms every such boundary before a
     * section is rendered on its own ({@see SectionStream}).
     *
     * Link reference definitions apply to the whole document, so they are
     * carried across sections: a section is rendered against every
     * definition before it, and a section with a bracket the parser could
     * not resolve is held back until a later definition resolves it or the
     * document ends (audit 15b-30). Documents without a boundary, and
     * renderers whose document-scope post-processing is global by nature
     * (block prefix/suffix, indent, margin, preservedNewLines), emit a
     * single final chunk — still byte-identical to {@see render()}.
     *
     * Pure channel: no timers, no I/O handles, no ReactPHP (E646). Each
     * yielded chunk is a plain string of composed SGR bytes; consuming the
     * generator lazily also makes the input iterable pull-on-demand, so a
     * caller feeding an unbounded source is never forced to materialise it.
     *
     * @param iterable<string> $chunks
     * @return \Generator<int, string>
     */
    public function stream(iterable $chunks): \Generator
    {
        if ($this->defersStreaming()) {
            $whole = '';
            foreach ($chunks as $chunk) {
                $whole .= self::requireChunk($chunk);
            }
            yield $this->render($whole);
            return;
        }

        $pending = '';
        foreach ($this->sectionBodies($chunks) as $body) {
            $head = rtrim($body, "\n");
            if ($head === '') {
                // A body with nothing but newlines joins the run held back.
                $pending .= $body;
                continue;
            }
            if ($pending !== '') {
                yield $pending;
            }
            yield $head;
            $pending = substr($body, strlen($head));
        }
        // The last held newline run is deliberately dropped: render() rtrims
        // trailing "\n" once at document end, and this reproduces that
        // exactly — every interior run is re-emitted with the next section's
        // head, so the concatenation equals render() byte for byte.
    }

    /**
     * True when this renderer's document-scope post-processing (block
     * prefix/suffix, indent, margin, preservedNewLines) is global by nature,
     * forcing the streaming family ({@see stream()}, {@see Writer}) to
     * buffer the whole document into its final chunk. Single source of the
     * law so both channels can never diverge on the predicate.
     */
    public function defersStreaming(): bool
    {
        return $this->theme->documentBlockPrefix !== ''
            || $this->theme->documentBlockSuffix !== ''
            || $this->theme->documentIndent > 0
            || $this->theme->documentMargin > 0
            || $this->preservedNewLines;
    }

    /**
     * Write-side counterpart of {@see stream()}: an object channel that
     * accepts Markdown chunks through {@see Writer::feed()} and pushes every
     * newly proven section into the sink immediately (write-through flush
     * law, E736 7.3). Shares the section stream and deferral predicate with
     * this channel, so feed()/close() output equals stream() output for the
     * same bytes.
     */
    public function writer(StreamSink $sink): Writer
    {
        return new Writer($this, $sink);
    }

    /**
     * The rendered bodies of the input's sections, in order, through one
     * {@see SectionStream}. Its state depends only on the assembled bytes,
     * never on where a chunk ended — which is what makes chunk-split
     * invariance a structural property rather than a hopeful one.
     *
     * @param iterable<string> $chunks
     * @return \Generator<string> raw section bodies, without render()'s document-scope post-processing
     */
    private function sectionBodies(iterable $chunks): \Generator
    {
        $sections = new SectionStream($this);
        foreach ($chunks as $chunk) {
            foreach ($sections->push(self::requireChunk($chunk)) as $body) {
                yield $body;
            }
        }
        foreach ($sections->finish() as $body) {
            yield $body;
        }
    }

    /**
     * Render one section to its raw ANSI body WITHOUT the document-scope
     * post-processing of {@see render()}: no rtrim, block prefix/suffix,
     * indent, or margin — those wrap the assembled stream exactly once (and
     * when they are configured at all, stream() falls back to buffering).
     *
     * The section is rendered on its own: a reference-style link whose
     * definition lives in another section stays literal text. The stream
     * family carries definitions across sections through
     * {@see SectionStream}, which renders with {@see parseSection()} and
     * {@see renderParsedSection()}.
     */
    public function renderSection(string $section): string
    {
        return $this->renderParsedSection($this->parseSection($section));
    }

    /**
     * Parse one section, prepared exactly as {@see render()} prepares a whole
     * document (sanitizing, emoji), with $seed's link reference definitions
     * known before the section's own. Every step of that preparation is
     * byte-local and never crosses a newline, so per-section preparation
     * keeps `stream() === render()`.
     *
     * @internal the stream family's primitive ({@see SectionStream}); the
     *           document is league/commonmark's and may change with it.
     */
    public function parseSection(string $section, ?ReferenceMapInterface $seed = null): Document
    {
        if ($this->sanitize) {
            $section = self::scrubForParse($section);
        }
        if ($this->expandEmoji) {
            $section = self::expandEmojiShortcodes($section);
            if ($this->sanitize) {
                $section = self::stripControls($section);
            }
        }

        $this->seedReferences = $seed;
        try {
            return $this->parser()->parse($section);
        } finally {
            $this->seedReferences = null;
        }
    }

    /**
     * Render a document {@see parseSection()} built, as {@see renderSection()}
     * does.
     *
     * A throwaway copy renders each section so every section starts from a
     * fresh Document block context and the instance's own block state
     * (which {@see render()} resets per call) is never touched by the
     * stream channel.
     *
     * @internal the stream family's primitive ({@see SectionStream}).
     */
    public function renderParsedSection(Document $document): string
    {
        $sub = $this->copy();
        $sub->blockStack = new BlockStack();
        $sub->styleSheet = StyleSheet::base();
        $sub->blockStack->push(new BlockContext(
            BlockKind::Document,
            depth: 0,
            accumulatedIndent: 0,
            cascadedStyle: $sub->theme->paragraph ?? Style::new(),
        ));

        return $sub->renderChildren($document);
    }

    private static function requireChunk(mixed $chunk): string
    {
        if (!is_string($chunk)) {
            throw new \InvalidArgumentException(Lang::t('renderer.chunk_invalid'));
        }
        return $chunk;
    }

    /**
     * Build a Renderer whose theme is selected by the `GLAMOUR_STYLE`
     * environment variable. Falls back to {@see Theme::ansi()} when the
     * env var is unset / unrecognised. Mirrors glamour's
     * `RenderWithEnvironmentConfig`.
     */
    public static function fromEnvironment(): self
    {
        return new self(Theme::fromEnvironment());
    }

    public function withTheme(Theme $theme): self
    {
        return $this->copy(theme: $theme);
    }

    /**
     * Wrap paragraph + blockquote + list-item bodies at `$cols` cells.
     * Code blocks and tables are never wrapped (they have their own
     * width semantics). Pass null or 0 to disable wrapping.
     *
     * Mirrors glamour's `WithWordWrap`.
     */
    public function withWordWrap(?int $cols): self
    {
        return $this->copy(wrapWidth: $cols, wrapWidthSet: true);
    }

    /**
     * Emit OSC 8 hyperlink escapes for `[text](url)` links so terminals
     * that support it render the text as a real clickable link. When
     * disabled (or the terminal doesn't support OSC 8), links degrade
     * to styled text plus a trailing ` (url)` suffix. Default: enabled.
     */
    public function withHyperlinks(bool $on = true): self
    {
        return $this->copy(emitHyperlinks: $on);
    }

    /**
     * Base URL prefixed onto relative `[text](path)` link / image targets.
     * URLs that already have a scheme (http://, https://, mailto:, …)
     * pass through unchanged. Mirrors glamour's `WithBaseURL`.
     */
    public function withBaseURL(?string $url): self
    {
        $url = $url === null || $url === '' ? null : rtrim($url, '/') . '/';
        return $this->copy(baseUrl: $url, baseUrlSet: true);
    }

    /**
     * Wrap text inside table cells at the renderer's word-wrap width.
     * Default off (cells render unwrapped, matching glamour's default).
     * Mirrors glamour's `WithTableWrap`.
     *
     * NOTE (E49): this wraps EACH CELL at the full `wrapWidth`, so it does
     * not bound the table itself — a three-column table can still render
     * roughly three times pane-wide with its border rows wrapping. For a
     * width-bounded table pair it with {@see withTableColumnBudget()}, the
     * knob that actually solves "table too wide".
     */
    public function withTableWrap(bool $on = true): self
    {
        return $this->copy(tableWrap: $on);
    }

    /**
     * Whether links inside table cells render as inline `[text](url)`
     * pairs (default — terse, scannable) or as full hyperlinks. When
     * false, table cells suppress the trailing `(url)` and OSC-8 envelope
     * since they bloat narrow columns. Mirrors glamour's
     * `WithInlineTableLinks`.
     */
    public function withInlineTableLinks(bool $on = true): self
    {
        return $this->copy(inlineTableLinks: $on);
    }

    /**
     * Bound a table's TOTAL rendered width by giving every column its share
     * of the renderer's word-wrap width (E49). Natural column contents are
     * scaled down proportionally, floor one cell per column, until padding,
     * border rules and all columns together fit `withWordWrap($cols)`; each
     * cell is then CLIPPED to its column budget. Default off — legacy
     * `withTableWrap` per-cell full-width reflow is untouched.
     *
     * When both are on, the budget wins over the wrap: this port's
     * Sprinkles column metrics sum a wrapped multi-line cell's lines, so a
     * reflowed cell would re-inflate the very border rows the budget exists
     * to bound. Clipping keeps one physical line per cell, which is exactly
     * the shape E43's horizontal-scroll proposal wants — a genuinely wide
     * table keeps bounded geometry in candy-shine and regains the clipped
     * bytes by scrolling in the consumer, not by reflow. A budget-clipped
     * table never needs the block-clip tag of that proposal's step 1; the
     * tag remains only for blocks (fences) this API cannot shrink.
     */
    public function withTableColumnBudget(bool $on = true): self
    {
        return $this->copy(tableColumnBudget: $on);
    }

    /**
     * Clip fenced-code lines to the renderer's word-wrap width (E43 step 1,
     * fence half). {@see withTableColumnBudget()} bounds the one block kind
     * whose geometry it can shrink by redistributing columns; a fence has no
     * columns to shrink, so its long lines simply overflow the pane today.
     * This is the block-clip that proposal left for fences: with the option
     * on (default OFF — every existing byte snapshot holds), each rendered
     * fence line is {@see Width::truncateAnsi()}-clipped at the current
     * block stack's available width, on both the plain code-block and the
     * syntax-highlighted paths. Content clipped here is the consumer's
     * scroll/pager territory, exactly the bounded-geometry-now /
     * full-content-later trade E49 made for tables. Indented code blocks are
     * NOT touched — the entry scopes the FENCED half only.
     */
    public function withFenceColumnBudget(bool $on = true): self
    {
        return $this->copy(fenceColumnBudget: $on);
    }

    /**
     * Preserve consecutive blank lines in source markdown. By default
     * CommonMark collapses runs of blank lines; with this on, every run of
     * blank lines between two blocks survives into {@see render()}'s output
     * at the position it held in the source (runs between the items of a
     * list excepted: lists render tight). Mirrors glamour's
     * `WithPreservedNewLines`. The stream family buffers while it is on
     * ({@see defersStreaming()}).
     */
    public function withPreservedNewLines(bool $on = true): self
    {
        return $this->copy(preservedNewLines: $on);
    }

    /**
     * Pick a stock theme by name. Mirrors glamour's
     * `WithStandardStyle($name)`. Accepts every name {@see Theme::byName()}
     * recognises (`ansi` / `plain` / `dark` / `light` / `dracula` /
     * `tokyo-night` / `pink` / `notty` / `ascii`); unknown names
     * throw `InvalidArgumentException`.
     */
    public function withStandardStyle(string $name): self
    {
        $theme = Theme::byName($name);
        if ($theme === null) {
            throw new \InvalidArgumentException(Lang::t('renderer.unknown_style', ['name' => $name]));
        }
        return $this->copy(theme: $theme);
    }

    /**
     * Expand `:smile:`-style emoji shortcodes in source Markdown
     * before parsing. Default off; on, the renderer rewrites every
     * `:shortcode:` token using the built-in shortcode map. Unknown
     * shortcodes pass through verbatim.
     *
     * Mirrors glamour's `WithEmoji`.
     */
    public function withEmoji(bool $on = true): self
    {
        return $this->copy(expandEmoji: $on);
    }

    /**
     * Strip C0 / ESC control bytes from source-derived text before
     * emitting it. Enabled by default; disable when rendering trusted
     * input where control characters must be preserved. Mirrors the
     * TUI render invariant that renderer output contains only intended
     * SGR escapes, not raw ANSI controls from the source document.
     *
     * This toggle governs only the general text pipeline (paragraph text,
     * inline/fenced/indented code). Raw HTML nodes and link/image URLs are
     * ALWAYS control-stripped regardless of this flag — those channels emit
     * source bytes verbatim into the terminal, so disabling the general
     * sanitizer must not re-open them as an injection vector.
     */
    public function withSanitize(bool $on = true): self
    {
        return $this->copy(sanitize: $on);
    }

    // Short-form alias.
    public function sanitize(bool $on = true): self
    {
        return $this->withSanitize($on);
    }

    // Short-form aliases.
    public function theme(Theme $theme): self            { return $this->withTheme($theme); }
    public function wordWrap(?int $cols): self           { return $this->withWordWrap($cols); }
    public function hyperlinks(bool $on = true): self    { return $this->withHyperlinks($on); }
    public function baseURL(?string $url): self          { return $this->withBaseURL($url); }
    public function tableWrap(bool $on = true): self     { return $this->withTableWrap($on); }
    public function inlineTableLinks(bool $on = true): self { return $this->withInlineTableLinks($on); }
    public function tableColumnBudget(bool $on = true): self { return $this->withTableColumnBudget($on); }

    public function fenceColumnBudget(bool $on = true): self { return $this->withFenceColumnBudget($on); }
    public function preservedNewLines(bool $on = true): self { return $this->withPreservedNewLines($on); }
    public function emoji(bool $on = true): self         { return $this->withEmoji($on); }
    public function standardStyle(string $name): self    { return $this->withStandardStyle($name); }

    /** @internal copy-with-overrides for chainable builders. */
    private function copy(
        ?Theme $theme = null,
        ?int $wrapWidth = null, bool $wrapWidthSet = false,
        ?bool $emitHyperlinks = null,
        ?string $baseUrl = null, bool $baseUrlSet = false,
        ?bool $tableWrap = null,
        ?bool $inlineTableLinks = null,
        ?bool $tableColumnBudget = null,
        ?bool $preservedNewLines = null,
        ?bool $expandEmoji = null,
        ?bool $sanitize = null,
        ?bool $fenceColumnBudget = null,
    ): self {
        return new self(
            $theme            ?? $this->theme,
            $wrapWidthSet ? $wrapWidth : $this->wrapWidth,
            $emitHyperlinks   ?? $this->emitHyperlinks,
            $baseUrlSet ? $baseUrl : $this->baseUrl,
            $tableWrap        ?? $this->tableWrap,
            $inlineTableLinks ?? $this->inlineTableLinks,
            $tableColumnBudget ?? $this->tableColumnBudget,
            $preservedNewLines ?? $this->preservedNewLines,
            $expandEmoji      ?? $this->expandEmoji,
            $sanitize         ?? $this->sanitize,
            $fenceColumnBudget ?? $this->fenceColumnBudget,
        );
    }

    public function render(string $markdown): string
    {
        if ($this->sanitize) {
            $markdown = self::scrubForParse($markdown);
        }
        if ($this->expandEmoji) {
            $markdown = self::expandEmojiShortcodes($markdown);
            // Strip control bytes AFTER expansion (not before), so a shortcode
            // whose replacement smuggles in C0/ESC bytes cannot slip an escape
            // sequence past the sanitizer. Gated on $sanitize to honour
            // withSanitize(false); scoped to the emoji path so ordinary
            // rendering (default: emoji off) is byte-for-byte unchanged.
            if ($this->sanitize) {
                $markdown = self::stripControls($markdown);
            }
        }

        // Fresh block state per render: a reused instance must not keep the
        // previous document's root context (one leaked per call otherwise,
        // inflating every depth-keyed StyleSheet lookup).
        $this->blockStack = new BlockStack();
        $this->styleSheet = StyleSheet::base();

        $document = $this->parser()->parse($markdown);
        $this->blockStack->push(new BlockContext(
            BlockKind::Document,
            depth: 0,
            accumulatedIndent: 0,
            cascadedStyle: $this->theme->paragraph ?? Style::new(),
        ));

        // The parser numbers lines of exactly this string from 1, ending
        // one at \r\n, \r or \n; every preparation step above is
        // byte-local and never crosses a newline.
        $this->sourceLines = $this->preservedNewLines
            ? (preg_split('/\r\n|\r|\n/', $markdown) ?: [])
            : null;
        try {
            $rendered = $this->renderChildren($document);
        } finally {
            $this->sourceLines = null;
        }
        $rendered = rtrim($rendered, "\n");
        // Block prefix / suffix wrap the entire document body (mirrors
        // glamour's StylePrimitive BlockPrefix / BlockSuffix slots).
        if ($this->theme->documentBlockPrefix !== '') {
            $rendered = $this->theme->documentBlockPrefix . $rendered;
        }
        if ($this->theme->documentBlockSuffix !== '') {
            $rendered .= $this->theme->documentBlockSuffix;
        }
        if ($this->theme->documentIndent > 0) {
            $indent = str_repeat(' ', $this->theme->documentIndent);
            $rendered = $indent . str_replace("\n", "\n" . $indent, $rendered);
        }
        if ($this->theme->documentMargin > 0) {
            $margin = str_repeat("\n", $this->theme->documentMargin);
            $rendered = $margin . $rendered . $margin;
        }
        return $rendered;
    }

    /**
     * Replace `:shortcode:` tokens with their Unicode equivalent
     * before parsing. Mirrors glamour's `WithEmoji` expansion
     * (charmbracelet/glamour consults the kyokomi/emoji GitHub table):
     * every GitHub code resolves, with {@see self::HOUSE_EMOJI} winning
     * on collisions. Unknown shortcodes pass through verbatim.
     */
    private static function expandEmojiShortcodes(string $markdown): string
    {
        static $map = self::HOUSE_EMOJI + GithubEmoji::MAP;

        return (string) preg_replace_callback(
            self::EMOJI_SHORTCODE_PATTERN,
            static fn (array $m): string => $map[strtolower($m[1])] ?? $m[0],
            $markdown,
        );
    }

    private function renderNode(Node $node): string
    {
        return match (true) {
            $node instanceof Heading       => $this->renderHeading($node),
            $node instanceof Paragraph     => $this->renderParagraph($node),
            $node instanceof FencedCode    => $this->renderFencedCode($node) . "\n\n",
            $node instanceof IndentedCode  => $this->renderIndent($node),
            $node instanceof BlockQuote    => $this->renderBlockQuote($node),
            $node instanceof ListBlock     => $this->renderList($node),
            $node instanceof ListItem      => $this->renderListItem($node),
            $node instanceof MdTable       => $this->renderTable($node),
            $node instanceof MdDescriptionList => $this->renderDescriptionList($node),
            $node instanceof DescriptionTerm => $this->renderDescriptionTerm($node),
            $node instanceof Description    => $this->renderDescription($node),
            $node instanceof ThematicBreak => $this->theme->rule->render(
                str_repeat($this->theme->horizontalRuleGlyph, max(1, $this->theme->horizontalRuleLength))
            ) . "\n\n",
            $node instanceof Strong        => $this->theme->bold->render($this->renderChildren($node)),
            $node instanceof Emphasis      => $this->theme->italic->render($this->renderChildren($node)),
            $node instanceof Strikethrough => $this->renderStrike($node),
            $node instanceof Code          => $this->renderCode($node),
            $node instanceof Link          => $this->renderLink($node),
            $node instanceof Image         => $this->renderImage($node),
            $node instanceof HtmlBlock     => $this->renderHtmlBlock($node),
            $node instanceof HtmlInline    => $this->renderHtmlSpan($node),
            $node instanceof TaskListItemMarker
                                          => $this->renderTaskMarker($node),
            $node instanceof Text          => $this->renderText($node->getLiteral()),
            $node instanceof Newline       => "\n",
            default                        => $this->renderChildren($node),
        };
    }

    /**
     * A well-formed UTF-8 sequence of two to four bytes (RFC 3629: no
     * overlongs, no surrogates, nothing past U+10FFFF), spelled byte-wise.
     * {@see stripControls()} skips over these so it can tell a lone 8-bit C1
     * byte from a continuation byte that merely falls in 0x80–0x9F.
     */
    private const UTF8_MULTIBYTE = '[\xC2-\xDF][\x80-\xBF]'
        . '|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

    /**
     * Strip C0 control bytes (except tab / newline), ESC, DEL and the C1
     * controls U+0080–U+009F — both UTF-8-encoded and as lone raw 8-bit
     * bytes — from a source-derived string, then render the invisible bidi
     * and zero-width format characters as `<U+XXXX>` markers. This closes
     * the ANSI-injection vector while preserving legitimate formatting
     * whitespace.
     *
     * The C1 sweep matters because a UTF-8 terminal such as xterm decodes
     * `\xC2\x9B` to U+009B and executes it as CSI — markdown carrying
     * `U+009B 2 J` would clear the screen without a single ESC byte (audit
     * 15b-08). Only the introducer is removed; the inert tail stays visible.
     *
     * The lone-byte sweep (audit 15b-29) covers the 8-bit spelling: a bare
     * `\x9B` is malformed UTF-8, and a terminal with 8-bit controls enabled
     * runs it as CSI too. A plain `[\x80-\x9F]` class would shred every
     * multi-byte character whose continuation bytes land in that range (→,
     * 👍), so well-formed sequences are matched first and skipped
     * (`(*SKIP)(*FAIL)`), and only a 0x80–0x9F byte left over is removed —
     * the rule candy-core's `Ansi::strip()` applies for
     * `Sanitize::untrusted()`. It runs BEFORE the C0 sweep on purpose:
     * removing an ASCII byte first could splice `\xC2 \x00 \x9B` into a
     * fresh, well-formed `\xC2\x9B` that the skip would then protect. The
     * other order cannot create one — after the lone-byte sweep every
     * surviving 0x80–0x9F byte continues a lead that sits right before it,
     * and the C0 sweep removes only ASCII bytes and whole `\xC2` pairs.
     *
     * The marker step (audit 15b-28) is candy-core's
     * `Sanitize::markInvisibleFormatting()`: a U+202E RIGHT-TO-LEFT OVERRIDE
     * in a code block would otherwise paint the rest of the line reversed
     * ("Trojan Source"), and a zero-width space would make two different
     * identifiers look the same. Joiners that are doing their job (the ZWJ
     * inside 👩 + U+200D + 💻) survive.
     *
     * Byte-oriented, NO /u flag: a /u pattern fails (returns null) on any
     * malformed UTF-8 in the document, which would turn the whole strip off.
     * `\xC2` is only ever a lead byte, so the pair match cannot split a valid
     * character — U+00A0 and up, and multi-byte characters whose continuation
     * bytes fall in 0x80–0x9F (→, 😀), pass untouched.
     *
     * Mirrors charmbracelet/glamour TUI render invariant.
     */
    private static function stripControls(string $s): string
    {
        // A byte pattern cannot hit PCRE's UTF-8 failure path, but
        // preg_replace is still typed ?string — fail closed to '' rather
        // than return null.
        $s = self::stripLoneC1($s);
        // Remove C0 controls except \t (0x09) and \n (0x0a); also strip ESC
        // (0x1b), DEL (0x7f) and UTF-8 C1 (\xC2\x80-\xC2\x9F).
        $s = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]|\xC2[\x80-\x9F]/', '', $s) ?? '';

        return Sanitize::markInvisibleFormatting($s);
    }

    /**
     * Remove every 0x80–0x9F byte that is not part of a well-formed UTF-8
     * sequence — a lone 8-bit C1 control such as a bare `\x9B` (CSI) —
     * leaving every valid character, including those whose continuation
     * bytes fall in that range, byte-identical (audit 15b-29).
     */
    private static function stripLoneC1(string $s): string
    {
        return preg_replace('/(?:' . self::UTF8_MULTIBYTE . ')(*SKIP)(*FAIL)|[\x80-\x9F]/', '', $s) ?? '';
    }

    /**
     * Make raw source safe to hand the CommonMark parser when sanitising:
     * lone 8-bit C1 bytes are removed ({@see stripLoneC1()}), then any other
     * malformed UTF-8 is repaired to U+FFFD.
     *
     * Why before the parse and not only in {@see stripControls()}: CommonMark
     * refuses input that is not valid UTF-8 with an
     * `UnexpectedEncodingException`, so markdown carrying one raw `\x9B`
     * (audit 15b-29) never reached the per-node sweep — it threw out of
     * {@see render()} instead. Removing the C1 byte (rather than repairing it
     * to U+FFFD) matches candy-core's `Sanitize::untrusted()`, so the same
     * text reads the same through both. Every step is byte-local and never
     * crosses a newline, so {@see renderSection()} applying it per section
     * keeps `stream() === render()`.
     */
    private static function scrubForParse(string $s): string
    {
        $s = self::stripLoneC1($s);
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $prev = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);

        return $s;
    }

    private function renderText(string $literal): string
    {
        if ($this->sanitize) {
            $literal = self::stripControls($literal);
        }
        if ($this->textCase !== null) {
            $literal = self::applyCase($literal, $this->textCase);
        }
        return $this->textIsPlain ? $literal : $this->theme->text->render($literal);
    }

    private function renderCode(Code $node): string
    {
        $literal = $node->getLiteral();
        if ($this->sanitize) {
            $literal = self::stripControls($literal);
        }
        return $this->theme->code->render($literal);
    }

    private function renderIndent(IndentedCode $node): string
    {
        $literal = rtrim($node->getLiteral(), "\n");
        if ($this->sanitize) {
            $literal = self::stripControls($literal);
        }
        return $this->theme->codeBlock->render($literal) . "\n\n";
    }

    private function isPlainStyle(\SugarCraft\Sprinkles\Style $s): bool
    {
        return $s->render('x') === 'x';
    }

    private function renderStrike(Strikethrough $node): string
    {
        $body = $this->renderChildren($node);
        $style = $this->theme->strike ?? \SugarCraft\Sprinkles\Style::new()->strikethrough();
        return $style->render($body);
    }

    private function renderImage(Image $node): string
    {
        $alt = $this->renderChildren($node);
        $url = $this->resolveUrl($node->getUrl());
        $imageStyle = $this->theme->image ?? \SugarCraft\Sprinkles\Style::new()->italic();
        if ($alt === '') {
            $rendered = $imageStyle->render('[image]');
        } else {
            // imageText paints the visible alt-text when set; otherwise
            // fall through to image. Mirrors glamour's `ImageText` slot.
            $textStyle = $this->theme->imageText ?? $imageStyle;
            $rendered = $textStyle->render($alt);
        }
        return $rendered . ' (' . $url . ')';
    }

    /**
     * Apply {@see withBaseURL()} to a relative link / image target.
     * Absolute URLs (any scheme, or `//host/...`) pass through unchanged.
     * URLs always have control bytes stripped before return — C0 / ESC /
     * BEL can break the OSC-8 envelope so they are removed unconditionally
     * (URLs never legitimately contain them).
     */
    private function resolveUrl(string $url): string
    {
        if ($this->baseUrl === null || $url === '') {
            return self::safeUrl($url);
        }
        if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $url) || str_starts_with($url, '#')) {
            // Has scheme, protocol-relative, or fragment-only — leave alone.
            return self::safeUrl($url);
        }
        return self::safeUrl($this->baseUrl . ltrim($url, '/'));
    }

    /**
     * Strip C0 / ESC / BEL from a URL. These bytes cannot appear in a
     * well-formed URI and would break the OSC-8 hyperlink envelope or
     * inject spurious control sequences into the terminal. Applied
     * unconditionally in resolveUrl for defence-in-depth.
     *
     * Mirrors charmbracelet/glamour URL sanitisation.
     */
    private static function safeUrl(string $url): string
    {
        // Remove C0 controls + ESC + BEL.
        return preg_replace('/[\x00-\x1f\x7f]/', '', $url);
    }

    private function renderHtmlBlock(HtmlBlock $node): string
    {
        // Raw HTML is emitted verbatim into the terminal, so it is the most
        // direct terminal-injection vector. Strip C0/ESC UNCONDITIONALLY —
        // per-node granularity, decoupled from withSanitize(false), which
        // only governs the general text pipeline. A caller disabling the
        // sanitizer to preserve intentional controls in trusted text must
        // never thereby re-open a raw-HTML escape channel.
        $literal = self::stripControls(rtrim($node->getLiteral(), "\n"));
        $style = $this->theme->htmlBlock ?? \SugarCraft\Sprinkles\Style::new();
        return $style->render($literal) . "\n\n";
    }

    private function renderHtmlSpan(HtmlInline $node): string
    {
        // See renderHtmlBlock: raw inline HTML is always control-stripped,
        // independent of the withSanitize() toggle.
        $literal = self::stripControls($node->getLiteral());
        $style = $this->theme->htmlSpan ?? \SugarCraft\Sprinkles\Style::new();
        return $style->render($literal);
    }

    private function renderParagraph(Paragraph $node): string
    {
        // Push Paragraph context onto the stack.
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::Paragraph, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        // A paragraph prefixes nothing; the stack sums every context's own
        // indent, so copying the parent's here would charge it twice.
        $newCtx = new BlockContext(
            BlockKind::Paragraph,
            depth: $depth + 1,
            accumulatedIndent: 0,
            cascadedStyle: $cascadedStyle,
        );
        $this->blockStack->push($newCtx);

        try {
            $body = $this->renderChildren($node);
            if ($this->wrapWidth !== null) {
                $avail = $this->blockStack->availableWidth($this->wrapWidth);
                $body = Width::wrapAnsi($body, $avail);
            }
            $body = $this->theme->paragraphPrefix . $body . $this->theme->paragraphSuffix;
            return $cascadedStyle->render($body) . "\n\n";
        } finally {
            $this->blockStack->pop();
        }
    }

    private function renderChildren(Node $parent): string
    {
        $parts = [];
        foreach ($parent->children() as $child) {
            $part = $this->renderNode($child);
            if ($this->sourceLines !== null && $child instanceof AbstractBlock) {
                $next = $child->next();
                if ($next instanceof AbstractBlock) {
                    $part = $this->padBlankLines($part, $child, $next);
                }
            }
            $parts[] = $part;
        }
        return implode('', $parts);
    }

    /**
     * {@see withPreservedNewLines()}: grow the blank lines a rendered block
     * ends with to the longest run of blank lines between it and the next
     * block in the source. CommonMark keeps no blank lines in the tree, but
     * every block carries its source lines, so each run is restored where it
     * stood. Only ever adds: a block already followed by enough blank lines
     * is left alone. (A run inside a list item does not survive: a list
     * renders its items tight.)
     */
    private function padBlankLines(string $rendered, AbstractBlock $block, AbstractBlock $next): string
    {
        $from = $block->getEndLine();
        $to = $next->getStartLine();
        if ($from === null || $to === null) {
            return $rendered;
        }
        $longest = 0;
        $run = 0;
        // Lines are 1-based; the gap is the lines strictly between the two.
        // Inside a blockquote a blank line still carries its `>` markers.
        for ($line = $from + 1; $line < $to; $line++) {
            $text = (string) preg_replace('/^(?:[ \t]{0,3}>[ \t]?)+/', '', $this->sourceLines[$line - 1] ?? '');
            $run = trim($text, " \t") === '' ? $run + 1 : 0;
            $longest = max($longest, $run);
        }
        $have = max(0, strlen($rendered) - strlen(rtrim($rendered, "\n")) - 1);

        return $longest > $have ? $rendered . str_repeat("\n", $longest - $have) : $rendered;
    }

    private function renderFencedCode(FencedCode $node): string
    {
        $body = rtrim($node->getLiteral(), "\n");
        if ($this->sanitize) {
            $body = self::stripControls($body);
        }
        $lang = trim($node->getInfo() ?? '');
        // No language hint → emit as plain code-block. With a hint,
        // route through the syntax highlighter; unknown languages
        // also fall through to the plain code-block style.
        $rendered = $lang === ''
            ? $this->theme->codeBlock->render($body)
            : SyntaxHighlighter::highlight($body, $lang, $this->theme);

        return $this->clipFenceToWidth($rendered);
    }

    /**
     * E43 fence half: with the option on, every physical line of a rendered
     * fence — plain or highlighted, theme decoration included — is clipped
     * so no line exceeds the available width. Off (default) is identity.
     */
    private function clipFenceToWidth(string $rendered): string
    {
        if (!$this->fenceColumnBudget || $this->wrapWidth === null) {
            return $rendered;
        }
        $avail = $this->blockStack->availableWidth($this->wrapWidth);

        return implode("\n", array_map(
            static fn (string $line): string => Width::truncateAnsi($line, $avail),
            explode("\n", $rendered),
        ));
    }

    private function renderHeading(Heading $h): string
    {
        // Push Heading context onto the stack.
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::Heading, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        $newCtx = new BlockContext(
            BlockKind::Heading,
            depth: $depth + 1,
            accumulatedIndent: 0,
            cascadedStyle: $cascadedStyle,
        );
        $this->blockStack->push($newCtx);

        try {
            $style = match ($h->getLevel()) {
                1       => $this->theme->heading1,
                2       => $this->theme->heading2,
                3       => $this->theme->heading3,
                4       => $this->theme->heading4,
                5       => $this->theme->heading5,
                default => $this->theme->heading6,
            };
            $prefix = $this->theme->headingPrefix
                ?? (str_repeat('#', $h->getLevel()) . ' ');
            $suffix = (string) $this->theme->headingSuffix;
            $outerCase = $this->textCase;
            $this->textCase = strtolower($this->theme->headingCase) === 'none' ? null : $this->theme->headingCase;
            try {
                $body = $this->renderChildren($h);
            } finally {
                $this->textCase = $outerCase;
            }
            return $style->render($prefix . $body . $suffix) . "\n\n";
        } finally {
            $this->blockStack->pop();
        }
    }

    /**
     * Apply a heading's case transform to one Text node's literal.
     *
     * Mirrors glamour's `Upper` / `Lower` / `Title` flags collapsed
     * into a single `case` selector. `none` (default) is identity;
     * unknown selectors fall through to identity. It runs on source text
     * only, never on rendered output: code spans, link URLs (including an
     * autolink's visible URL text, see renderLink()) and the escape
     * bytes of inline styling keep their case. Title case therefore starts
     * a word at every Text node, as glamour's per-element transform does.
     */
    private static function applyCase(string $text, string $case): string
    {
        return match (strtolower($case)) {
            'upper' => mb_strtoupper($text, 'UTF-8'),
            'lower' => mb_strtolower($text, 'UTF-8'),
            'title' => mb_convert_case($text, MB_CASE_TITLE, 'UTF-8'),
            default => $text,
        };
    }

    private function renderBlockQuote(BlockQuote $q): string
    {
        // Push BlockQuote context onto the stack.
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::BlockQuote, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        // The stack charges every BlockQuote a 2-cell margin, which is the
        // whole `▎ ` bar column, so the quote's own indent share is 0.
        // Charging the bar as indent on top of the margin wrapped quoted
        // text 2 cells short of the width it is given; glamour likewise
        // charges a quote only its indent, never a margin.
        $newCtx = new BlockContext(
            BlockKind::BlockQuote,
            depth: $depth + 1,
            accumulatedIndent: 0,
            cascadedStyle: $cascadedStyle,
        );
        $this->blockStack->push($newCtx);

        try {
            $inner = rtrim($this->renderChildren($q), "\n");
            if ($this->wrapWidth !== null) {
                $avail = $this->blockStack->availableWidth($this->wrapWidth);
                $inner = Width::wrapAnsi($inner, max(1, $avail));
            }
            $lines = explode("\n", $inner);
            $out   = [];
            foreach ($lines as $line) {
                $out[] = $cascadedStyle->render('▎ ' . $line);
            }
            return implode("\n", $out) . "\n\n";
        } finally {
            $this->blockStack->pop();
        }
    }

    /**
     * Render a list item's blocks inside its own context, so everything in
     * it wraps at the width left after the marker column {@see renderList()}
     * prefixes to its lines. $markerWidth is that column in cells (0 for an
     * item rendered outside a list). The stack charges every ListItem a
     * 2-cell margin — the stock `• ` column — so the item's own indent is
     * the rest of the column.
     */
    private function renderListItem(ListItem $item, int $markerWidth = 0): string
    {
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::ListItem, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        $newCtx = new BlockContext(
            BlockKind::ListItem,
            depth: $depth + 1,
            accumulatedIndent: max(0, $markerWidth - 2),
            cascadedStyle: $cascadedStyle,
        );
        $this->blockStack->push($newCtx);

        try {
            return $this->renderChildren($item);
        } finally {
            $this->blockStack->pop();
        }
    }

    private function renderList(ListBlock $list): string
    {
        // Push List context onto the stack.
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::List, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        $newCtx = new BlockContext(
            BlockKind::List,
            depth: $depth + 1,
            accumulatedIndent: 0,
            cascadedStyle: $cascadedStyle,
        );
        $this->blockStack->push($newCtx);

        try {
            $data    = $list->getListData();
            $ordered = $data->type === ListBlock::TYPE_ORDERED;
            $start   = (int) ($data->start ?? 1);
            // Distinct ordered / unordered marker styles when supplied;
            // both fall through to the catch-all `listMarker`.
            $marker  = $ordered
                ? ($this->theme->orderedListMarker   ?? $this->theme->listMarker)
                : ($this->theme->unorderedListMarker ?? $this->theme->listMarker);
            $orderedFmt = $this->theme->orderedListMarkerFormat;
            $unorderedGlyph = $this->theme->unorderedListMarkerGlyph;
            $levelIndent = max(0, $this->theme->listLevelIndent);

            $result = [];
            $i   = $start;
            foreach ($list->children() as $item) {
                $bullet = $ordered ? sprintf($orderedFmt, $i) : $unorderedGlyph;
                // Continuation indent: max of the bullet's cell width + 1
                // and the theme's listLevelIndent (default 0: the bullet
                // column alone), so nested lists indent uniformly per theme.
                // Known before the body renders, so the body wraps at the
                // width this column leaves (the first line's `bullet ` is
                // never wider than it).
                $indentN = max(Width::string($bullet) + 1, $levelIndent);
                $indent  = str_repeat(' ', $indentN);
                $body   = rtrim($this->renderListItem($item, $indentN), "\n");
                // Paragraphs inside list items emit a trailing blank line for
                // top-level separation; collapse those runs so nested lists
                // sit directly under their parent rather than after a gap.
                $body = (string) preg_replace('/\n{2,}/', "\n", $body);

                $lines  = explode("\n", $body);
                $first  = array_shift($lines) ?? '';

                // CommonMark softbreaks leave trailing whitespace on the
                // preceding Text node; rtrim every emitted line so item
                // bodies don't accumulate stray spaces.
                $result[] = $marker->render($bullet) . ' ' . rtrim($first);
                foreach ($lines as $line) {
                    $line = rtrim($line);
                    $result[] = ($line === '' ? '' : $indent . $line);
                }
                $i++;
            }
            return implode("\n", $result) . "\n";
        } finally {
            $this->blockStack->pop();
        }
    }

    private function renderLink(Link $l): string
    {
        $text = $this->renderChildren($l);
        $url  = $this->resolveUrl($l->getUrl());
        $linkText = $this->theme->linkText ?? $this->theme->link;

        $insideTable = $this->inTableCell;
        $hyperlinks  = $this->emitHyperlinks && !($insideTable && !$this->inlineTableLinks);
        $showSuffix  = !$insideTable || $this->inlineTableLinks;

        // glamour shows an email autolink as its address alone, hyperlinked
        // to the mailto: URL in the link-text style, never with a
        // `(mailto:...)` suffix: the URL only repeats the visible address.
        // An explicit `[a@b.c](mailto:a@b.c)` stays a labelled link there.
        if ($l->data->get(AutolinkMarker::DATA_KEY, false) === true
            && $text !== '' && $text !== $url
            && str_starts_with(strtolower($url), 'mailto:')
        ) {
            $styledText = $linkText->render($text);
            return $hyperlinks ? Ansi::hyperlink($url, $styledText) : $styledText;
        }

        $isAutolink = $text === '' || $text === $url;
        if (!$isAutolink && $this->textCase !== null) {
            // A heading's case transform has already reached the link text,
            // so `HTTPS://EX.COM/A` no longer equals its own URL. Decide
            // autolink-ness from the uncased text instead; the autolink
            // branch prints the URL itself, which keeps its case.
            $outerCase = $this->textCase;
            $this->textCase = null;
            try {
                $isAutolink = $this->renderChildren($l) === $url;
            } finally {
                $this->textCase = $outerCase;
            }
        }

        if ($isAutolink) {
            // Autolink case: bare URL rendered as link text.
            // Prefer the dedicated autolink slot; fall back to link style.
            $style = $this->theme->autolink ?? $this->theme->link;
            $rendered = $style->render($url);
            return $hyperlinks
                ? Ansi::hyperlink($url, $rendered)
                : $rendered;
        }

        $styledText = $linkText->render($text);
        if ($hyperlinks) {
            return $showSuffix
                ? Ansi::hyperlink($url, $styledText) . ' (' . $url . ')'
                : Ansi::hyperlink($url, $styledText);
        }
        return $showSuffix ? $styledText . ' (' . $url . ')' : $styledText;
    }

    /**
     * GitHub-flavoured Markdown tables → Sprinkles Table with a rounded
     * border. The first TableSection (THEAD) becomes the headers; rows
     * inside the second section (TBODY) become body rows. Cell content
     * is rendered with the inline pipeline so emphasis / code / links
     * still pick up their styles.
     */
    private function renderTable(MdTable $table): string
    {
        // Pass 1 renders every cell's inline pipeline RAW; wrap and cell
        // style move to pass 2 so the E49 column budget can be computed
        // from natural content widths first. The default path (budget off)
        // keeps the historic order — wrap@wrapWidth, then style — byte
        // for byte.
        $sections = [];
        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }
            $isHeader = $section->isHead();
            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                $raw = [];
                foreach ($row->children() as $cell) {
                    if (!$cell instanceof TableCell) {
                        continue;
                    }
                    $this->inTableCell = true;
                    try {
                        $raw[] = rtrim($this->renderChildren($cell));
                    } finally {
                        $this->inTableCell = false;
                    }
                }
                $sections[] = [$raw, $isHeader];
            }
        }

        $budgets = ($this->tableColumnBudget && $this->wrapWidth !== null && $sections !== [])
            ? $this->tableColumnBudgets($sections)
            : null;

        $headers = [];
        $rows    = [];
        foreach ($sections as [$cells, $isHeader]) {
            // Apply per-cell theme style (header vs body) when set.
            $cellStyle = $isHeader
                ? $this->theme->tableHeader
                : $this->theme->tableCell;
            foreach ($cells as $i => $body) {
                if ($budgets !== null) {
                    // Budget clips at the column width — a wrapped cell
                    // would inflate the Sprinkles column metrics past any
                    // budget anyway, and E49's chosen trade is bounded
                    // geometry now, full content via E43's scroll later.
                    $body = Width::truncateAnsi($body, $budgets[$i]);
                } elseif ($this->tableWrap && $this->wrapWidth !== null) {
                    $body = Width::wrapAnsi($body, $this->wrapWidth);
                }
                if ($cellStyle !== null) {
                    $body = $cellStyle->render($body);
                }
                $cells[$i] = $body;
            }
            if ($isHeader) {
                $headers = $cells;
            } else {
                $rows[] = $cells;
            }
        }
        $st = SprinklesTable::new()->border($this->buildTableBorder());
        if ($headers !== []) {
            $st = $st->headers(...$headers);
        }
        foreach ($rows as $r) {
            $st = $st->row(...$r);
        }
        return $st->render() . "\n\n";
    }

    /**
     * Per-column CONTENT budgets (cells only — padding and border rules
     * excluded) such that a bordered table never renders wider than the
     * renderer's wrap width. The shrink mirrors Sprinkles\Table::render()'s
     * width-cap math — proportional scale, floor 1 cell per column — so the
     * two mechanisms agree whenever both could apply.
     *
     * @param list<array{0: list<string>, 1: bool}> $sections raw cell text per row
     * @return list<int> one budget per column (ragged rows: widest row wins)
     */
    private function tableColumnBudgets(array $sections): array
    {
        $colCount = 0;
        foreach ($sections as [$cells]) {
            $colCount = max($colCount, count($cells));
        }
        $natural = array_fill(0, $colCount, 0);
        foreach ($sections as [$cells]) {
            foreach ($cells as $i => $cell) {
                $natural[$i] = max($natural[$i], Width::of($cell));
            }
        }
        // buildTableBorder() is always-on here: left + right rule, a rule
        // between every pair of columns, one space of padding each side.
        $overhead = 2 * $colCount + ($colCount + 1);
        $available = max($colCount, $this->wrapWidth - $overhead);
        $total = array_sum($natural);
        $scale = $total > 0 ? $available / $total : 1.0;
        $budgets = [];
        for ($i = 0; $i < $colCount; $i++) {
            // Never budget wider than the content — shrink only.
            $budgets[$i] = min($natural[$i], max(1, (int) floor($natural[$i] * $scale)));
        }
        return $budgets;
    }

    /**
     * Build a Border using theme table-separator glyphs but preserving
     * the rounded corner style from Border::rounded().
     */
    private function buildTableBorder(): Border
    {
        $r = Border::rounded();
        return new Border(
            $r->top,
            $r->bottom,
            $r->left,
            $r->right,
            $r->topLeft,
            $r->topRight,
            $r->bottomLeft,
            $r->bottomRight,
            // Override interior separators from theme glyphs.
            middleLeft: $this->theme->tableColumnSeparator,
            middleRight: $this->theme->tableColumnSeparator,
            middle: $this->theme->tableCenterSeparator,
            middleTop: $this->theme->tableRowSeparator,
            middleBottom: $this->theme->tableRowSeparator,
        );
    }

    private function renderDescriptionList(MdDescriptionList $list): string
    {
        $inner = $this->renderChildren($list);
        $style = $this->theme->definitionList;
        return $style !== null ? $style->render($inner) : $inner;
    }

    private function renderDescriptionTerm(DescriptionTerm $term): string
    {
        $body = $this->renderChildren($term);
        $style = $this->theme->definitionTerm ?? Style::new();
        return $style->render($body);
    }

    private function renderDescription(Description $desc): string
    {
        $body = $this->renderChildren($desc);
        $style = $this->theme->definitionDescription ?? Style::new();
        return $style->render($body) . "\n\n";
    }

    /**
     * Replace TaskListItemMarker (`[x]` / `[ ]`) with the matching glyph,
     * styled as a list marker. CommonMark parses the marker's trailing
     * space as part of the next Text node, so we don't add one here —
     * adding one would produce a double space before the body.
     */
    private function renderTaskMarker(TaskListItemMarker $marker): string
    {
        $glyph = $marker->isChecked()
            ? $this->theme->taskTickedGlyph
            : $this->theme->taskUntickedGlyph;
        return $this->theme->listMarker->render($glyph);
    }
}
