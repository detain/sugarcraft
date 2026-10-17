<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-077: with `titleModel` unset the auxiliary backend used to land on the
 * provider block's own default - Sonnet on anthropic, gpt-4o on openai - so a
 * user who deliberately chose Haiku for the conversation still paid the
 * conversation-class rate for every session title, `/btw` line and goal
 * judgement. The resolution order is now:
 *
 *   $CANDY_CODE_TITLE_MODEL → titleModel config key → the type's cheap
 *   auxiliary default (Haiku-class / mini, where one exists) → block model.
 *
 * The summary backend keeps its own semantics: it passes the conversation's
 * model as `$defaultModel` BEFORE the cheap tier, because cache reuse
 * requires the same model that produced the cached prefix.
 */
final class TitleBackendAuxiliaryModelTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $sandboxHome = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot FIRST, then re-arm after the trait's ENV_KEYS loop - the
        // lane-cf contract. Then clear every input this family reads.
        $dir = sys_get_temp_dir() . '/provb-aux-home-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->sandboxHome = $this->useHomeSandbox($dir);

        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_MODEL');
        putenv('CANDY_CODE_TITLE_MODEL');
        putenv('CANDY_CODE_SUMMARY_MODEL');
        $this->useHomeSandbox($this->sandboxHome);
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_MODEL');
        putenv('CANDY_CODE_TITLE_MODEL');
        putenv('CANDY_CODE_SUMMARY_MODEL');
        $this->restoreHomeSandbox();
        if ($this->sandboxHome !== null && is_dir($this->sandboxHome)) {
            @rmdir($this->sandboxHome);
        }

        parent::tearDown();
    }

    public function testTheAuxiliaryRosterNamesTheCheapModels(): void
    {
        $this->assertSame(
            ['claude-haiku-4-5', 'claude-haiku-4-5', 'us.anthropic.claude-haiku-4-5', 'gpt-4o-mini'],
            [
                ProviderFactory::auxiliaryModelFor('anthropic'),
                ProviderFactory::auxiliaryModelFor('vertex'),
                ProviderFactory::auxiliaryModelFor('bedrock'),
                ProviderFactory::auxiliaryModelFor('openai'),
            ],
        );

        // No sibling id we can promise a local/subscription endpoint serves.
        $this->assertNull(ProviderFactory::auxiliaryModelFor('sglang'));
        $this->assertNull(ProviderFactory::auxiliaryModelFor('custom'));
        $this->assertNull(ProviderFactory::auxiliaryModelFor('claude-code'));
        $this->assertNull(ProviderFactory::auxiliaryModelFor('nonsense'));
    }

    public function testTheTitleModelEnvironmentVariableStillWins(): void
    {
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');
        putenv('CANDY_CODE_TITLE_MODEL=explicit-tiny');

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('explicit-tiny', $backend->model());
    }

    public function testTheTitleModelConfigKeyStillWins(): void
    {
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');
        Bootstrap::writeUserConfig(['titleModel' => 'config-cheap']);

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('config-cheap', $backend->model());
    }

    public function testAnAnthropicTitleFallsToHaikuNotTheBlockModel(): void
    {
        // The regression itself: no titleModel anywhere, block default
        // claude-sonnet-4-6.
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');

        $blockDefault = (new ProviderFactory())->defaultConfig('anthropic')['model'];
        $this->assertSame('claude-sonnet-4-6', $blockDefault);

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('claude-haiku-4-5', $backend->model());
    }

    public function testAnOpenAiTitleFallsToMiniNotTheBlockModel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=sk-test');

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('gpt-4o-mini', $backend->model());
        $this->assertNotSame(
            (new ProviderFactory())->defaultConfig('openai')['model'],
            $backend->model(),
        );
    }

    public function testASglangTitleKeepsTheBlockModelBecauseThereIsNoCheapSibling(): void
    {
        putenv('CANDY_CODE_PROVIDER=sglang');

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        // sglang is not in the roster: the block model is the only name a
        // local server can be promised to serve.
        $this->assertSame(
            (new ProviderFactory())->defaultConfig('sglang')['model'],
            $backend->model(),
        );
    }

    public function testTheSummaryBackendConversationModelOutranksTheCheapTier(): void
    {
        // summaryBackend() passes the conversation model as $defaultModel.
        // That argument must beat the auxiliary default, or cache reuse would
        // silently break the same-model requirement.
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');

        $backend = self::toollessWithDefaultModel('conversation-model');

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame('conversation-model', $backend->model());
    }

    /**
     * Drive the private shared constructor exactly as summaryBackend() does,
     * without needing a full main engine for the cache-reuse wrapper.
     */
    private static function toollessWithDefaultModel(string $defaultModel): mixed
    {
        $method = new \ReflectionMethod(Bootstrap::class, 'toollessBackend');

        return $method->invoke(null, 'CANDY_CODE_SUMMARY_MODEL', 'summaryModel', $defaultModel);
    }
}
