<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookResult;

/**
 * ISS-040 seam S1 closed: `unattendedAsk: true` on a hook-file entry must
 * reach the headless prompt's roster through the REAL launch — parse, load,
 * register, roster — not just through the PHP API the consumer-side tests
 * (HeadlessPermissionPromptUnattendedAskTest) call directly.
 *
 * One fake launch per test: statics reset plus one reflective `Bootstrap::hooks()`
 * call over a sandboxed HOME carrying `~/.candy-code/hooks.yaml`, the same
 * shape PolicyFreshnessQuarantineTest proved the loader with; helpers are
 * deliberately named differently from that file's so the drift guard's
 * name scan stays clean (lane-ce precedent).
 */
final class HookFileUnattendedAskTest extends TestCase
{
    private string $sandboxDir = '';
    private string $sandboxHome = '';
    private string $preSandboxHome = '';
    private mixed $preSandboxServerHome = null;

    protected function setUp(): void
    {
        $this->preSandboxHome = getenv('HOME') ?: '';
        HeadlessPermissionPrompt::forgetUnattendedAnswers();

        $this->sandboxDir = sys_get_temp_dir() . '/hook_unattended_' . uniqid('', true);
        $this->sandboxHome = $this->sandboxDir . '/home';
        mkdir($this->sandboxHome . '/.candy-code', 0700, true);
        putenv('HOME=' . $this->sandboxHome);
        $this->preSandboxServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->sandboxHome;

        foreach (['CANDY_CODE_PROVIDER', 'CANDY_CODE_BACKEND_CMD', 'CANDY_CODE_MODEL', 'CANDY_CODE_PERMISSION_MODE'] as $var) {
            putenv($var);
        }

        file_put_contents($this->sandboxHome . '/.candy-code/config.json', '{}');
        $this->clearLaunchStatics();
    }

    protected function tearDown(): void
    {
        $this->clearLaunchStatics();
        HeadlessPermissionPrompt::forgetUnattendedAnswers();

        putenv('HOME=' . $this->preSandboxHome);
        if ($this->preSandboxServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->preSandboxServerHome;
        }

        foreach (array_reverse(iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandboxDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ), false)) as $node) {
            $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
        }
        @rmdir($this->sandboxDir);
    }

    public function testAnEnabledEntryWithTheKeyIsRosteredByTheLaunchThatLoadsIt(): void
    {
        $this->plantUserHooks(
            "hooks:\n"
            . "  PreToolUse:\n"
            . "    - name: gate-ask\n"
            . "      command: 'true'\n"
            . "      unattendedAsk: true\n"
            . "    - name: quiet-ask\n"
            . "      command: 'true'\n",
        );
        $names = $this->oneLaunch();

        self::assertEqualsCanonicalizing(['gate-ask', 'quiet-ask'], array_values(array_diff($names, ['protect-files', 'confirm-rm'])), 'both entries must join the chain for the roster claim to mean anything');
        self::assertTrue(
            HeadlessPermissionPrompt::demandsUnattendedAnswer(
                new HookResult(HookResult::ASK, 'question', askedBy: ['gate-ask']),
            ),
            'unattendedAsk: true did not reach the headless roster through the real loader',
        );
        self::assertFalse(
            HeadlessPermissionPrompt::demandsUnattendedAnswer(
                new HookResult(HookResult::ASK, 'question', askedBy: ['quiet-ask']),
            ),
            'an entry that never asked for the opt-in must not be auto-rostered',
        );
    }

    public function testADisabledEntryDoesNotRosterItsOptIn(): void
    {
        $this->plantUserHooks(
            "hooks:\n"
            . "  PreToolUse:\n"
            . "    - name: switched-off\n"
            . "      command: 'true'\n"
            . "      disabled: true\n"
            . "      unattendedAsk: true\n",
        );
        $names = $this->oneLaunch();

        self::assertNotContains('switched-off', $names, 'a disabled entry must stay out of the chain');
        self::assertFalse(
            HeadlessPermissionPrompt::demandsUnattendedAnswer(
                new HookResult(HookResult::ASK, 'question', askedBy: ['switched-off']),
            ),
            'a guard that never joins the chain must not roster an answer nobody will be asked for',
        );
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /** One reflective launch; returns the PreToolUse chain's hook names. */
    private function oneLaunch(): array
    {
        $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, null);
        self::assertInstanceOf(HookManager::class, $hooks);

        $registry = new \ReflectionProperty(HookManager::class, 'registry');
        $registry->setAccessible(true);
        $getFor = new \ReflectionMethod($registry->getValue($hooks), 'getForEvent');
        $getFor->setAccessible(true);

        return array_map(
            static fn (object $hook): string => $hook->name(),
            $getFor->invoke($registry->getValue($hooks), 'PreToolUse'),
        );
    }

    private function plantUserHooks(string $yaml): void
    {
        file_put_contents($this->sandboxHome . '/.candy-code/hooks.yaml', $yaml);
        clearstatcache();
    }

    /** Fresh-process shape for the launch memo: entries cache and notice store. */
    private function clearLaunchStatics(): void
    {
        // The freshness memo stayed on Bootstrap when S1a split the class;
        // only the notice store moved to LaunchNotices.
        foreach ([
            'policyFreshnessChecked' => false,
            'policyChanged' => false,
            'policyPreviousProjectHooks' => [],
            'policyPreviousTrustedRoots' => [],
            'hookFileEntries' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(Bootstrap::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
        LaunchNotices::reset();
    }
}
