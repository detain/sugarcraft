<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\RulesCommand;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Host\Commands\NewRulePrompt;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14d: `/newrule [focus]` sends a canned prompt as a turn asking the
 * agent to draft a project rule into `.sugar-crush/rules/` — a write the
 * protect-files hook asks about in every permission mode (step 0.8b).
 *
 * @see NewRulePrompt
 */
final class NewRuleCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-newrule-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        mkdir($this->sandbox . '/repo', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    public function testNewRuleStartsATurnCarryingTheCannedPrompt(): void
    {
        $next = $this->submit('/newrule');

        self::assertTrue($next->inFlight, '/newrule must start a turn');
        self::assertSame([NewRulePrompt::prompt()], self::userPrompts($next));
        self::assertSame('', $next->inputBuf);
    }

    public function testTheArgumentIsAppendedAsAFocus(): void
    {
        $prompts = self::userPrompts($this->submit('/newrule the commit message style'));

        self::assertCount(1, $prompts);
        self::assertStringStartsWith('Please turn what this conversation has established into a project rule', $prompts[0]);
        self::assertStringEndsWith('The user asked the rule to cover: the commit message style', $prompts[0]);
    }

    public function testThePromptNamesTheRuleFilesAlreadyThere(): void
    {
        mkdir($this->sandbox . '/repo/.sugar-crush/rules', 0o755, true);
        touch($this->sandbox . '/repo/.sugar-crush/rules/zeta.md');
        touch($this->sandbox . '/repo/.sugar-crush/rules/alpha.md');
        touch($this->sandbox . '/repo/.sugar-crush/rules/notes.txt');

        self::assertSame(['alpha.md', 'zeta.md'], NewRulePrompt::existingRules($this->sandbox . '/repo'));
        self::assertStringContainsString(
            'The rule files already there are: alpha.md, zeta.md.',
            self::userPrompts($this->submit('/newrule'))[0],
        );
    }

    public function testALongListIsCutAndSaysHowManyMore(): void
    {
        $names = array_map(static fn (int $i): string => sprintf('r%02d.md', $i), range(1, NewRulePrompt::MAX_LISTED + 3));

        $prompt = NewRulePrompt::prompt('', $names);

        self::assertStringContainsString('r40.md and 3 more (list the directory before choosing a name).', $prompt);
        self::assertStringNotContainsString('r41.md', $prompt);
    }

    public function testThePromptCarriesTheContract(): void
    {
        $prompt = NewRulePrompt::prompt();

        self::assertStringContainsString(NewRulePrompt::DIRECTORY . '/', $prompt);
        self::assertStringContainsString('## Brief overview', $prompt);
        foreach (NewRulePrompt::SECTIONS as $section) {
            self::assertStringContainsString('## ' . $section, $prompt);
        }
        self::assertStringContainsString('Do not invent preferences', $prompt);
        self::assertStringContainsString('Never overwrite or edit an existing rule file', $prompt);
        self::assertStringContainsString('There are no rule files in .sugar-crush/rules/ yet.', $prompt);
        self::assertStringNotContainsString('@', $prompt, 'the mention scanner must find nothing to attach');
        self::assertStringNotContainsString('%', $prompt);
        self::assertSame($prompt, NewRulePrompt::prompt('   '), 'a blank focus is no focus');
    }

    /**
     * The write the prompt asks for lands on the policy surface: it is put to
     * the human in every mode and never remembered by an "always" grant.
     */
    public function testTheRuleWriteIsAlwaysAskedAbout(): void
    {
        $args = ['file_path' => NewRulePrompt::DIRECTORY . '/testing-conventions.md', 'content' => "---\nname: x\n---\nx"];

        foreach (PermissionMode::cases() as $mode) {
            $manager = new HookManager(new HookRegistry());
            $manager->registerBuiltIns();
            $manager->register(new PermissionGateHook(new PermissionGate($mode)));
            $verdict = $manager->preToolUse(new HookContext(
                sessionId: 's',
                toolName: 'Write',
                toolArgs: $args,
                toolInput: (string) json_encode($args),
                toolOutput: '',
                model: 'm',
                provider: 'p',
                projectRoot: $this->sandbox . '/repo',
            ));

            self::assertFalse($verdict->permitsExecution(), "{$mode->value}: the rule was written unprompted");
            if ($verdict->isDenied()) {
                self::assertStringStartsWith("Permission mode '{$mode->value}'", (string) $verdict->message);

                continue;
            }
            self::assertTrue($verdict->isAsk(), $mode->value);
            self::assertFalse($verdict->askedOnlyBy('permission-gate'), "{$mode->value}: an \"always\" grant would remember it");
        }
    }

    public function testItIsAdvertisedBesideRules(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn ($s): bool => $s->name === 'newrule'));

        self::assertCount(1, $rows);
        self::assertSame('[focus]', $rows[0]->argumentHint);
        self::assertSame('Rules', $rows[0]->category);
    }

    public function testTheRulesListingPointsAtIt(): void
    {
        ob_start();
        (new RulesCommand(new RuleLoader($this->sandbox . '/repo'), RulesState::new()))->execute(new Chat(backend: new EchoBackend()));
        $text = (string) ob_get_clean();

        self::assertStringContainsString('/newrule has the agent draft a project rule', $text);
    }

    public function testItIsRefusedMidTurn(): void
    {
        self::assertSame(
            TurnController::ROUTE_REFUSE_COMMAND,
            TurnController::new()->midTurnRoute('/newrule', false, SubmitOptions::new()),
        );
    }

    private function submit(string $draft): Chat
    {
        $chat = (new Chat(inputBuf: $draft, backend: new EchoBackend(), projectRoot: $this->sandbox . '/repo'))->withSize(100, 30);
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** @return list<string> */
    private static function userPrompts(Chat $chat): array
    {
        return array_values(array_map(
            static fn (Message $m): string => $m->content,
            array_filter($chat->history, static fn (Message $m): bool => $m->role === Role::User),
        ));
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
}
