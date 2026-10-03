<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Delegates to an autolink inline parser and tags the {@see Link} it
 * appends with {@see AutolinkMarker::DATA_KEY}.
 *
 * league/commonmark builds `<foo@bar.com>` and `[foo@bar.com](mailto:foo@bar.com)`
 * as identical Link nodes, while goldmark keeps them apart (`ast.AutoLink`
 * vs `ast.Link`) and glamour renders them differently: an email autolink
 * shows only its address, an explicit link shows its text and URL. The tag
 * is how the renderer tells the two apart. Registered one priority above
 * the parser it wraps, so it runs first; the original never sees input the
 * wrapper accepted.
 *
 * Mirrors charmbracelet/glamour ansi.(*ANSIRenderer).NewElement `case ast.KindAutoLink`.
 */
final class AutolinkMarker implements InlineParserInterface
{
    public const DATA_KEY = 'sugarcraft_shine_autolink';

    public function __construct(private readonly InlineParserInterface $inner)
    {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return $this->inner->getMatchDefinition();
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $container = $inlineContext->getContainer();
        $before    = $container->lastChild();
        if (!$this->inner->parse($inlineContext)) {
            return false;
        }
        $added = $container->lastChild();
        if ($added instanceof Link && $added !== $before) {
            $added->data->set(self::DATA_KEY, true);
        }

        return true;
    }
}
