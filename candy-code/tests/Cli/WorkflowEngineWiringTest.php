<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\WorkflowEngineWiring;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;

final class WorkflowEngineWiringTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wf-wiring-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/home', 0700, true);
        mkdir($this->dir . '/root', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->dir . '/root');
        foreach (glob($this->dir . '/home/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir . '/home');
        @rmdir($this->dir);
    }

    private function engine(): WorkflowEngine
    {
        return WorkflowEngineWiring::engine(
            new WorkflowRegistry($this->dir . '/wf'),
            $this->dir . '/root',
            new PermissionGate(PermissionMode::Default),
            'echo',
            'echo-model',
            [],
        );
    }

    public function testItBuildsAWorkflowEngine(): void
    {
        self::assertInstanceOf(WorkflowEngine::class, $this->engine());
    }

    public function testTheEngineCarriesTheDeferredBuiltInPresetRoster(): void
    {
        $names = $this->engine()->presetNames();

        self::assertIsArray($names);
        self::assertContains('coder', $names);
        self::assertContains('reviewer', $names);
    }
}
