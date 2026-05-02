<?php

declare(strict_types=1);

namespace CandyCore\Shine;

use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Width;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Table\Table as SprinklesTable;
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
    public readonly Theme $theme;
    private readonly MarkdownParser $parser;
    private readonly ?int $wrapWidth;
    private readonly bool $emitHyperlinks;

    public function __construct(?Theme $theme = null, ?int $wrapWidth = null, bool $emitHyperlinks = true)
    {
        $this->theme = $theme ?? Theme::ansi();
        $this->wrapWidth = ($wrapWidth !== null && $wrapWidth > 0) ? $wrapWidth : null;
        $this->emitHyperlinks = $emitHyperlinks;

        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new TaskListExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new AutolinkExtension());
        $this->parser = new MarkdownParser($env);
    }

    public static function ansi(): self  { return new self(Theme::ansi());  }
    public static function plain(): self { return new self(Theme::plain()); }

    public function withTheme(Theme $theme): self
    {
        return new self($theme, $this->wrapWidth, $this->emitHyperlinks);
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
        return new self($this->theme, $cols, $this->emitHyperlinks);
    }

    /**
     * Emit OSC 8 hyperlink escapes for `[text](url)` links so terminals
     * that support it render the text as a real clickable link. When
     * disabled (or the terminal doesn't support OSC 8), links degrade
     * to styled text plus a trailing ` (url)` suffix. Default: enabled.
     */
    public function withHyperlinks(bool $on = true): self
    {
        return new self($this->theme, $this->wrapWidth, $on);
    }

    public function render(string $markdown): string
    {
        $document = $this->parser->parse($markdown);
        $rendered = $this->renderChildren($document);
        return rtrim($rendered, "\n");
    }

    private function renderNode(Node $node): string
    {
        return match (true) {
            $node instanceof Heading       => $this->renderHeading($node),
            $node instanceof Paragraph     => $this->renderParagraph($node),
            $node instanceof FencedCode    => $this->renderFencedCode($node) . "\n\n",
            $node instanceof IndentedCode  => $this->theme->codeBlock->render(rtrim($node->getLiteral(), "\n")) . "\n\n",
            $node instanceof BlockQuote    => $this->renderBlockQuote($node),
            $node instanceof ListBlock     => $this->renderList($node),
            $node instanceof ListItem      => $this->renderChildren($node),
            $node instanceof MdTable       => $this->renderTable($node),
            $node instanceof ThematicBreak => $this->theme->rule->render(str_repeat('─', 40)) . "\n\n",
            $node instanceof Strong        => $this->theme->bold->render($this->renderChildren($node)),
            $node instanceof Emphasis      => $this->theme->italic->render($this->renderChildren($node)),
            $node instanceof Strikethrough => $this->renderStrike($node),
            $node instanceof Code          => $this->theme->code->render($node->getLiteral()),
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

    private function renderText(string $literal): string
    {
        if ($this->theme->text !== null && !$this->isPlainStyle($this->theme->text)) {
            return $this->theme->text->render($literal);
        }
        return $literal;
    }

    private function isPlainStyle(\CandyCore\Sprinkles\Style $s): bool
    {
        return $s->render('x') === 'x';
    }

    private function renderStrike(Strikethrough $node): string
    {
        $body = $this->renderChildren($node);
        $style = $this->theme->strike ?? \CandyCore\Sprinkles\Style::new()->strikethrough();
        return $style->render($body);
    }

    private function renderImage(Image $node): string
    {
        $alt = $this->renderChildren($node);
        $url = $node->getUrl();
        $imageStyle = $this->theme->image ?? \CandyCore\Sprinkles\Style::new()->italic();
        $rendered = $imageStyle->render($alt === '' ? '[image]' : $alt);
        return $rendered . ' (' . $url . ')';
    }

    private function renderHtmlBlock(HtmlBlock $node): string
    {
        $literal = rtrim($node->getLiteral(), "\n");
        $style = $this->theme->htmlBlock ?? \CandyCore\Sprinkles\Style::new();
        return $style->render($literal) . "\n\n";
    }

    private function renderHtmlSpan(HtmlInline $node): string
    {
        $style = $this->theme->htmlSpan ?? \CandyCore\Sprinkles\Style::new();
        return $style->render($node->getLiteral());
    }

    private function renderParagraph(Paragraph $node): string
    {
        $body = $this->renderChildren($node);
        if ($this->wrapWidth !== null) {
            $body = Width::wrapAnsi($body, $this->wrapWidth);
        }
        return $this->theme->paragraph->render($body) . "\n\n";
    }

    private function renderChildren(Node $parent): string
    {
        $out = '';
        foreach ($parent->children() as $child) {
            $out .= $this->renderNode($child);
        }
        return $out;
    }

    private function renderFencedCode(FencedCode $node): string
    {
        $body = rtrim($node->getLiteral(), "\n");
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
        $style = match ($h->getLevel()) {
            1       => $this->theme->heading1,
            2       => $this->theme->heading2,
            3       => $this->theme->heading3,
            4       => $this->theme->heading4,
            5       => $this->theme->heading5,
            default => $this->theme->heading6,
        };
        $prefix = str_repeat('#', $h->getLevel()) . ' ';
        return $style->render($prefix . $this->renderChildren($h)) . "\n\n";
    }

    private function renderBlockQuote(BlockQuote $q): string
    {
        $inner = rtrim($this->renderChildren($q), "\n");
        if ($this->wrapWidth !== null) {
            // Subtract 2 cells for the '▎ ' prefix.
            $inner = Width::wrapAnsi($inner, max(1, $this->wrapWidth - 2));
        }
        $lines = explode("\n", $inner);
        $out   = [];
        foreach ($lines as $line) {
            $out[] = $this->theme->blockquote->render('▎ ' . $line);
        }
        return implode("\n", $out) . "\n\n";
    }

    private function renderList(ListBlock $list): string
    {
        $data    = $list->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;
        $start   = (int) ($data->start ?? 1);
        $marker  = $this->theme->listMarker;

        $out = '';
        $i   = $start;
        foreach ($list->children() as $item) {
            $bullet = $ordered ? sprintf('%d.', $i) : '•';
            $body   = rtrim($this->renderChildren($item), "\n");
            // Paragraphs inside list items emit a trailing blank line for
            // top-level separation; collapse those runs so nested lists
            // sit directly under their parent rather than after a gap.
            $body = (string) preg_replace('/\n{2,}/', "\n", $body);

            $lines  = explode("\n", $body);
            $first  = array_shift($lines) ?? '';
            $indent = str_repeat(' ', mb_strlen($bullet, 'UTF-8') + 1);

            // CommonMark softbreaks leave trailing whitespace on the
            // preceding Text node; rtrim every emitted line so item
            // bodies don't accumulate stray spaces.
            $out .= $marker->render($bullet) . ' ' . rtrim($first) . "\n";
            foreach ($lines as $line) {
                $line = rtrim($line);
                $out .= ($line === '' ? '' : $indent . $line) . "\n";
            }
            $i++;
        }
        return $out . "\n";
    }

    private function renderLink(Link $l): string
    {
        $text = $this->renderChildren($l);
        $url  = $l->getUrl();
        $linkText = $this->theme->linkText ?? $this->theme->link;

        if ($text === '' || $text === $url) {
            $rendered = $this->theme->link->render($url);
            return $this->emitHyperlinks
                ? Ansi::hyperlink($url, $rendered)
                : $rendered;
        }

        $styledText = $linkText->render($text);
        if ($this->emitHyperlinks) {
            // OSC 8: terminals that support it render text as a real
            // clickable link; those that don't see the styled text plus
            // a no-op escape, so we still want the (url) suffix as a
            // visible fallback.
            return Ansi::hyperlink($url, $styledText) . ' (' . $url . ')';
        }
        return $styledText . ' (' . $url . ')';
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
                    $cells[] = rtrim($this->renderChildren($cell));
                }
                if ($isHeader) {
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }
            }
        }
        $st = SprinklesTable::new()->border(Border::rounded());
        if ($headers !== []) {
            $st = $st->headers(...$headers);
        }
        foreach ($rows as $r) {
            $st = $st->row(...$r);
        }
        return $st->render() . "\n\n";
    }

    /**
     * Replace TaskListItemMarker (`[x]` / `[ ]`) with the matching glyph,
     * styled as a list marker. CommonMark parses the marker's trailing
     * space as part of the next Text node, so we don't add one here —
     * adding one would produce a double space before the body.
     */
    private function renderTaskMarker(TaskListItemMarker $marker): string
    {
        $glyph = $marker->isChecked() ? '☑' : '☐';
        return $this->theme->listMarker->render($glyph);
    }
}
