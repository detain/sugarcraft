<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookConfig;

/**
 * @see HookConfig
 */
final class HookConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hook_config_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    // =========================================================================
    // loadFromFile Tests
    // =========================================================================

    public function testLoadFromFileNotFound(): void
    {
        $result = HookConfig::loadFromFile('/nonexistent/path/hooks.yaml');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLoadFromFileReturnsEmptyOnReadFailure(): void
    {
        // Create a file that exists but is not readable (if we can control perms)
        // Since we run as same user, just use invalid path
        $result = HookConfig::loadFromFile('/dev/null/hooks.yaml');

        $this->assertIsArray($result);
    }

    // =========================================================================
    // parse Tests - Valid YAML
    // =========================================================================

    public function testParseValidYaml(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^Read$'
      command: 'echo allowed'
      description: 'Allow read operations'
    - matcher: '^Write$'
      command: 'echo allowed'
      description: 'Allow write operations'
  PostToolUse:
    - matcher: '.*'
      command: 'echo post'
      description: 'Log all operations'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(3, $result);

        // First PreToolUse hook
        $this->assertSame('PreToolUse', $result[0]['event']);
        $this->assertSame('^Read$', $result[0]['matcher']);
        $this->assertSame('echo allowed', $result[0]['command']);
        $this->assertSame('Allow read operations', $result[0]['description']);

        // Second PreToolUse hook
        $this->assertSame('PreToolUse', $result[1]['event']);
        $this->assertSame('^Write$', $result[1]['matcher']);

        // PostToolUse hook
        $this->assertSame('PostToolUse', $result[2]['event']);
        $this->assertSame('.*', $result[2]['matcher']);
    }

    public function testParseWithDefaults(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - command: 'my_script.sh'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('PreToolUse', $result[0]['event']);
        $this->assertSame('.*', $result[0]['matcher']); // default
        $this->assertSame('my_script.sh', $result[0]['command']);
        $this->assertSame('', $result[0]['description']); // default
    }

    // =========================================================================
    // parse Tests - Empty/Null Cases
    // =========================================================================

    public function testParseEmptyHooks(): void
    {
        $yaml = <<<'YAML'
hooks: {}
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseNoHooksKey(): void
    {
        $yaml = <<<'YAML'
some_other_key: value
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseEmptyYaml(): void
    {
        $result = HookConfig::parse('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseNullLikeValue(): void
    {
        $result = HookConfig::parse('null');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // parse Tests - Invalid YAML
    // =========================================================================

    public function testParseInvalidYaml(): void
    {
        $invalidYaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^Read$
      command: [invalid array structure
YAML;

        $result = HookConfig::parse($invalidYaml);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseMalformedYaml(): void
    {
        $malformedYaml = "this is not: [yaml at all really::: invalid";

        $result = HookConfig::parse($malformedYaml);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // parse Tests - Edge Cases
    // =========================================================================

    public function testParseMultipleHooksSameEvent(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^A$'
      command: 'cmd_a'
    - matcher: '^B$'
      command: 'cmd_b'
    - matcher: '^C$'
      command: 'cmd_c'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(3, $result);
        foreach ($result as $hook) {
            $this->assertSame('PreToolUse', $hook['event']);
        }
    }

    public function testParsePreservesDescription(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '.*'
      command: 'audit.sh'
      description: 'Security audit hook'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('Security audit hook', $result[0]['description']);
    }

    public function testParseEmptyCommand(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '.*'
      command: ''
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['command']);
    }
}
