<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for CLI argument parsing via parseArgs().
 */
final class CliTest extends TestCase
{
    protected function setUp(): void
    {
        // Require bin/pop with the guard so dispatch doesn't execute
        // The functions parseArgs and buildEmail are free functions in SugarCraft\Post namespace
        require_once __DIR__ . '/../bin/pop';
    }

    public function testParseArgsFromFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '--from', 'a@b', '--to', 'c@d']);

        $this->assertSame('a@b', $result['opts']['from']);
        $this->assertSame(['c@d'], $result['opts']['to']);
        $this->assertFalse($result['help']);
    }

    public function testParseArgsToFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '--from', 'a@b', '--to', 'c@d', '--subject', 'Hi']);

        $this->assertSame('Hi', $result['opts']['subject']);
    }

    public function testParseArgsHelpFlagSurfaces(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '--help']);

        $this->assertTrue($result['help']);
        $this->assertTrue($result['opts']['help']);
    }

    public function testParseArgsShortHelpFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '-h']);

        $this->assertTrue($result['help']);
        $this->assertTrue($result['opts']['help']);
    }

    public function testParseArgsMultipleTo(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b',
            '--to', 'c@d',
            '--to', 'e@f',
        ]);

        $this->assertSame(['c@d', 'e@f'], $result['opts']['to']);
    }

    public function testParseArgsCc(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--cc', 'cc@example.com',
        ]);

        $this->assertSame(['cc@example.com'], $result['opts']['cc']);
    }

    public function testParseArgsBcc(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--bcc', 'bcc@example.com',
        ]);

        $this->assertSame(['bcc@example.com'], $result['opts']['bcc']);
    }

    public function testParseArgsAttachments(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--attach', 'file1.pdf', '--attach', 'file2.pdf',
        ]);

        $this->assertSame(['file1.pdf', 'file2.pdf'], $result['opts']['attachments']);
    }

    public function testParseArgsShortFromFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '-f', 'a@b', '-t', 'c@d']);

        $this->assertSame('a@b', $result['opts']['from']);
        $this->assertSame(['c@d'], $result['opts']['to']);
    }

    public function testParseArgsShortSubjectFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs(['pop', '-f', 'a@b', '-t', 'c@d', '-s', 'Hello']);

        $this->assertSame('Hello', $result['opts']['subject']);
    }

    public function testParseArgsBodyFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--body', 'Message body',
        ]);

        $this->assertSame('Message body', $result['opts']['body']);
    }

    public function testParseArgsReplyTo(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--reply-to', 'reply@example.com',
        ]);

        $this->assertSame('reply@example.com', $result['opts']['reply_to']);
    }

    public function testParseArgsHtmlFlag(): void
    {
        $result = \SugarCraft\Post\parseArgs([
            'pop', '--from', 'a@b', '--to', 'c@d', '--html', '<p>HTML body</p>',
        ]);

        $this->assertSame('<p>HTML body</p>', $result['opts']['html']);
    }
}
