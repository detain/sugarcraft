<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Reference\ReferenceInterface;

/**
 * The CommonMark parser a {@see Renderer} family shares, plus the link
 * reference definitions the parse in flight is seeded with.
 *
 * Every renderer a `with*()` builder derives from another shares one of
 * these, so `Renderer::cached($theme, $w)->withFolds($ids)->render()` on
 * every frame reuses one Environment instead of rebuilding it. The parser's
 * document listener reads this object rather than a renderer, so a derived
 * renderer that seeds a section writes the state the shared parser reads.
 *
 * @internal Owned by {@see Renderer}; nothing outside it should hold one.
 */
final class ParserBundle
{
    /** Built on first parse, then reused by every renderer sharing this bundle. */
    public ?MarkdownParser $parser = null;

    /**
     * Seeded references for the parse in flight, null outside one. Only
     * {@see Renderer::parseSection()} sets it, and only for the duration of
     * that parse.
     *
     * @var list<ReferenceInterface>|null
     */
    public ?array $seedReferences = null;
}
