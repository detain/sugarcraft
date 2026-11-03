<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Sql\Admin\PerfSchema\CommitPlanner;
use SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Sql\Admin\PerfSchema\SetupInstruments;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * D-M27: [c] against performance_schema used to execute queued UPDATEs on a
 * single blind keypress, while KILL and SET GLOBAL gated behind explicit
 * confirmation. The commit now arms a prompt listing the statements; only [y]
 * fires and any other key cancels.
 */
final class PerfSchemaCommitConfirmTest extends TestCase
{
    private function dirtyPage(FakeDatabase $db): PerfSchemaPage
    {
        $page = new PerfSchemaPage(new ServerContext($db));
        $instrument = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        )->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$instrument]);
        (new \ReflectionProperty(PerfSchemaPage::class, 'commitPlanner'))->setValue($page, $planner);
        (new \ReflectionProperty(PerfSchemaPage::class, 'instruments'))->setValue($page, [$instrument]);
        // isDirty() refuses to consult the arrays until a tracker exists
        // (loadData() installs one after the first successful load).
        $tracker = new \SugarCraft\Sql\Admin\PerfSchema\ChangeTracker([$instrument]);
        (new \ReflectionProperty(PerfSchemaPage::class, 'changeTracker'))->setValue($page, $tracker);

        return $page;
    }

    private function key(string $rune): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: $rune);
    }

    private function pending(PerfSchemaPage $page): ?array
    {
        return (new \ReflectionProperty(PerfSchemaPage::class, 'pendingCommit'))->getValue($page);
    }

    public function testCStagesTheCommitWithoutExecutingAnything(): void
    {
        $db = new FakeDatabase();
        $page = $this->dirtyPage($db);

        [$next, $cmd] = $page->update($this->key('c'));
        $this->assertNull($cmd);
        $this->assertNotSame($page, $next);
        $this->assertNotNull($this->pending($next), 'c must arm the prompt');
        $this->assertTrue($next->isModal(), 'the armed page owns the next keystroke');
        $this->assertSame([], $db->getExecutions(), 'nothing may run before confirmation');
    }

    public function testTheArmedPromptListsStatementsAndRendersTheDecision(): void
    {
        $db = new FakeDatabase();
        [$armed,] = $this->dirtyPage($db)->update($this->key('c'));
        \assert($armed instanceof PerfSchemaPage);

        $pending = $this->pending($armed);
        $this->assertIsArray($pending);
        $this->assertCount(1, $pending);
        $this->assertStringContainsString('UPDATE', (string) $pending[0]['sql']);

        $view = $armed->view();
        $this->assertStringContainsString('[y] confirm', $view);
        $this->assertStringContainsString('UPDATE', $view, 'the exact statement must be previewable');
    }

    public function testYExecutesTheStagedStatements(): void
    {
        $db = new FakeDatabase();
        [$armed,] = $this->dirtyPage($db)->update($this->key('c'));
        \assert($armed instanceof PerfSchemaPage);

        [$done,] = $armed->update($this->key('y'));
        \assert($done instanceof PerfSchemaPage);

        $executions = $db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('UPDATE', (string) $executions[0]['sql']);
        $this->assertNull($this->pending($done), 'the prompt must be consumed');
    }

    public function testAnyOtherKeyCancelsTheStagedCommit(): void
    {
        $db = new FakeDatabase();
        [$armed,] = $this->dirtyPage($db)->update($this->key('c'));
        \assert($armed instanceof PerfSchemaPage);

        foreach (['n', 'q', 'c', 'j'] as $rune) {
            [$cancelled,] = $armed->update($this->key($rune));
            \assert($cancelled instanceof PerfSchemaPage);
            $this->assertNull($this->pending($cancelled), "'{$rune}' must cancel");
        }
        $this->assertSame([], $db->getExecutions(), 'a cancelled commit never runs');
    }
}
