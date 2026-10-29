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
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\Commands\FastHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\FallbackProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use OpenAI\Contracts\ClientContract;

/**
 * C-PRV-3.b: `/fast` is the premium service-lane dial, and the pair the
 * engine CARRIES is the only hand it will turn — the same total-contract
 * discipline as the effort suite above it, because the banned failure is the
 * same one: a saved WORD no wire reads, or a live value the next launch
 * forgets. Every set here asserts BOTH halves (live overlay and user-tier
 * save), every refusal asserts NEITHER half lands, and `off` asserts the
 * affirmative 'default' save that the design carries in place of a delete.
 *
 * The eligibility question is asked of the wrapper too, exactly as the launch
 * door asks it: a `fallback(...)` pair is refused with the wrapper named —
 * the primary under it may shape the lane perfectly well, and the leg that
 * is missing belongs to the wrapper, not to it.
 */
final class FastCommandTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $root = '';

    /** @var string|false */
    private string|false $originalAnthropicKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/candy_code_fast_' . uniqid('', true);
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
        $this->removeFastTree($this->root);

        parent::tearDown();
    }

    public function testOnAppliesTheLaneLiveAndSavesTheWordOnce(): void
    {
        $after = $this->drive($this->fastChat(), '/fast on');

        $this->assertSame('fast', $this->engineOf($after)->serviceTier());
        $this->assertSame('fast', Bootstrap::readUserConfig()['serviceTier'] ?? null);
        $this->assertFastReply('Fast mode ON on anthropic for claude-opus-5', $after);
        $this->assertFastReply('saved as serviceTier', $after);
    }

    public function testOffSavesTheAffirmativeDefaultNotADelete(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'fast']);
        $after = $this->drive($this->fastChat(), '/fast off');

        $this->assertSame('default', $this->engineOf($after)->serviceTier());
        $this->assertSame('default', Bootstrap::readUserConfig()['serviceTier'] ?? null,
            'an explicit standard lane is kept in the user tier — there is no third state to hand back');
        $this->assertFastReply('Fast mode OFF on anthropic', $after);
    }

    public function testTheTokenIsCasewiseAndTrimmedLikeTheLaunchResolver(): void
    {
        $after = $this->drive($this->fastChat(), '/fast   ON ');

        $this->assertSame('fast', $this->engineOf($after)->serviceTier(), 'the stored word is the enum spelling, not the typing');
        $this->assertSame('fast', Bootstrap::readUserConfig()['serviceTier'] ?? null);
    }

    public function testOnOnAnOffRosterModelRefusesAndLandsNowhereAtAll(): void
    {
        // The provider is on the roster; the MODEL is not (fast mode gates by
        // family). The refusal names the pair, and neither half lands.
        $chat = $this->fastChat(model: 'claude-sonnet-4-6');
        $after = $this->drive($chat, '/fast on');

        $this->assertNull($this->engineOf($after)->serviceTier());
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertFastReply('anthropic sends no premium lane for claude-sonnet-4-6', $after);
    }

    public function testOnOnANonShaperRefusesAndLandsNowhereAtAll(): void
    {
        // Echo has no service-tier control at all: a half-door that saved the
        // word and claimed it would be the set-but-ignored lie the launch
        // door exists to ban.
        $after = $this->drive($this->fastChat(echoing: true), '/fast on');

        $this->assertNull($this->engineOf($after)->serviceTier());
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertFastReply('has no service-tier lane, so /fast changed nothing', $after);
    }

    public function testOnArmsThePriorityLaneOnOpenAiCodeModelsToo(): void
    {
        // The roster is not Anthropic-shaped: the OpenAI implementation hands
        // a tier for every model but Azure, and the command asks the pair it
        // carries — never a hard-coded provider name.
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        $chat = new Chat(
            backend: EngineBackend::new($provider, 'gpt-4o'),
            projectRoot: $this->root . '/project',
            workspace: $this->writerWorkspace(),
        );

        $after = $this->drive($chat, '/fast on');

        $this->assertSame('fast', $this->engineOf($after)->serviceTier());
        $this->assertSame('fast', Bootstrap::readUserConfig()['serviceTier'] ?? null);
        $this->assertFastReply('Fast mode ON on openai for gpt-4o', $after);
    }

    public function testOnOnAFallbackWrapperRefusesNamingTheWrapperLeg(): void
    {
        // The wrapped primary shapes the lane; the wrapper does not, and the
        // wrapper is what the engine carries. Naming the primary here would
        // accuse a wire that does have the field — the door's argument, kept
        // verbatim in the command.
        $primary = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5');
        $wrapper = FallbackProvider::new($primary, 'claude-opus-5', [
            'gpt-4o' => static fn (): ProviderInterface => new EchoProvider(),
        ]);

        $chat = new Chat(
            backend: EngineBackend::new($wrapper, 'claude-opus-5'),
            projectRoot: $this->root . '/project',
            workspace: $this->writerWorkspace(),
        );
        $after = $this->drive($chat, '/fast on');

        $this->assertNull($this->engineOf($after)->serviceTier());
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertFastReply('fallback(anthropic) has no service-tier lane', $after,
            'the accusation lands on the leg that is missing, not on the primary under it');
        $this->assertStringNotContainsString('anthropic sends', $this->replyOf($after));
    }

    public function testAnUnknownTokenAnswersTheUsageAndChangesNothing(): void
    {
        $after = $this->drive($this->fastChat(), '/fast turbo');

        $this->assertNull($this->engineOf($after)->serviceTier());
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertFastReply('Usage: /fast <on|off>', $after);
    }

    public function testATwoWordArgumentAnswersTheUsageToo(): void
    {
        $after = $this->drive($this->fastChat(), '/fast on now');

        $this->assertNull($this->engineOf($after)->serviceTier());
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertFastReply('Usage: /fast <on|off>', $after);
    }

    public function testBareStatusOnAnUnarmedShapedPairSaysOffAndEligible(): void
    {
        $after = $this->drive($this->fastChat(), '/fast');

        $this->assertFastReply('Fast mode: OFF on anthropic — unset; this pair shapes a premium lane, so /fast on would arm it.', $after);
    }

    public function testBareStatusReadsTheLiveOverlayAfterASet(): void
    {
        $after = $this->drive($this->drive($this->fastChat(), '/fast on'), '/fast');

        $this->assertFastReply('Fast mode: ON on anthropic for claude-opus-5 — the premium lane is armed.', $after);
    }

    public function testBareStatusOnAnExplicitDefaultSaysSavedSo(): void
    {
        $after = $this->drive($this->drive($this->fastChat(), '/fast off'), '/fast');

        $this->assertFastReply('Fast mode: OFF on anthropic — the standard lane, saved explicitly.', $after);
    }

    public function testBareStatusOnAnUnshapedPairAnnouncesTheRefusalAhead(): void
    {
        $after = $this->drive($this->fastChat(model: 'claude-sonnet-4-6'), '/fast');

        $this->assertFastReply('Fast mode: OFF on anthropic — unset; this pair shapes no premium lane, so /fast on would refuse.', $after);
    }

    public function testWithoutAWriterTheLaneStillTurnsForThisSession(): void
    {
        $after = $this->drive($this->fastChat(writer: false), '/fast on');

        $this->assertSame('fast', $this->engineOf($after)->serviceTier());
        $this->assertFastReply('this session only', $after);
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig(), 'and nothing was written behind the report');
    }

    public function testASessionWithNoEngineBackendIsToldPlainly(): void
    {
        $chat = new Chat(backend: new EchoBackend(), projectRoot: $this->root . '/project');

        $bare = $this->drive($chat, '/fast');
        $this->assertFastReply('no engine backend', $bare);

        $set = $this->drive($chat, '/fast on');
        $this->assertFastReply('this backend has no service-tier lane', $set);
        $this->assertArrayNotHasKey('serviceTier', Bootstrap::readUserConfig());
    }

    public function testOffPersistsEvenWithoutAnEngineToOverlay(): void
    {
        // The save is the ask; the live leg is optional — the split `/effort
        // auto` already makes. A future launch on a shaping pair starts on the
        // lane this session was told to leave.
        $chat = new Chat(
            backend: new EchoBackend(),
            projectRoot: $this->root . '/project',
            workspace: $this->writerWorkspace(),
        );
        $after = $this->drive($chat, '/fast off');

        $this->assertArrayHasKey('serviceTier', Bootstrap::readUserConfig());
        $this->assertSame('default', Bootstrap::readUserConfig()['serviceTier']);
        $this->assertFastReply('Fast mode OFF on this backend', $after);
    }

    public function testTheDialTalkIsUiOnlyRows(): void
    {
        $before = count($this->fastChat()->history);
        $after = $this->drive($this->fastChat(), '/fast on');
        $rows = array_slice($after->history, $before);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->uiOnly, 'the dial talk never rides the transcript to the model');
        }
    }

    public function testTheCommandIsRegisteredWithBothSurfaces(): void
    {
        $rows = array_values(array_filter(
            BuiltInCommands::all(),
            static fn ($row): bool => $row->spec->name === 'fast',
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('handleFastCommand', $rows[0]->handler);
        $this->assertSame(CommandArguments::Parsed, $rows[0]->arguments);
        $this->assertSame(FastHostCommand::class, $rows[0]->hostCommand);
        $this->assertSame('[on|off]', $rows[0]->spec->argumentHint);
    }

    // ---- harness ----------------------------------------------------------

    private function fastChat(bool $echoing = false, bool $writer = true, string $model = 'claude-opus-5'): Chat
    {
        $provider = $echoing
            ? new EchoProvider()
            : new AnthropicProvider('sk-test', 'https://api.anthropic.com', $model);

        return new Chat(
            backend: EngineBackend::new($provider, $model),
            projectRoot: $this->root . '/project',
            workspace: $writer ? $this->writerWorkspace() : null,
        );
    }

    private function writerWorkspace(): WorkspaceContext
    {
        return WorkspaceContext::new(root: $this->root . '/project')->withService(
            SettingsWriter::class,
            SettingsWriter::new(
                Bootstrap::userConfigPath(),
                static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
            ),
        );
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

    private function assertFastReply(string $needle, Chat $chat, string $why = ''): void
    {
        $this->assertStringContainsString($needle, $this->replyOf($chat), 'reply was: ' . $this->replyOf($chat) . ($why === '' ? '' : ' — ' . $why));
    }

    private function removeFastTree(string $dir): void
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
