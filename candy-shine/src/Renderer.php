<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Shine\Lang;
use SugarCraft\Shine\Render\BlockContext;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Shine\Render\BlockStack;
use SugarCraft\Shine\Style\StyleCascade;
use SugarCraft\Shine\Style\StyleSheet;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Table\Table as SprinklesTable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
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
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

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

    public readonly Theme $theme;
    /**
     * CommonMark parser, built lazily on first {@see render()} and cached
     * on the instance. Left null by the constructor so the chainable
     * `with*()` builders (which spin up a fresh instance via {@see copy()})
     * do NOT rebuild the parser/Environment on every call — only an actual
     * render pays that cost, once per instance.
     */
    private ?MarkdownParser $parser = null;
    private readonly ?int $wrapWidth;
    private readonly bool $emitHyperlinks;
    private readonly ?string $baseUrl;
    private readonly bool $tableWrap;
    private readonly bool $inlineTableLinks;
    private readonly bool $preservedNewLines;
    private readonly bool $expandEmoji;
    private readonly bool $sanitize;
    private readonly bool $textIsPlain;
    private bool $inTableCell = false;

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
        bool $preservedNewLines = false,
        bool $expandEmoji = false,
        bool $sanitize = true,
    ) {
        $this->theme = $theme ?? Theme::ansi();
        $this->wrapWidth = ($wrapWidth !== null && $wrapWidth > 0) ? $wrapWidth : null;
        $this->emitHyperlinks = $emitHyperlinks;
        $this->baseUrl = $baseUrl;
        $this->tableWrap = $tableWrap;
        $this->inlineTableLinks = $inlineTableLinks;
        $this->preservedNewLines = $preservedNewLines;
        $this->expandEmoji = $expandEmoji;
        $this->sanitize = $sanitize;
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
     * Preserve consecutive blank lines in source markdown. By default
     * CommonMark collapses runs of blank lines; with this on, every
     * `\n\n+` in the source survives into the output. Mirrors glamour's
     * `WithPreservedNewLines`.
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
        ?bool $preservedNewLines = null,
        ?bool $expandEmoji = null,
        ?bool $sanitize = null,
    ): self {
        return new self(
            $theme            ?? $this->theme,
            $wrapWidthSet ? $wrapWidth : $this->wrapWidth,
            $emitHyperlinks   ?? $this->emitHyperlinks,
            $baseUrlSet ? $baseUrl : $this->baseUrl,
            $tableWrap        ?? $this->tableWrap,
            $inlineTableLinks ?? $this->inlineTableLinks,
            $preservedNewLines ?? $this->preservedNewLines,
            $expandEmoji      ?? $this->expandEmoji,
            $sanitize         ?? $this->sanitize,
        );
    }

    public function render(string $markdown): string
    {
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

        // Lazy-initialize block stack with root Document context.
        // Using null check ensures fresh state when Renderer is reused via copy().
        if ($this->blockStack === null) {
            $this->blockStack = new BlockStack();
            $this->styleSheet = StyleSheet::base();
        }

        $document = $this->parser()->parse($markdown);
        $this->blockStack->push(new BlockContext(
            BlockKind::Document,
            depth: 0,
            accumulatedIndent: 0,
            cascadedStyle: $this->theme->paragraph ?? Style::new(),
        ));

        $rendered = $this->renderChildren($document);
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
        if ($this->preservedNewLines) {
            // CommonMark collapses `\n\n+` to one blank-line break;
            // re-inflate by counting source blank lines and padding the
            // output. We approximate by matching `\n{3,}` runs in the
            // source and copying the count into the output where the
            // first single blank line lives.
            $sourceRuns = self::extractBlankRuns($markdown);
            if ($sourceRuns !== []) {
                $rendered = self::reapplyBlankRuns($rendered, $sourceRuns);
            }
        }
        return $rendered;
    }

    /**
     * Replace `:shortcode:` tokens with their Unicode equivalent
     * before parsing. Mirrors glamour's `WithEmoji` expansion. Unknown
     * shortcodes pass through verbatim. Map matches the gum format
     * subcommand's emoji selector for consistency.
     */
    private static function expandEmojiShortcodes(string $markdown): string
    {
        static $map = [
            'smile'    => '😄', 'grin'    => '😁',
            'heart'    => '❤️', 'fire'    => '🔥',
            'rocket'   => '🚀', 'star'    => '⭐',
            'thumbsup' => '👍', 'thumbsdown' => '👎',
            'check'    => '✅', 'x'       => '❌',
            'warning'  => '⚠️',  'info'    => 'ℹ️',
            'tada'     => '🎉', 'sparkles' => '✨',
            'candy'    => '🍬', 'sugar'   => '🍭',
            'honey'    => '🍯',
            'clap'     => '👏', 'eyes'    => '👀',
            'tongue'   => '👅', 'wink'   => '😉',
            'sob'      => '😭', 'sleeping' => '😴',
            'zzz'      => '💤', ' headphones' => '🎧',
            'mail'     => '📧', 'email'  => '📧',
            'phone'    => '📞', 'camera' => '📷',
            'gift'     => '🎁', 'pencil' => '📝',
            'hammer'   => '🔨', 'wrench' => '🔧',
            'bug'      => '🐛', 'dragon' => '🐉',
            'koala'    => '🐨', 'tiger'  => '🐯',
            'rabbit'   => '🐰', 'snake'  => '🐍',
        ];
        return (string) preg_replace_callback(
            self::EMOJI_SHORTCODE_PATTERN,
            static fn (array $m): string => $map[strtolower($m[1])] ?? $m[0],
            $markdown,
        );
    }

    /**
     * Extract sequences of 3+ consecutive newlines from the source,
     * recording the count for each match. Used by
     * {@see withPreservedNewLines()} to re-inflate runs that CommonMark
     * collapses on parse.
     *
     * @return list<int> list of blank-line counts, in source order
     */
    private static function extractBlankRuns(string $source): array
    {
        $out = [];
        if (preg_match_all('/\n{3,}/', $source, $m) === false) {
            return $out;
        }
        foreach ($m[0] as $run) {
            $out[] = strlen($run) - 1; // n newlines = n-1 blank lines
        }
        return $out;
    }

    /**
     * Replace the first `len(runs)` `\n\n` separators in `$rendered`
     * with `\n` repeated by the matching run count.
     *
     * @param list<int> $runs
     */
    private static function reapplyBlankRuns(string $rendered, array $runs): string
    {
        $i = 0;
        return (string) preg_replace_callback(
            '/\n{2,}/',
            static function (array $m) use (&$i, $runs) {
                if ($i < count($runs)) {
                    $blanks = $runs[$i++];
                    return str_repeat("\n", $blanks);
                }
                return $m[0];
            },
            $rendered,
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
     * Strip C0 control bytes (except tab / newline) and ESC from a
     * source-derived string. This closes the ANSI-injection vector
     * while preserving legitimate formatting whitespace.
     *
     * Mirrors charmbracelet/glamour TUI render invariant.
     */
    private static function stripControls(string $s): string
    {
        // Remove C0 controls except \t (0x09) and \n (0x0a); also strip ESC (0x1b).
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $s);
    }

    private function renderText(string $literal): string
    {
        if ($this->sanitize) {
            $literal = self::stripControls($literal);
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

        $newCtx = new BlockContext(
            BlockKind::Paragraph,
            depth: $depth + 1,
            accumulatedIndent: $parentCtx?->accumulatedIndent ?? 0,
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
            $parts[] = $this->renderNode($child);
        }
        return implode('', $parts);
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
        if ($lang === '') {
            return $this->theme->codeBlock->render($body);
        }
        return SyntaxHighlighter::highlight($body, $lang, $this->theme);
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
            accumulatedIndent: $parentCtx?->accumulatedIndent ?? 0,
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
            $body   = $this->applyCase($this->renderChildren($h), $this->theme->headingCase);
            return $style->render($prefix . $body . $suffix) . "\n\n";
        } finally {
            $this->blockStack->pop();
        }
    }

    /**
     * Apply a case transform to a heading body.
     *
     * Mirrors glamour's `Upper` / `Lower` / `Title` flags collapsed
     * into a single `case` selector. `none` (default) is identity;
     * unknown selectors fall through to identity.
     */
    private function applyCase(string $text, string $case): string
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

        // Blockquote adds 2 cells of indent and 1 margin unit.
        $parentIndent = $parentCtx?->accumulatedIndent ?? 0;
        $newCtx = new BlockContext(
            BlockKind::BlockQuote,
            depth: $depth + 1,
            accumulatedIndent: $parentIndent + 2,
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

    private function renderListItem(ListItem $item): string
    {
        // Push ListItem context onto the stack.
        $parentCtx = $this->blockStack->peek();
        $depth = $this->blockStack->depth();
        $parentStyle = $parentCtx?->cascadedStyle ?? ($this->theme->paragraph ?? Style::new());
        $blockStyle = $this->styleSheet->for(BlockKind::ListItem, $depth);
        $cascadedStyle = StyleCascade::merge($parentStyle, $blockStyle);

        $newCtx = new BlockContext(
            BlockKind::ListItem,
            depth: $depth + 1,
            accumulatedIndent: ($parentCtx?->accumulatedIndent ?? 0),
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
            accumulatedIndent: $parentCtx?->accumulatedIndent ?? 0,
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
                $body   = rtrim($this->renderChildren($item), "\n");
                // Paragraphs inside list items emit a trailing blank line for
                // top-level separation; collapse those runs so nested lists
                // sit directly under their parent rather than after a gap.
                $body = (string) preg_replace('/\n{2,}/', "\n", $body);

                $lines  = explode("\n", $body);
                $first  = array_shift($lines) ?? '';
                // Continuation indent: max of bullet width and configured
                // listLevelIndent so nested lists indent uniformly per
                // theme. Default levelIndent (4) matches glamour stock.
                $indentN = max(mb_strlen($bullet, 'UTF-8') + 1, $levelIndent);
                $indent  = str_repeat(' ', $indentN);

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

        if ($text === '' || $text === $url) {
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
        $headers = [];
        $rows    = [];
        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }
            $isHeader = $section->isHead();
            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->children() as $cell) {
                    if (!$cell instanceof TableCell) {
                        continue;
                    }
                    $this->inTableCell = true;
                    try {
                        $body = rtrim($this->renderChildren($cell));
                    } finally {
                        $this->inTableCell = false;
                    }
                    if ($this->tableWrap && $this->wrapWidth !== null) {
                        $body = Width::wrapAnsi($body, $this->wrapWidth);
                    }
                    // Apply per-cell theme style (header vs body) when set.
                    $cellStyle = $isHeader
                        ? $this->theme->tableHeader
                        : $this->theme->tableCell;
                    if ($cellStyle !== null) {
                        $body = $cellStyle->render($body);
                    }
                    $cells[] = $body;
                }
                if ($isHeader) {
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }
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
