<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\InitCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14e: `/init` sends a canned AGENTS.md-writing prompt as a turn.
 */
final class InitCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-init-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function submit(string $draft): Chat
    {
        $chat = (new Chat(inputBuf: $draft, backend: new EchoBackend()))->withSize(100, 30);
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** @return list<string> */
    private static function userPrompts(Chat $chat): array
    {
        return array_values(array_map(
            static fn(Message $m): string => $m->content,
            array_filter($chat->history, static fn(Message $m): bool => $m->role === Role::User),
        ));
    }

    public function testInitStartsATurnCarryingTheCannedPrompt(): void
    {
        $next = $this->submit('/init');

        self::assertTrue($next->inFlight, '/init must start a turn');
        self::assertSame([InitCommand::prompt()], self::userPrompts($next));
        self::assertSame('', $next->inputBuf);
    }

    public function testTheArgumentIsAppendedAsAFocusInstruction(): void
    {
        $next = $this->submit('/init the test harness');

        $prompts = self::userPrompts($next);
        self::assertCount(1, $prompts);
        self::assertStringStartsWith('Please analyze this project and write an AGENTS.md file', $prompts[0]);
        self::assertStringEndsWith('pay particular attention to: the test harness', $prompts[0]);
    }

    public function testTheColonSpellingTakesTheSameFocus(): void
    {
        self::assertSame(self::userPrompts($this->submit('/init docs')), self::userPrompts($this->submit('/init:docs')));
    }

    public function testThePromptNamesTheFileAndTheAliasesItShouldRead(): void
    {
        $prompt = InitCommand::prompt();

        foreach (['AGENTS.md', 'CLAUDE.md', 'GEMINI.md', '.cursorrules', '.clinerules'] as $name) {
            self::assertStringContainsString($name, $prompt);
        }
        self::assertStringNotContainsString('@', $prompt, 'the mention scanner must find nothing to attach');
        self::assertStringNotContainsString('%s', $prompt);
        self::assertSame($prompt, InitCommand::prompt('   '), 'a blank focus is no focus');
    }

    public function testInitIsAdvertisedWithItsFocusHint(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn($s): bool => $s->name === 'init'));

        self::assertCount(1, $rows);
        self::assertSame('[focus]', $rows[0]->argumentHint);
    }

    public function testInitWhileATurnIsInFlightStartsNoSecondTurn(): void
    {
        $busy = $this->submit('hello');
        self::assertTrue($busy->inFlight);

        [$next] = $busy->runCommand('/init');

        self::assertSame(['hello'], self::userPrompts($next));
    }
}
