<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * C16: the .php compile memo keys on the file's CONTENT, not just its path.
 *
 * The old law was "editing a .php workflow does not take effect until
 * restart" — a cost the registry paid to dodge the uncatchable
 * "Cannot redeclare" fatal that a blind re-require of an EDITED file can
 * raise when the file declares file-scope symbols. The content-hash memo
 * keeps the fatal out (edited files that declare anything are refused
 * loudly, naming the path and the restart that fixes them) while files
 * that merely `return` a workflow — the shape the docs teach — re-require
 * inside the process.
 *
 * These pins watch $GLOBALS['c16_requires'] counters written by the
 * fixture files themselves, so "compiled again" is measured, not assumed.
 */
final class WorkflowRegistryPhpMemoTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/candy_code_wf_memo_' . \uniqid('', true);
        \mkdir($this->tempDir . '/workflows', 0755, true);
        $GLOBALS['c16_requires'] = [];
        self::resetCompileMemos();
    }

    protected function tearDown(): void
    {
        self::resetCompileMemos();
        unset($GLOBALS['c16_requires']);
        $this->removeMemoDirectory($this->tempDir);
    }

    public function testAnUnchangedFileIsCompiledExactlyOnce(): void
    {
        $path = $this->workflowsDir() . '/memo-once.php';
        \file_put_contents($path, $this->returner('memo-once', 'once'));

        $registry = new WorkflowRegistry($this->workflowsDir());
        $first = $registry->load('memo-once');
        $second = $registry->load('memo-once');

        self::assertSame($first, $second, 'an untouched file must ride the memo');
        self::assertCount(1, $GLOBALS['c16_requires']['once'] ?? []);
    }

    public function testAnEditedReturnOnlyWorkflowRecompilesInsideTheProcess(): void
    {
        $path = $this->workflowsDir() . '/memo-edit.php';
        \file_put_contents($path, $this->returner('memo-edit', 'edit'));

        $registry = new WorkflowRegistry($this->workflowsDir());
        self::assertSame('memo-edit', $registry->load('memo-edit')->name);

        \file_put_contents($path, $this->returner('memo-renamed', 'edit'));

        $reloaded = $registry->load('memo-edit');
        self::assertSame('memo-renamed', $reloaded->name, 'the edit must land in-process, not wait for a restart');
        self::assertCount(2, $GLOBALS['c16_requires']['edit'] ?? [], 'the file must actually be required again');
    }

    public function testAPoisonedCompileHealsOnceTheFileIsFixed(): void
    {
        $path = $this->workflowsDir() . '/memo-heal.php';
        \file_put_contents($path, "<?php\n\$GLOBALS['c16_requires']['heal'][] = 1;\nthrow new \\RuntimeException('v1 boom');\n");

        $registry = new WorkflowRegistry($this->workflowsDir());
        $refused = null;
        try {
            $registry->load('memo-heal');
        } catch (\RuntimeException $error) {
            $refused = $error;
        }
        self::assertNotNull($refused, 'the throwing file must refuse the first load');
        self::assertSame('v1 boom', $refused->getMessage());

        \file_put_contents($path, $this->returner('memo-healed', 'heal'));

        $healed = $registry->load('memo-heal');
        self::assertSame('memo-healed', $healed->name, 'a fixed file must not ride the poison installed by the broken bytes');
        self::assertCount(2, $GLOBALS['c16_requires']['heal'] ?? []);
    }

    public function testAnEditedDeclaringFileIsRefusedLoudlyInsteadOfServingStale(): void
    {
        $canary = 'wf_memo_canary_' . \substr(\md5((string) __CLASS__), 0, 8);
        $path = $this->workflowsDir() . '/memo-decl.php';
        \file_put_contents($path, "<?php\nfunction {$canary}() { return 1; }\n\$GLOBALS['c16_requires']['decl'][] = 1;\nthrow new \\RuntimeException('v1 boom');\n");

        $registry = new WorkflowRegistry($this->workflowsDir());
        $refused = null;
        try {
            $registry->load('memo-decl');
        } catch (\RuntimeException $error) {
            $refused = $error;
        }
        self::assertNotNull($refused, 'the throwing file must refuse the first load');
        self::assertSame('v1 boom', $refused->getMessage());

        \file_put_contents($path, "<?php\nfunction {$canary}() { return 2; }\n\$GLOBALS['c16_requires']['decl'][] = 1;\nthrow new \\RuntimeException('v2 boom');\n");

        try {
            $registry->load('memo-decl');
            self::fail('an edited declaring file must refuse, not re-require into the redeclare fatal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('memo-decl.php', $error->getMessage());
            self::assertStringContainsString('edited', $error->getMessage());
            self::assertStringContainsString('restart', $error->getMessage());
        }

        self::assertCount(1, $GLOBALS['c16_requires']['decl'] ?? [], 'the loud refusal must not re-require the file');
    }

    public function testThePoisonRidesTheHashOfTheAttemptThatInstalledIt(): void
    {
        $path = $this->workflowsDir() . '/memo-poison.php';
        \file_put_contents($path, "<?php\n\$GLOBALS['c16_requires']['poison'][] = 1;\nthrow new \\RuntimeException('same bytes');\n");

        $registry = new WorkflowRegistry($this->workflowsDir());
        $first = null;
        try {
            $registry->load('memo-poison');
        } catch (\RuntimeException $error) {
            $first = $error;
        }
        self::assertNotNull($first, 'the throwing file must refuse');
        self::assertSame('same bytes', $first->getMessage());

        $second = null;
        try {
            $registry->load('memo-poison');
        } catch (\RuntimeException $error) {
            $second = $error;
        }
        self::assertNotNull($second, 'the unchanged bytes must stay poisoned');
        self::assertSame($first, $second, 'the very same Throwable must answer, not a re-require');

        self::assertCount(1, $GLOBALS['c16_requires']['poison'] ?? []);
    }

    public function testAnEditedSuccessMemoOnADeclaringFileIsRefusedLoudlyToo(): void
    {
        $canary = 'wf_memo_canary_ok_' . \substr(\md5((string) __CLASS__ . 'ok'), 0, 8);
        $path = $this->workflowsDir() . '/memo-decl-ok.php';
        \file_put_contents($path, "<?php\nfunction {$canary}() { return 1; }\n\$GLOBALS['c16_requires']['declok'][] = 1;\nreturn " . $this->returnerExpression('memo-decl-v1') . "\n");

        $registry = new WorkflowRegistry($this->workflowsDir());
        $first = $registry->load('memo-decl-ok');
        self::assertSame('memo-decl-v1', $first->name);

        \file_put_contents($path, "<?php\nfunction {$canary}() { return 2; }\n\$GLOBALS['c16_requires']['declok'][] = 1;\nreturn " . $this->returnerExpression('memo-decl-v2') . "\n");

        try {
            $registry->load('memo-decl-ok');
            self::fail('an edited declaring file must refuse instead of serving the stale memo');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('edited', $error->getMessage());
            self::assertStringContainsString('restart', $error->getMessage());
        }

        self::assertCount(1, $GLOBALS['c16_requires']['declok'] ?? [], 'the refusal must not re-require');
    }

    /**
     * A .php workflow file in the shape the docs teach: no file-scope
     * declarations, just a returned Workflow.
     */
    private function returner(string $name, string $tag): string
    {
        return "<?php\n\$GLOBALS['c16_requires']['{$tag}'][] = 1;\nreturn " . $this->returnerExpression($name) . "\n";
    }

    private function returnerExpression(string $name = 'memo-workflow'): string
    {
        return "(new \\SugarCraft\\Code\\Workflows\\WorkflowBuilder())->name('{$name}')->description('d')->stage('s', \\SugarCraft\\Code\\Workflows\\Tasks::agent('coder')->prompt('p'))->build();";
    }

    private function workflowsDir(): string
    {
        return $this->tempDir . '/workflows';
    }

    /**
     * The compile memo is process-global static state; each test must start
     * and end with it empty or fixtures in one test answer from another's
     * bytes. No production reset seam exists, so reflection is the door.
     */
    private static function resetCompileMemos(): void
    {
        foreach (['phpWorkflows', 'phpWorkflowFailures', 'phpWorkflowHashes'] as $memo) {
            if (\property_exists(WorkflowRegistry::class, $memo)) {
                (new \ReflectionProperty(WorkflowRegistry::class, $memo))->setValue(null, []);
            }
        }
    }

    private function removeMemoDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeMemoDirectory($path);
                continue;
            }
            \unlink($path);
        }
        \rmdir($dir);
    }
}
