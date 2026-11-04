<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * `candy-vcr help <sub>` must answer with the sub's OWN help. Before the
 * routing door the verb was ignored: `help inspect` printed only the global
 * usage, even though `inspect --help` already had the real content.
 */
final class HelpSubcommandTest extends TestCase
{
    /** @param list<string> $argv @return array{0:int,1:string} */
    private function runHelp(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(['candy-vcr', ...$argv], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, ((string) stream_get_contents($stdout)) . ((string) stream_get_contents($stderr))];
    }

    public function testHelpHandRolledVerbPrintsItsUsage(): void
    {
        [$exit, $out] = $this->runHelp(['help', 'inspect']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('usage: candy-vcr inspect', $out);
    }

    public function testHelpReplayVerbCarriesTheSpeedDefaults(): void
    {
        [$exit, $out] = $this->runHelp(['help', 'replay']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('usage: candy-vcr replay', $out);
        $this->assertStringContainsString('Default --speed is instant', $out);
    }

    public function testHelpSymfonyVerbDelegatesToItsHelp(): void
    {
        [$exit, $out] = $this->runHelp(['help', 'render-tape']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Usage:', $out);
        $this->assertStringContainsString('Options:', $out);
    }

    public function testHelpUnknownSubIsRefusedLikeARun(): void
    {
        [$exit, $out] = $this->runHelp(['help', 'definitely-not-a-verb']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("unknown subcommand 'definitely-not-a-verb'", $out);
        $this->assertStringContainsString('usage: candy-vcr <subcommand>', $out);
    }

    public function testBareHelpStillPrintsGlobalUsage(): void
    {
        [$exit, $out] = $this->runHelp(['help']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('usage: candy-vcr <subcommand>', $out);
    }
}
