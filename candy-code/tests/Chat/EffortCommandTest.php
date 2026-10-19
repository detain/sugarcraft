<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * PRV-2 (§6.5) step 3: `/effort` is the dial, and the provider roster is the
 * only hand it will turn. Every set here asserts BOTH halves of the contract -
 * the live overlay on the engine and the user-tier save - because the banned
 * failure has always been one landing while the other silently does not: a
 * saved key no wire reads, or a live value the next launch forgets.
 */
final class EffortCommandTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $root = '';

    /** @var string|false */
    private string|false $originalAnthropicKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/candy_code_effort_' . uniqid('', true);
        mkdir($this->root . '/project', 0700, true);
        $this->useHomeSandbox($this->root . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);

        // Construction only; these tests never send a request, so no seam
        // is armed and no key is ever used past the constructor.
        $this->originalAnthropicKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=test-key');
    }

    protected function tearDown(): void
    {
        $this->originalAnthropicKey === false
            ? putenv('ANTHROPIC_API_KEY')
            : putenv('ANTHROPIC_API_KEY=' . $this->originalAnthropicKey);
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeEffortTree($this->root);

        parent::tearDown();
    }

    public function testSettingATierAppliesItLiveAndSavesItOnce(): void
    {
        $after = $this->sendEffort('/effort high', $this->engineChat());

        $this->assertSame('high', $this->engineOf($after)->reasoningEffort());
        $this->assertSame('high', Bootstrap::readUserConfig()['reasoningEffort'] ?? null);
        $this->assertEffortReply('Effort set to high on anthropic', $after);
        $this->assertStringContainsString('saved as reasoningEffort', $this->replyOf($after));
    }

    public function testTheTokenIsCasewiseAndTrimmedLikeTheLaunchResolver(): void
    {
        $after = $this->sendEffort('/effort   HIGH ', $this->engineChat());

        $this->assertSame('high', $this->engineOf($after)->reasoningEffort(), 'the wire spelling is the enum value, not the typing');
        $this->assertSame('high', Bootstrap::readUserConfig()['reasoningEffort'] ?? null);
    }

    public function testARefusedProviderLandsNowhereAtAll(): void
    {
        // Echo draws nothing - the refusal must be total: no live value, no
        // saved key, no half-door where config.json holds a tier the wire
        // ignores until some future provider reads it.
        $chat = $this->engineChat(echoing: true);
        $after = $this->sendEffort('/effort max', $chat);

        $this->assertNull($this->engineOf($after)->reasoningEffort());
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
        $this->assertEffortReply('no reasoning-effort control', $after);
    }

    public function testAnUnknownTokenAnswersTheUsageAndChangesNothing(): void
    {
        $after = $this->sendEffort('/effort turbo', $this->engineChat());

        $this->assertNull($this->engineOf($after)->reasoningEffort());
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
        $this->assertEffortReply('Usage: /effort <low|medium|high|xhigh|max>, or /effort auto', $after);
    }

    public function testBareStatusReadsTheLiveOverlayAndNamesTheSpend(): void
    {
        $after = $this->sendEffort('/effort', $this->sendEffort('/effort high', $this->engineChat()));

        $reply = $this->replyOf($after);
        $this->assertStringContainsString('Reasoning effort: high on anthropic', $reply);
        $this->assertStringContainsString('8192 tokens', $reply, 'the status line tells the truth about where the tier goes');
    }

    public function testBareStatusOnAnUndialedEngineSaysUnset(): void
    {
        $after = $this->sendEffort('/effort', $this->engineChat());

        $this->assertEffortReply('Reasoning effort: unset on anthropic. Nothing asked', $after);
    }

    public function testAutoDeletesTheKeyInsteadOfWritingAnEmptyOne(): void
    {
        Bootstrap::writeUserConfig(['reasoningEffort' => 'medium']);
        $after = $this->sendEffort('/effort auto', $this->engineChat());

        $this->assertNull($this->engineOf($after)->reasoningEffort(), 'the live overlay clears with the save');
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig(), 'a reset deletes - an explicit null would outrank every lower layer');
        $this->assertEffortReply('Effort cleared on anthropic', $after);
    }

    public function testAutoClearsTheLiveOverlayEvenWithNothingSaved(): void
    {
        $after = $this->sendEffort('/effort auto', $this->sendEffort('/effort low', $this->engineChat(echoWriter: false)));

        $this->assertNull($this->engineOf($after)->reasoningEffort());
        $this->assertEffortReply('Effort cleared', $after);
    }

    public function testWithoutAWriterTheDialStillTurnsForThisSession(): void
    {
        $after = $this->sendEffort('/effort xhigh', $this->engineChat(echoWriter: false));

        $this->assertSame('xhigh', $this->engineOf($after)->reasoningEffort());
        $this->assertEffortReply('this session only', $after);
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig(), 'and nothing was written behind the report');
    }

    public function testASessionWithNoEngineBackendIsToldPlainly(): void
    {
        $chat = new Chat(backend: new EchoBackend(), projectRoot: $this->root . '/project');

        $bare = $this->drive($chat, '/effort');
        $this->assertEffortReply('no engine backend', $bare);

        $set = $this->drive($chat, '/effort high');
        $this->assertEffortReply('this backend has no reasoning-effort control', $set);
        $this->assertArrayNotHasKey('reasoningEffort', Bootstrap::readUserConfig());
    }

    public function testTheTierIsUiOnlyRows(): void
    {
        $before = count($this->engineChat()->history);
        $after = $this->sendEffort('/effort high', $this->engineChat());
        $rows = array_slice($after->history, $before);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->uiOnly, 'the dial talk never rides the transcript to the model');
        }
    }

    // ---- harness ----------------------------------------------------------

    private function engineChat(bool $echoing = false, bool $echoWriter = true): Chat
    {
        $provider = $echoing
            ? new EchoProvider()
            : new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6');

        $workspace = $echoWriter
            ? WorkspaceContext::new(root: $this->root . '/project')->withService(
                SettingsWriter::class,
                SettingsWriter::new(
                    Bootstrap::userConfigPath(),
                    static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
                ),
            )
            : null;

        return new Chat(
            backend: EngineBackend::new($provider, 'claude-sonnet-4-6'),
            projectRoot: $this->root . '/project',
            workspace: $workspace,
        );
    }

    private function sendEffort(string $line, Chat $chat): Chat
    {
        return $this->drive($chat, $line);
    }

    private function drive(Chat $chat, string $line): Chat
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'withInputBuf'))->invoke($chat, $line);
        [$next] = $drafted->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    private function engineOf(Chat $chat): EngineBackend
    {
        $backend = $chat->backend();
        $this->assertInstanceOf(EngineBackend::class, $backend);

        return $backend;
    }

    private function replyOf(Chat $chat): string
    {
        $history = $chat->history;
        $last = end($history);
        $this->assertInstanceOf(Message::class, $last);

        return $last->content;
    }

    private function assertEffortReply(string $needle, Chat $chat): void
    {
        $this->assertStringContainsString($needle, $this->replyOf($chat), 'reply was: '.$this->replyOf($chat));
    }

    private function removeEffortTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
