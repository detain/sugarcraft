<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\App\AppBuilder;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;

/**
 * @see AppBuilder
 */
final class AppBuilderTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    // =========================================================================
    // Instantiation Tests
    // =========================================================================

    public function testCanBeInstantiatedWithNew(): void
    {
        $builder = new AppBuilder();

        $this->assertInstanceOf(AppBuilder::class, $builder);
    }

    // =========================================================================
    // Default Values Tests
    // =========================================================================

    public function testDefaultValuesAreCorrect(): void
    {
        $builder = new AppBuilder();

        // Use reflection to access private properties
        $reflection = new \ReflectionClass(AppBuilder::class);

        $providerProp = $reflection->getProperty('provider');
        $providerProp->setAccessible(true);
        $this->assertNull($providerProp->getValue($builder));

        $modelProp = $reflection->getProperty('model');
        $modelProp->setAccessible(true);
        $this->assertSame('claude-sonnet-4-6', $modelProp->getValue($builder));

        $messagesProp = $reflection->getProperty('messages');
        $messagesProp->setAccessible(true);
        $this->assertSame([], $messagesProp->getValue($builder));

        $toolsProp = $reflection->getProperty('tools');
        $toolsProp->setAccessible(true);
        $this->assertSame([], $toolsProp->getValue($builder));

        $paneProp = $reflection->getProperty('pane');
        $paneProp->setAccessible(true);
        $this->assertSame(Pane::Chat, $paneProp->getValue($builder));

        $errorProp = $reflection->getProperty('error');
        $errorProp->setAccessible(true);
        $this->assertNull($errorProp->getValue($builder));

        $statusProp = $reflection->getProperty('status');
        $statusProp->setAccessible(true);
        $this->assertNull($statusProp->getValue($builder));

        $sessionIdProp = $reflection->getProperty('sessionId');
        $sessionIdProp->setAccessible(true);
        $this->assertNull($sessionIdProp->getValue($builder));

        $contextFilesProp = $reflection->getProperty('contextFiles');
        $contextFilesProp->setAccessible(true);
        $this->assertSame([], $contextFilesProp->getValue($builder));

        $enabledSkillsProp = $reflection->getProperty('enabledSkills');
        $enabledSkillsProp->setAccessible(true);
        $this->assertSame([], $enabledSkillsProp->getValue($builder));

        $activeHooksProp = $reflection->getProperty('activeHooks');
        $activeHooksProp->setAccessible(true);
        $this->assertSame([], $activeHooksProp->getValue($builder));
    }

    // =========================================================================
    // withProvider() Tests
    // =========================================================================

    public function testWithProviderReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withProvider($this->provider);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithProviderSetsProviderCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withProvider($this->provider);

        // Original should be unchanged
        $reflection = new \ReflectionClass(AppBuilder::class);
        $providerProp = $reflection->getProperty('provider');
        $providerProp->setAccessible(true);

        $this->assertNull($providerProp->getValue($builder));
        $this->assertSame($this->provider, $providerProp->getValue($builder2));
    }

    // =========================================================================
    // withModel() Tests
    // =========================================================================

    public function testWithModelReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withModel('gpt-4');

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithModelSetsModelCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withModel('claude-3-opus');

        $reflection = new \ReflectionClass(AppBuilder::class);
        $modelProp = $reflection->getProperty('model');
        $modelProp->setAccessible(true);

        $this->assertSame('claude-sonnet-4-6', $modelProp->getValue($builder));
        $this->assertSame('claude-3-opus', $modelProp->getValue($builder2));
    }

    // =========================================================================
    // withMessages() Tests
    // =========================================================================

    public function testWithMessagesReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withMessages(['hello']);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithMessagesSetsMessagesCorrectly(): void
    {
        $messages = ['msg1', 'msg2'];
        $builder = new AppBuilder();
        $builder2 = $builder->withMessages($messages);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $messagesProp = $reflection->getProperty('messages');
        $messagesProp->setAccessible(true);

        $this->assertSame([], $messagesProp->getValue($builder));
        $this->assertSame($messages, $messagesProp->getValue($builder2));
    }

    // =========================================================================
    // withTools() Tests
    // =========================================================================

    public function testWithToolsReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withTools([]);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithToolsSetsToolsCorrectly(): void
    {
        $tools = [['name' => 'tool1', 'description' => 'A tool']];
        $builder = new AppBuilder();
        $builder2 = $builder->withTools($tools);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $toolsProp = $reflection->getProperty('tools');
        $toolsProp->setAccessible(true);

        $this->assertSame([], $toolsProp->getValue($builder));
        $this->assertSame($tools, $toolsProp->getValue($builder2));
    }

    // =========================================================================
    // withPane() Tests
    // =========================================================================

    public function testWithPaneReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withPane(Pane::Skills);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithPaneSetsPaneCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withPane(Pane::Menu);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $paneProp = $reflection->getProperty('pane');
        $paneProp->setAccessible(true);

        $this->assertSame(Pane::Chat, $paneProp->getValue($builder));
        $this->assertSame(Pane::Menu, $paneProp->getValue($builder2));
    }

    // =========================================================================
    // withError() Tests
    // =========================================================================

    public function testWithErrorReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withError('some error');

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithErrorSetsErrorCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withError('Something went wrong');

        $reflection = new \ReflectionClass(AppBuilder::class);
        $errorProp = $reflection->getProperty('error');
        $errorProp->setAccessible(true);

        $this->assertNull($errorProp->getValue($builder));
        $this->assertSame('Something went wrong', $errorProp->getValue($builder2));
    }

    public function testWithErrorCanSetNull(): void
    {
        $builder = (new AppBuilder())->withError('error');
        $builder2 = $builder->withError(null);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $errorProp = $reflection->getProperty('error');
        $errorProp->setAccessible(true);

        $this->assertSame('error', $errorProp->getValue($builder));
        $this->assertNull($errorProp->getValue($builder2));
    }

    // =========================================================================
    // withStatus() Tests
    // =========================================================================

    public function testWithStatusReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withStatus('ready');

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithStatusSetsStatusCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withStatus('Processing...');

        $reflection = new \ReflectionClass(AppBuilder::class);
        $statusProp = $reflection->getProperty('status');
        $statusProp->setAccessible(true);

        $this->assertNull($statusProp->getValue($builder));
        $this->assertSame('Processing...', $statusProp->getValue($builder2));
    }

    public function testWithStatusCanSetNull(): void
    {
        $builder = (new AppBuilder())->withStatus('done');
        $builder2 = $builder->withStatus(null);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $statusProp = $reflection->getProperty('status');
        $statusProp->setAccessible(true);

        $this->assertSame('done', $statusProp->getValue($builder));
        $this->assertNull($statusProp->getValue($builder2));
    }

    // =========================================================================
    // withSessionId() Tests
    // =========================================================================

    public function testWithSessionIdReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withSessionId('session-123');

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithSessionIdSetsSessionIdCorrectly(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withSessionId('session-abc');

        $reflection = new \ReflectionClass(AppBuilder::class);
        $sessionIdProp = $reflection->getProperty('sessionId');
        $sessionIdProp->setAccessible(true);

        $this->assertNull($sessionIdProp->getValue($builder));
        $this->assertSame('session-abc', $sessionIdProp->getValue($builder2));
    }

    // =========================================================================
    // withContextFiles() Tests
    // =========================================================================

    public function testWithContextFilesReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withContextFiles(['file.php']);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithContextFilesSetsContextFilesCorrectly(): void
    {
        $files = ['file1.php', 'file2.php'];
        $builder = new AppBuilder();
        $builder2 = $builder->withContextFiles($files);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $contextFilesProp = $reflection->getProperty('contextFiles');
        $contextFilesProp->setAccessible(true);

        $this->assertSame([], $contextFilesProp->getValue($builder));
        $this->assertSame($files, $contextFilesProp->getValue($builder2));
    }

    // =========================================================================
    // withEnabledSkills() Tests
    // =========================================================================

    public function testWithEnabledSkillsReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withEnabledSkills(['skill1']);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithEnabledSkillsSetsEnabledSkillsCorrectly(): void
    {
        $skills = ['skill1', 'skill2'];
        $builder = new AppBuilder();
        $builder2 = $builder->withEnabledSkills($skills);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $enabledSkillsProp = $reflection->getProperty('enabledSkills');
        $enabledSkillsProp->setAccessible(true);

        $this->assertSame([], $enabledSkillsProp->getValue($builder));
        $this->assertSame($skills, $enabledSkillsProp->getValue($builder2));
    }

    // =========================================================================
    // withActiveHooks() Tests
    // =========================================================================

    public function testWithActiveHooksReturnsAppBuilderInstance(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withActiveHooks(['hook1']);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testWithActiveHooksSetsActiveHooksCorrectly(): void
    {
        $hooks = ['pre_hook', 'post_hook'];
        $builder = new AppBuilder();
        $builder2 = $builder->withActiveHooks($hooks);

        $reflection = new \ReflectionClass(AppBuilder::class);
        $activeHooksProp = $reflection->getProperty('activeHooks');
        $activeHooksProp->setAccessible(true);

        $this->assertSame([], $activeHooksProp->getValue($builder));
        $this->assertSame($hooks, $activeHooksProp->getValue($builder2));
    }

    // =========================================================================
    // Fluent Builder / Chaining Tests
    // =========================================================================

    public function testWithMethodsAreFluentCanChainAllMethods(): void
    {
        $builder = new AppBuilder();

        $result = $builder
            ->withProvider($this->provider)
            ->withModel('claude-3')
            ->withMessages(['msg1'])
            ->withTools([['name' => 't1']])
            ->withPane(Pane::Agents)
            ->withError('error')
            ->withStatus('status')
            ->withSessionId('session')
            ->withContextFiles(['file.php'])
            ->withEnabledSkills(['skill'])
            ->withActiveHooks(['hook']);

        $this->assertInstanceOf(AppBuilder::class, $result);
    }

    public function testChainedBuildCreatesAppWithCorrectValues(): void
    {
        $app = (new AppBuilder())
            ->withProvider($this->provider)
            ->withModel('custom-model')
            ->withMessages(['hello', 'world'])
            ->withTools([['name' => 'tool1']])
            ->withPane(Pane::Skills)
            ->withError('some error')
            ->withStatus('ready')
            ->withSessionId('session-xyz')
            ->withContextFiles(['file1.php', 'file2.php'])
            ->withEnabledSkills(['skill1', 'skill2'])
            ->withActiveHooks(['pre', 'post'])
            ->build();

        $this->assertInstanceOf(App::class, $app);
        $this->assertSame($this->provider, $app->provider);
        $this->assertSame('custom-model', $app->model);
        $this->assertSame(['hello', 'world'], $app->messages);
        $this->assertSame([['name' => 'tool1']], $app->tools);
        $this->assertSame(Pane::Skills, $app->pane);
        $this->assertSame('some error', $app->error);
        $this->assertSame('ready', $app->status);
        $this->assertSame('session-xyz', $app->sessionId);
        $this->assertSame(['file1.php', 'file2.php'], $app->contextFiles);
        $this->assertSame(['skill1', 'skill2'], $app->enabledSkills);
        $this->assertSame(['pre', 'post'], $app->activeHooks);
    }

    public function testBuildWithMinimalConfiguration(): void
    {
        // Only provider is required, all others have defaults
        $app = (new AppBuilder())
            ->withProvider($this->provider)
            ->build();

        $this->assertInstanceOf(App::class, $app);
        $this->assertSame($this->provider, $app->provider);
        $this->assertSame('claude-sonnet-4-6', $app->model);
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

    // =========================================================================
    // build() Exception Tests
    // =========================================================================

    public function testBuildThrowsLogicExceptionWhenProviderNotSet(): void
    {
        $builder = new AppBuilder();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('provider is required');

        $builder->build();
    }

    public function testBuildThrowsLogicExceptionEvenWithAllOtherPropertiesSet(): void
    {
        // Even if all other properties are set, provider is still required
        $builder = (new AppBuilder())
            ->withModel('claude-3')
            ->withMessages(['msg'])
            ->withTools([['name' => 'tool']])
            ->withPane(Pane::Skills)
            ->withError('error')
            ->withStatus('status')
            ->withSessionId('session')
            ->withContextFiles(['file.php'])
            ->withEnabledSkills(['skill'])
            ->withActiveHooks(['hook']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('provider is required');

        $builder->build();
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testWithMethodsReturnNewInstanceOriginalUnchanged(): void
    {
        $builder = new AppBuilder();
        $builder2 = $builder->withProvider($this->provider);
        $builder3 = $builder->withModel('gpt-4');

        // Original builder unchanged
        $reflection = new \ReflectionClass(AppBuilder::class);

        $providerProp = $reflection->getProperty('provider');
        $providerProp->setAccessible(true);
        $this->assertNull($providerProp->getValue($builder));

        $modelProp = $reflection->getProperty('model');
        $modelProp->setAccessible(true);
        $this->assertSame('claude-sonnet-4-6', $modelProp->getValue($builder));

        // builder2 has provider set
        $this->assertSame($this->provider, $providerProp->getValue($builder2));

        // builder3 has model set
        $this->assertSame('gpt-4', $modelProp->getValue($builder3));
    }
}
