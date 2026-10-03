<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Commands\Specs\CommandArguments;

/**
 * DH-CMDS: the built-in commands are one spec file each under
 * `src/Commands/Specs/`, discovered and sorted, and `Chat::dispatchCommand()`
 * routes through the handler each file names. These are the table's own
 * invariants; the routing itself is driven end to end by {@see SlashDispatchTest}.
 */
final class BuiltInCommandsTest extends TestCase
{
    private static function specDir(): string
    {
        return \dirname(__DIR__, 2) . '/src/Commands/Specs';
    }

    public function testEverySpecFileIsDiscoveredInFileNameOrder(): void
    {
        $files = array_values(array_filter(
            array_map('basename', glob(self::specDir() . '/*.php') ?: []),
            static fn (string $f): bool => preg_match(BuiltInCommands::FILE_PATTERN, $f) === 1,
        ));
        sort($files, \SORT_STRING);

        $expected = array_map(
            static fn (string $f): string => (string) preg_replace('/^\d{4}-|\.php$/', '', $f),
            $files,
        );

        self::assertNotSame([], $expected);
        self::assertSame($expected, array_map(static fn (BuiltInCommand $c): string => $c->name(), BuiltInCommands::all()));
        self::assertSame($expected, array_map(static fn (CommandSpec $s): string => $s->name, CommandRegistry::all()));
    }

    public function testNoOtherFileInTheDirectoryIsASpecFile(): void
    {
        foreach (glob(self::specDir() . '/*.php') ?: [] as $path) {
            $file = basename($path);
            if (preg_match(BuiltInCommands::FILE_PATTERN, $file) === 1) {
                continue;
            }

            // Everything else must be a PSR-4 class of the Specs namespace —
            // a misnamed spec file would otherwise be silently skipped.
            self::assertMatchesRegularExpression('/^[A-Z][A-Za-z]+\.php$/', $file, "{$file} is neither a spec file nor a class");
            self::assertTrue(
                class_exists('SugarCraft\\Crush\\Commands\\Specs\\' . basename($file, '.php'))
                || enum_exists('SugarCraft\\Crush\\Commands\\Specs\\' . basename($file, '.php')),
                "{$file} declares no type of its name",
            );
        }
    }

    public function testEveryHandlerIsAChatMethodTakingWhatItsSpecHandsIt(): void
    {
        $chat = new \ReflectionClass(Chat::class);
        foreach (BuiltInCommands::all() as $command) {
            if ($command->handler === null) {
                continue;
            }

            self::assertTrue($chat->hasMethod($command->handler), "/{$command->name()} names Chat::{$command->handler}(), which does not exist");
            $method = $chat->getMethod($command->handler);
            self::assertFalse($method->isStatic(), "Chat::{$command->handler}() must be an instance method");

            $params = $method->getParameters();
            $expected = match ($command->arguments) {
                CommandArguments::None => [],
                CommandArguments::Text => ['string'],
                CommandArguments::Parsed => ['array'],
            };
            self::assertSame(
                $expected,
                array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $params),
                "Chat::{$command->handler}() does not take what /{$command->name()}'s spec hands it",
            );
        }
    }

    public function testAPaletteOnlyRowHasNoHandlerAndEveryVisibleRowHasOne(): void
    {
        foreach (BuiltInCommands::all() as $command) {
            self::assertSame(
                $command->spec->slashVisible,
                $command->handler !== null,
                "/{$command->name()}: a slash-visible row needs a handler and a palette-only row must not have one",
            );
            if ($command->handler === null) {
                self::assertSame([], $command->aliases, "/{$command->name()} is palette-only, so an alias would dispatch nothing");
            }
        }
    }

    public function testEverySpellingResolvesToItsOwnCommand(): void
    {
        foreach (BuiltInCommands::all() as $command) {
            foreach ($command->spellings() as $spelling) {
                self::assertSame($command, BuiltInCommands::forSpelling($spelling));
            }
        }

        self::assertNull(BuiltInCommands::forSpelling('new'), 'a palette-only row is not a dispatching spelling');
        self::assertNull(BuiltInCommands::forSpelling('zzzsecret'));
        self::assertSame(['quit', 'agent', 'background'], BuiltInCommands::aliases());
    }

    public function testAnArgumentLessCommandAcceptsOnlyItsBareName(): void
    {
        $exit = BuiltInCommands::forSpelling('exit');
        self::assertNotNull($exit);
        self::assertTrue($exit->accepts('/exit', 'exit'));
        self::assertTrue($exit->accepts('/quit', 'quit'));
        self::assertFalse($exit->accepts('/exit now', 'exit'));

        $rename = BuiltInCommands::forSpelling('rename');
        self::assertNotNull($rename);
        self::assertTrue($rename->accepts('/rename later', 'rename'));
    }

    public function testTheWrapperIsImmutable(): void
    {
        $spec = CommandSpec::new('zz', 'z', 'App');
        $bare = BuiltInCommand::new($spec);
        $routed = $bare->withHandler('handleKeysCommand', CommandArguments::None)->withAliases('zzz');

        self::assertNull($bare->handler);
        self::assertSame([], $bare->spellings());
        self::assertSame('handleKeysCommand', $routed->handler);
        self::assertSame(CommandArguments::None, $routed->arguments);
        self::assertSame(['zz', 'zzz'], $routed->spellings());
        self::assertSame($spec, $routed->spec);
    }
}
