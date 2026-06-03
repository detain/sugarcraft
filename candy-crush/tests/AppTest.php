<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\CallToolCmd;
use SugarCraft\Crush\App\Cmd;
use SugarCraft\Crush\App\ErrorMsg;
use SugarCraft\Crush\App\Msg;
use SugarCraft\Crush\App\RunCompletionCmd;
use SugarCraft\Crush\App\SelectPaneMsg;
use SugarCraft\Crush\App\StatusMsg;
use SugarCraft\Crush\App\ToolResultMsg;
use SugarCraft\Crush\App\UserInputMsg;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;

/**
 * @see App
 * @see Msg
 * @see UserInputMsg
 * @see SelectPaneMsg
 * @see ToolResultMsg
 * @see ErrorMsg
 * @see StatusMsg
 * @see Cmd
 * @see RunCompletionCmd
 * @see CallToolCmd
 */
final class AppTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    // =========================================================================
    // App::new() Tests
    // =========================================================================

    public function testNewCreatesInstanceWithDefaultValues(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $this->assertSame($this->provider, $app->provider);
        $this->assertSame('gpt-4', $app->model);
        $this->assertSame([], $app->messages);
        $this->assertSame([], $app->tools);
        $this->assertSame(Pane::Chat, $app->pane);
        $this->assertNull($app->error);
        $this->assertNull($app->status);
        $this->assertNull($app->sessionId);
        $this->assertSame([], $app->contextFiles);
        $this->assertSame([], $app->enabledSkills);
        $this->assertSame([], $app->activeHooks);
    }

    public function testNewWithDifferentModel(): void
    {
        $app = App::new($this->provider, 'claude-3-opus');

        $this->assertSame('claude-3-opus', $app->model);
    }

    public function testProviderInterfaceIsCorrectlyStoredInApp(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $this->assertSame($this->provider, $app->provider);
        $this->assertInstanceOf(ProviderInterface::class, $app->provider);
    }

    // =========================================================================
    // Immutability Tests - All 11 with*() builders
    // =========================================================================

    public function testWithProviderReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withProvider($this->provider);

        $this->assertNotSame($a, $b);
        $this->assertSame($this->provider, $a->provider); // Unchanged
    }

    public function testWithModelReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withModel('claude-3');

        $this->assertNotSame($a, $b);
        $this->assertSame('gpt-4', $a->model);
        $this->assertSame('claude-3', $b->model);
    }

    public function testWithMessagesReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $msg = new UserMessage('Hello');
        $b = $a->withMessages([$msg]);

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->messages);
        $this->assertSame([$msg], $b->messages);
    }

    public function testWithToolsReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $tool = ['name' => 'test', 'description' => 'A test tool'];
        $b = $a->withTools([$tool]);

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->tools);
        $this->assertSame([$tool], $b->tools);
    }

    public function testWithPaneReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withPane(Pane::Skills);

        $this->assertNotSame($a, $b);
        $this->assertSame(Pane::Chat, $a->pane);
        $this->assertSame(Pane::Skills, $b->pane);
    }

    public function testWithErrorReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withError('Something went wrong');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->error);
        $this->assertSame('Something went wrong', $b->error);
    }

    public function testWithErrorCanSetNull(): void
    {
        $a = App::new($this->provider, 'gpt-4')->withError('error');
        $b = $a->withError(null);

        $this->assertNotSame($a, $b);
        $this->assertSame('error', $a->error);
        $this->assertNull($b->error);
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withStatus('Processing...');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->status);
        $this->assertSame('Processing...', $b->status);
    }

    public function testWithStatusCanSetNull(): void
    {
        $a = App::new($this->provider, 'gpt-4')->withStatus('Done');
        $b = $a->withStatus(null);

        $this->assertNotSame($a, $b);
        $this->assertSame('Done', $a->status);
        $this->assertNull($b->status);
    }

    public function testWithSessionIdReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $b = $a->withSessionId('session-123');

        $this->assertNotSame($a, $b);
        $this->assertNull($a->sessionId);
        $this->assertSame('session-123', $b->sessionId);
    }

    public function testWithContextFilesReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $files = ['file1.php', 'file2.php'];
        $b = $a->withContextFiles($files);

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->contextFiles);
        $this->assertSame($files, $b->contextFiles);
    }

    public function testWithEnabledSkillsReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $skills = ['skill1', 'skill2'];
        $b = $a->withEnabledSkills($skills);

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->enabledSkills);
        $this->assertSame($skills, $b->enabledSkills);
    }

    public function testWithActiveHooksReturnsNewInstance(): void
    {
        $a = App::new($this->provider, 'gpt-4');
        $hooks = ['pre', 'post'];
        $b = $a->withActiveHooks($hooks);

        $this->assertNotSame($a, $b);
        $this->assertSame([], $a->activeHooks);
        $this->assertSame($hooks, $b->activeHooks);
    }

    // =========================================================================
    // withMessage() Tests
    // =========================================================================

    public function testWithMessageAppendsMessageCorrectly(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg1 = new UserMessage('First message');
        $msg2 = new UserMessage('Second message');

        $app2 = $app->withMessage($msg1);
        $app3 = $app2->withMessage($msg2);

        $this->assertSame([], $app->messages);
        $this->assertSame([$msg1], $app2->messages);
        $this->assertSame([$msg1, $msg2], $app3->messages);
    }

    public function testWithMessageReturnsNewInstance(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new UserMessage('Hello');

        $app2 = $app->withMessage($msg);

        $this->assertNotSame($app, $app2);
        $this->assertSame([], $app->messages);
    }

    // =========================================================================
    // update() Tests - 5 Msg Types
    // =========================================================================

    public function testUpdateHandlesUserInputMsg(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new UserInputMsg('Hello, world!');

        [$nextApp, $cmd] = $app->update($msg);

        // App state updated with new user message
        $this->assertCount(1, $nextApp->messages);
        $this->assertInstanceOf(UserMessage::class, $nextApp->messages[0]);
        $this->assertSame('Hello, world!', $nextApp->messages[0]->content());

        // Returns RunCompletionCmd
        $this->assertInstanceOf(RunCompletionCmd::class, $cmd);
        $this->assertInstanceOf(Message::class, $cmd->userMessage);
    }

    public function testUpdateHandlesSelectPaneMsg(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $app = $app->withError('Previous error');
        $msg = new SelectPaneMsg(Pane::Skills);

        [$nextApp, $cmd] = $app->update($msg);

        // Pane changed and error cleared
        $this->assertSame(Pane::Skills, $nextApp->pane);
        $this->assertNull($nextApp->error);
        $this->assertNull($cmd);
    }

    public function testUpdateHandlesToolResultMsg(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new ToolResultMsg('call_123', 'Tool result content', false);

        [$nextApp, $cmd] = $app->update($msg);

        // Message appended
        $this->assertCount(1, $nextApp->messages);
        $this->assertInstanceOf(\SugarCraft\Crush\Messages\ToolResultMessage::class, $nextApp->messages[0]);
        $this->assertSame('call_123', $nextApp->messages[0]->toolCallId());
        $this->assertSame('Tool result content', $nextApp->messages[0]->content());

        // No cmd returned
        $this->assertNull($cmd);
    }

    public function testUpdateHandlesToolResultMsgWithError(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new ToolResultMsg('call_456', 'Error occurred', true);

        [$nextApp, $cmd] = $app->update($msg);

        $this->assertCount(1, $nextApp->messages);
        $this->assertTrue($nextApp->messages[0]->isError());
        $this->assertNull($cmd);
    }

    public function testUpdateHandlesErrorMsg(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new ErrorMsg('Something went wrong');

        [$nextApp, $cmd] = $app->update($msg);

        // Error set
        $this->assertSame('Something went wrong', $nextApp->error);
        $this->assertNull($cmd);
    }

    public function testUpdateHandlesStatusMsg(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new StatusMsg('Processing your request...');

        [$nextApp, $cmd] = $app->update($msg);

        // Status set
        $this->assertSame('Processing your request...', $nextApp->status);
        $this->assertNull($cmd);
    }

    // =========================================================================
    // Msg Construction Tests - All 5 Types
    // =========================================================================

    public function testUserInputMsgConstruction(): void
    {
        $msg = new UserInputMsg('Test input');

        $this->assertSame('Test input', $msg->content);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testSelectPaneMsgConstruction(): void
    {
        $msg = new SelectPaneMsg(Pane::Menu);

        $this->assertSame(Pane::Menu, $msg->pane);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testToolResultMsgConstruction(): void
    {
        $msg = new ToolResultMsg('call_abc', 'result content', true);

        $this->assertSame('call_abc', $msg->toolCallId);
        $this->assertSame('result content', $msg->content);
        $this->assertTrue($msg->isError);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testToolResultMsgConstructionWithDefaultIsError(): void
    {
        $msg = new ToolResultMsg('call_xyz', 'success');

        $this->assertFalse($msg->isError);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testErrorMsgConstruction(): void
    {
        $msg = new ErrorMsg('Error message');

        $this->assertSame('Error message', $msg->message);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testStatusMsgConstruction(): void
    {
        $msg = new StatusMsg('Status message');

        $this->assertSame('Status message', $msg->message);
        $this->assertInstanceOf(Msg::class, $msg);
    }

    // =========================================================================
    // Cmd Construction Tests - Both Types
    // =========================================================================

    public function testRunCompletionCmdConstruction(): void
    {
        $userMsg = new UserMessage('Test');
        $cmd = new RunCompletionCmd($userMsg);

        $this->assertSame($userMsg, $cmd->userMessage);
        $this->assertInstanceOf(Cmd::class, $cmd);
    }

    public function testCallToolCmdConstruction(): void
    {
        $args = ['param1' => 'value1', 'param2' => 42];
        $cmd = new CallToolCmd('my_tool', $args);

        $this->assertSame('my_tool', $cmd->toolName);
        $this->assertSame($args, $cmd->args);
        $this->assertInstanceOf(Cmd::class, $cmd);
    }

    public function testCallToolCmdWithEmptyArgs(): void
    {
        $cmd = new CallToolCmd('no_args_tool', []);

        $this->assertSame('no_args_tool', $cmd->toolName);
        $this->assertSame([], $cmd->args);
    }

    // =========================================================================
    // Chained with*() Tests
    // =========================================================================

    public function testChainedWithMethodsWorkCorrectly(): void
    {
        $app = App::new($this->provider, 'gpt-4')
            ->withSessionId('session-abc')
            ->withPane(Pane::Skills)
            ->withStatus('Ready');

        $this->assertSame('session-abc', $app->sessionId);
        $this->assertSame(Pane::Skills, $app->pane);
        $this->assertSame('Ready', $app->status);
        $this->assertSame('gpt-4', $app->model); // Unchanged from new()
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testEmptyMessageContent(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $msg = new UserInputMsg('');

        [$nextApp, $cmd] = $app->update($msg);

        $this->assertCount(1, $nextApp->messages);
        $this->assertSame('', $nextApp->messages[0]->content());
    }

    public function testMultiplePaneChangesPreserveOtherState(): void
    {
        $app = App::new($this->provider, 'gpt-4')
            ->withError('error')
            ->withStatus('status');

        $app = $app->update(new SelectPaneMsg(Pane::Agents))[0];

        $this->assertSame(Pane::Agents, $app->pane);
        $this->assertNull($app->error);
        $this->assertSame('status', $app->status);
    }

    public function testMultipleMessagesAccumulate(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $app = $app->update(new UserInputMsg('First'))[0];
        $app = $app->update(new UserInputMsg('Second'))[0];
        $app = $app->update(new ToolResultMsg('call_1', 'Result 1'))[0];

        $this->assertCount(3, $app->messages);
    }
}
