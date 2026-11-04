<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\DiffCommand;
use SugarCraft\Vcr\Cli\DocumentedCommand;
use SugarCraft\Vcr\Cli\InspectCommand;
use SugarCraft\Vcr\Cli\MigrateCommand;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Cli\StatsCommand;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * One help contract across the whole CLI.
 *
 * Before this, `--help` was refused as an unknown option by inspect/replay/
 * migrate, dropped on the floor by stats/diff, and honoured only where
 * Symfony happened to be wired — and `-h` was worse than useless on the
 * hand-rolled verbs because it was swallowed as a positional path. Every
 * verb now answers both flags with its own usage block on stdout and
 * exit 0; the third leg pins that the same string is what the verb prints
 * when you call it wrongly, so the two paths cannot drift apart.
 */
final class HelpContractTest extends TestCase
{
    /**
     * @return Generator<string, array{0: string, 1: string, 2: string}>
     */
    public static function helpRequests(): Generator
    {
        $handRolled = ['record', 'inspect', 'replay', 'diff', 'stats', 'migrate'];
        foreach ($handRolled as $verb) {
            foreach (['-h', '--help'] as $flag) {
                yield $verb . ' ' . $flag => [$verb, $flag, 'usage: candy-vcr ' . $verb];
            }
        }

        // The two Symfony verbs answer with Symfony's own help screen.
        foreach (['render-tape', 'render-batch'] as $verb) {
            foreach (['-h', '--help'] as $flag) {
                yield $verb . ' ' . $flag => [$verb, $flag, 'Usage:'];
            }
        }
    }

    #[DataProvider('helpRequests')]
    public function testEveryVerbAnswersHelpWithItsOwnUsage(string $verb, string $flag, string $marker): void
    {
        [$exit, $out, $err] = $this->exec(new Application(), ['candy-vcr', $verb, $flag]);

        self::assertSame(0, $exit, $verb . ' ' . $flag . ' should exit 0');
        self::assertStringContainsString($marker, $out);
        self::assertSame('', $err, 'help belongs on stdout, not stderr');
    }

    public function testHelpWinsOverOtherOptions(): void
    {
        [$exit, $out] = $this->exec(new Application(), ['candy-vcr', 'replay', '--speed', 'instant', '--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('usage: candy-vcr replay', $out);
    }

    /**
     * @return Generator<string, array{0: DocumentedCommand, 1: string}>
     */
    public static function documentedVerbs(): Generator
    {
        yield 'inspect' => [new InspectCommand(), 'inspect'];
        yield 'replay' => [new ReplayCommand(), 'replay'];
        yield 'diff' => [new DiffCommand(), 'diff'];
        yield 'stats' => [new StatsCommand(), 'stats'];
        yield 'migrate' => [new MigrateCommand(), 'migrate'];
    }

    /**
     * The usage block has exactly one home: the string a verb prints when
     * you call it with nothing is the same string `--help` prints.
     */
    #[DataProvider('documentedVerbs')]
    public function testUsageHasOneHomePerVerb(DocumentedCommand $command, string $verb): void
    {
        [$exit, , $err] = $this->exec($command);

        self::assertSame(2, $exit, $verb . ' with no arguments should refuse with exit 2');
        self::assertSame($command->usage(), $err, $verb . ' prints its own usage() when misused');
    }

    /**
     * @param array<string> $argv
     * @param array<string> $cmdArgs
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function exec(object $target, array $argv = [], array $cmdArgs = []): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        if ($target instanceof Application) {
            $exit = $target->run($argv, $stdout, $stderr);
        } else {
            /** @var \SugarCraft\Vcr\Cli\Command $target */
            $exit = $target->run($cmdArgs, $stdout, $stderr);
        }

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
