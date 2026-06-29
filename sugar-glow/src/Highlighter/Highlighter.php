<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Highlighter;

/**
 * Highlighter wrapper that processes markdown and highlights code blocks.
 */
final class Highlighter
{
    public function __construct(
        private ?HighlighterInterface $inner = null,
    ) {}

    /**
     * Create a highlighter with the default ChromaJsonHighlighter.
     */
    public static function new(): self
    {
        return new self(new ChromaJsonHighlighter([
            'comment'     => '90',   // bright black
            'string'      => '33',   // yellow
            'keyword'     => '1;34', // bold blue
            'number'      => '1;35', // bold magenta
            'function'    => '1;36', // bold cyan
            'operator'    => '37',   // white
        ]));
    }

    /**
     * Highlight code blocks found in markdown text.
     *
     * Finds content between ```language ... ``` and replaces with highlighted version.
     */
    public function highlightMarkdown(string $markdown): string
    {
        return preg_replace_callback(
            '/```(\w+)?\n([\s\S]*?)```/',
            function (array $matches): string {
                $lang = $matches[1] ?? '';
                $code = $matches[2];
                // Only highlight when a language is specified AND the inner highlighter supports it.
                if ($lang !== '' && $this->inner !== null && $this->inner->supports($lang)) {
                    $code = $this->inner->highlight(rtrim($code), $lang);
                }
                return $code;
            },
            $markdown
        ) ?? $markdown;
    }

    /**
     * Return a new Highlighter with the given highlighter.
     */
    public function withHighlighter(HighlighterInterface $highlighter): self
    {
        return new self($highlighter);
    }
}
