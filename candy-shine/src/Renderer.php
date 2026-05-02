<?php

declare(strict_types=1);

namespace CandyCore\Shine;

use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Table\Table as SprinklesTable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
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

    public function __construct(?Theme $theme = null)
    {
        $this->theme = $theme ?? Theme::ansi();
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new TaskListExtension());
        $this->parser = new MarkdownParser($env);
    }

    public static function ansi(): self  { return new self(Theme::ansi());  }
    public static function plain(): self { return new self(Theme::plain()); }

    public function withTheme(Theme $theme): self
    {
        return new self($theme);
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
            $node instanceof Paragraph     => $this->theme->paragraph->render($this->renderChildren($node)) . "\n\n",
            $node instanceof FencedCode    => $this->theme->codeBlock->render(rtrim($node->getLiteral(), "\n")) . "\n\n",
            $node instanceof IndentedCode  => $this->theme->codeBlock->render(rtrim($node->getLiteral(), "\n")) . "\n\n",
            $node instanceof BlockQuote    => $this->renderBlockQuote($node),
            $node instanceof ListBlock     => $this->renderList($node),
            $node instanceof ListItem      => $this->renderChildren($node),
            $node instanceof MdTable       => $this->renderTable($node),
            $node instanceof ThematicBreak => $this->theme->rule->render(str_repeat('─', 40)) . "\n\n",
            $node instanceof Strong        => $this->theme->bold->render($this->renderChildren($node)),
            $node instanceof Emphasis      => $this->theme->italic->render($this->renderChildren($node)),
            $node instanceof Code          => $this->theme->code->render($node->getLiteral()),
            $node instanceof Link          => $this->renderLink($node),
            $node instanceof TaskListItemMarker
                                          => $this->renderTaskMarker($node),
            $node instanceof Text          => $node->getLiteral(),
            $node instanceof Newline       => "\n",
            default                        => $this->renderChildren($node),
        };
    }

    private function renderChildren(Node $parent): string
    {
        $out = '';
        foreach ($parent->children() as $child) {
            $out .= $this->renderNode($child);
        }
        return $out;
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
        if ($text === '' || $text === $url) {
            return $this->theme->link->render($url);
        }
        return $this->theme->link->render($text) . ' (' . $url . ')';
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
