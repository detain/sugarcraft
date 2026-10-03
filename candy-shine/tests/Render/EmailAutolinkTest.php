<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Parser\Inline\AutolinkParser;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Render\AutolinkMarker;
use SugarCraft\Shine\Renderer;

/**
 * glamour renders an email autolink (`<a@b.c>`, or a bare GFM-linkified
 * address) as the address alone, hyperlinked to its `mailto:` URL, with no
 * URL suffix; an explicit `[a@b.c](mailto:a@b.c)` stays a labelled link.
 */
final class EmailAutolinkTest extends TestCase
{
    public function testAngleEmailAutolinkHasNoMailtoSuffix(): void
    {
        $out = Renderer::plain()->withHyperlinks(false)->render('<foo@bar.com>');

        $this->assertSame('foo@bar.com', trim($out));
    }

    public function testBareEmailAutolinkHasNoMailtoSuffix(): void
    {
        $out = Renderer::plain()->withHyperlinks(false)->render('write foo@bar.com now');

        $this->assertSame('write foo@bar.com now', trim($out));
    }

    public function testEmailAutolinkHyperlinksTheMailtoUrl(): void
    {
        $out = Renderer::plain()->withHyperlinks()->render('<foo@bar.com>');

        $this->assertSame("\e]8;;mailto:foo@bar.com\e\\foo@bar.com\e]8;;\e\\", trim($out));
    }

    public function testExplicitMailtoLinkKeepsItsUrlSuffix(): void
    {
        $out = Renderer::plain()->withHyperlinks(false)->render('[foo@bar.com](mailto:foo@bar.com)');

        $this->assertSame('foo@bar.com (mailto:foo@bar.com)', trim($out));
    }

    public function testUriAutolinkStillPrintsItsUrl(): void
    {
        $r = Renderer::plain()->withHyperlinks(false);

        $this->assertSame('mailto:foo@bar.com', trim($r->render('<mailto:foo@bar.com>')));
        $this->assertSame('https://ex.com', trim($r->render('<https://ex.com>')));
    }

    public function testMarkerTagsOnlyTheLinkItsParserAppended(): void
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addInlineParser(new AutolinkMarker(new AutolinkParser()), 51);
        $doc = (new MarkdownParser($env))->parse("<a@b.co> [x](mailto:a@b.co)\n");

        $links = [];
        foreach ($doc->iterator() as $node) {
            if ($node instanceof Link) {
                $links[] = $node->data->get(AutolinkMarker::DATA_KEY, false);
            }
        }

        $this->assertSame([true, false], $links);
    }

    public function testMarkerSharesTheWrappedParsersMatchDefinition(): void
    {
        $inner = new AutolinkParser();

        $this->assertEquals($inner->getMatchDefinition(), (new AutolinkMarker($inner))->getMatchDefinition());
    }
}
