<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Pruning\CompressPreviewHook;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall as RowCall;
use SugarCraft\Crush\ToolResult as RowResult;
use SugarCraft\Crush\Tools\BuiltIn\Compress;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-4, Kilo's legacy `/compact --self`: the turn's own model writes
 * the summary with its Compress call — one range over the whole closed
 * conversation — and on that turn alone the call is put to the person as a
 * question carrying the summary ({@see CompressPreviewHook}), so it applies
 * only once they approved it. A plain `/compress` turn is not previewed.
 */
final class CompactSelfPreviewTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string|false $env = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-compact-self-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/root', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->env = getenv(PruningMode::ENV);
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->env === false ? putenv(PruningMode::ENV) : putenv(PruningMode::ENV . '=' . $this->env);
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
    }

    public function testTheSummaryIsPreviewedAndAppliesOnlyWhenApproved(): void
    {
        $asked = [];
        $approved = $this->engine(static function (ToolCall $call, HookResult $ask) use (&$asked): bool {
            $asked[] = [$call->name(), $ask->message];

            return true;
        })->complete(self::history(Compress::selfCompactionPrompt()));

        $this->assertCount(1, $asked, 'the Compress call was put to the person');
        $this->assertSame('Compress', $asked[0][0]);
        $this->assertStringContainsString('/compact --self: the model\'s summary would replace the conversation it covers ("Whole session")', $asked[0][1]);
        $this->assertStringContainsString("r1…r3:\nThe user asked to read a.php; it defines login().", $asked[0][1]);
        $this->assertNotNull($approved->contextLedger?->block(1), 'approved: the block applies');

        $refused = $this->engine(static fn (ToolCall $call, HookResult $ask): bool => false)
            ->complete(self::history(Compress::selfCompactionPrompt()));
        $this->assertNull($refused->contextLedger?->block(1), 'refused: the conversation stays as it was');
    }

    public function testAPlainCompressTurnIsNotPreviewed(): void
    {
        $asked = 0;
        $reply = $this->engine(static function () use (&$asked): bool {
            $asked++;

            return false;
        })->complete(self::history(Compress::triggerPrompt()));

        $this->assertSame(0, $asked);
        $this->assertNotNull($reply->contextLedger?->block(1));
    }

    public function testTheTriggerIsRecognisedAndOnlyTheSelfOneIsSelf(): void
    {
        $self = Compress::selfCompactionPrompt('the auth work');
        $this->assertStringStartsWith(Compress::TRIGGER . "\n" . Compress::SELF_MARKER . "\n", $self);
        $this->assertStringEndsWith("Focus from the user:\nthe auth work", $self);
        $this->assertTrue(Compress::isSelfCompaction([new \SugarCraft\Crush\Messages\UserMessage($self)]));
        $this->assertTrue(Compress::isTriggered([new \SugarCraft\Crush\Messages\UserMessage($self)]), 'it is a /compress trigger too: the tool is offered');
        $this->assertFalse(Compress::isSelfCompaction([new \SugarCraft\Crush\Messages\UserMessage(Compress::triggerPrompt())]));
    }

    public function testTheQuestionShowsTheSummaryBounded(): void
    {
        $long = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 40)));
        $question = CompressPreviewHook::question(['topic' => 'T', 'ranges' => [['from' => 'r1', 'to' => 'r9', 'summary' => $long]]]);

        $this->assertStringContainsString("r1…r9:\nline 1\n", $question);
        $this->assertStringContainsString('line 29', $question);
        $this->assertStringNotContainsString('line 30', $question);
        $this->assertStringContainsString('… (11 more lines)', $question);
        $this->assertStringEndsWith('Approve to apply it; refuse to keep the conversation as it is.', $question);
        $this->assertSame('^Compress$', (new CompressPreviewHook())->matcher());
    }

    public function testTheCommandSendsTheSelfTriggerOrSaysWhyItCannot(): void
    {
        [$next, $cmd] = self::type(new Chat(history: self::history('hi')), '/compact --self the auth work');

        $this->assertNotNull($cmd);
        $this->assertTrue($next->inFlight);
        $prompts = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === \SugarCraft\Crush\Role::User && !$m->uiOnly));
        $this->assertSame(Compress::selfCompactionPrompt('the auth work'), $prompts[array_key_last($prompts)]->content);

        [$off] = self::type(new Chat(history: self::history('hi')), '/pruning off');
        [$refused, $none] = self::type($off, '/compact --self');
        $this->assertNull($none);
        $this->assertStringStartsWith('Context pruning is `off`', $refused->history[array_key_last($refused->history)]->content);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param \Closure(ToolCall, HookResult): bool $approver */
    private function engine(\Closure $approver): EngineBackend
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('k1', 'Compress', [
                'topic' => 'Whole session',
                'ranges' => [['from' => 'r1', 'to' => 'r3', 'summary' => 'The user asked to read a.php; it defines login().']],
            ])]),
            new CompleteResponse(content: 'Done.'),
        ], contextWindow: 1_000_000);

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/root')
            ->withTools([Prune::new(), Compress::new()])
            ->withContextLedger(ContextLedger::new()->withDefaultMode(PruningMode::Auto))
            ->withPermissionApprover($approver);
    }

    /** @return array{0: Chat, 1: mixed} */
    private static function type(Chat $chat, string $draft): array
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat->withSize(120, 30), ['inputBuf' => $draft]);

        return $drafted->update(new KeyMsg(KeyType::Enter));
    }

    /** @return list<Message> */
    private static function history(string $prompt): array
    {
        return [
            Message::user('read a.php'),
            Message::assistant('')->withToolCalls([new RowCall('Read', ['file_path' => 'a.php'], 'old')])->withStepId('s_x_1')->withUserVisible(false),
            Message::assistant(str_repeat('a.php line ', 400))->withToolResults([new RowResult('Read', str_repeat('a.php line ', 400), null, 'old')])->withStepId('s_x_1'),
            Message::assistant('read it')->withStepId('s_x_2'),
            Message::user('thanks'),
            Message::assistant('welcome')->withStepId('s_x_3'),
            Message::user($prompt),
        ];
    }
}
