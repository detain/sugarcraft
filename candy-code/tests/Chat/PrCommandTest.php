<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\PrCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap GAP-GIT-04 (plan §6.1 R13): `/pr` sends a canned push-and-open-a-
 * pull-request prompt as a turn. The gh write itself happens inside that
 * turn through the Bash tool — where the session's permission mode gates it
 * — so what THIS command owes is the prompt: focus paragraph on or off, no
 * `@` the mention scanner could latch onto, and the honest naming of the
 * gate and the failure road.
 */
final class PrCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-pr-' . bin2hex(random_bytes(6));
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

    public function testPrStartsATurnCarryingTheCannedPrompt(): void
    {
        $next = $this->submit('/pr');

        self::assertTrue($next->inFlight, '/pr must start a turn');
        self::assertSame([PrCommand::prompt()], self::userPrompts($next));
        self::assertSame('', $next->inputBuf);
    }

    public function testTheArgumentIsAppendedAsAFocusInstruction(): void
    {
        $next = $this->submit('/pr watch the migrations');

        $prompts = self::userPrompts($next);
        self::assertCount(1, $prompts);
        self::assertStringStartsWith('Push the current branch to its remote', $prompts[0]);
        self::assertStringEndsWith('pay particular attention to: watch the migrations', $prompts[0]);
    }

    public function testTheColonSpellingTakesTheSameFocus(): void
    {
        self::assertSame(self::userPrompts($this->submit('/pr docs')), self::userPrompts($this->submit('/pr:docs')));
    }

    public function testThePromptNamesGhTheGateAndTheFailureRoad(): void
    {
        $prompt = PrCommand::prompt();

        foreach (['gh pr create', 'Bash', 'permission'] as $name) {
            self::assertStringContainsString($name, $prompt);
        }
        self::assertStringContainsString('hand back', $prompt, 'a failed step must hand the user the exact command');
        self::assertStringNotContainsString('@', $prompt, 'the mention scanner must find nothing to attach');
        self::assertStringNotContainsString('%s', $prompt);
        self::assertSame($prompt, PrCommand::prompt('   '), 'a blank focus is no focus');
    }

    public function testPrIsAdvertisedWithItsFocusHint(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn($s): bool => $s->name === 'pr'));

        self::assertCount(1, $rows);
        self::assertSame('[<focus>]', $rows[0]->argumentHint);
    }

    public function testPrWhileATurnIsInFlightStartsNoSecondTurn(): void
    {
        $busy = $this->submit('hello');
        self::assertTrue($busy->inFlight);

        [$next] = $busy->runCommand('/pr');

        self::assertSame(['hello'], self::userPrompts($next));
    }
}
