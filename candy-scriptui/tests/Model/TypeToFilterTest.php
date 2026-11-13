<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\ChooseModel;

/**
 * scriptui-43 (finding 061): typing into `choose` filters immediately —
 * gum muscle memory — instead of letters falling into a void until '/'
 * mode. --no-filter keeps the historical keys.
 */
final class TypeToFilterTest extends TestCase
{
    private function model(bool $typeToFilter = true, int $limit = 1, bool $noLimit = false): ChooseModel
    {
        return ChooseModel::fromOptions(
            ['apple', 'banana', 'cherry', 'fig'],
            limit: $limit,
            noLimit: $noLimit,
            typeToFilter: $typeToFilter,
        );
    }

    public function testTypedLetterOpensFilterAndNarrows(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));

        self::assertTrue($m->list->isFiltering());
        self::assertSame('a', $m->list->filterText);
        $titles = array_map(static fn ($i) => $i->title(), $m->list->visibleItems());
        self::assertSame(['apple', 'banana'], $titles);
    }

    public function testWholeWordTypingNarrowsAndEnterSelects(): void
    {
        $m = $this->model();
        foreach (['f', 'i', 'g'] as $ch) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }

        $titles = array_map(static fn ($i) => $i->title(), $m->list->visibleItems());
        self::assertSame(['fig'], $titles);

        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($m->isSubmitted(), 'first Enter commits the filter, not the answer');
        self::assertSame('fig', $m->list->filterText, 'narrowing survives the commit');
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($m->isSubmitted());
        self::assertSame('fig', $m->selected());
    }

    public function testVimNavKeysStillRideTheCursor(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'k'));

        self::assertFalse($m->list->isFiltering());
        self::assertSame('', $m->list->filterText);
        self::assertSame(0, $m->list->index());
    }

    public function testNoFilterKeepsTheHistoricalVoid(): void
    {
        $m = $this->model(typeToFilter: false);
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));

        self::assertFalse($m->list->isFiltering());
        self::assertCount(4, $m->list->visibleItems());
    }

    public function testSpaceIsFilterInSingleAndTickInMulti(): void
    {
        $single = $this->model();
        [$single, ] = $single->update(new KeyMsg(KeyType::Space, ''));
        self::assertTrue($single->list->isFiltering());
        self::assertSame(' ', $single->list->filterText);

        $multi = $this->model(limit: 2, noLimit: true);
        [$multi, ] = $multi->update(new KeyMsg(KeyType::Space, ''));
        self::assertFalse($multi->list->isFiltering());
        [$multi, ] = $multi->update(new KeyMsg(KeyType::Enter));
        self::assertSame(['apple'], $multi->selectedAll());
    }

    public function testSlashStillOpensAnEmptyFilterMode(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, '/'));

        self::assertTrue($m->list->isFiltering());
        self::assertSame('', $m->list->filterText);
    }

    public function testConflictingFilterFlagsRefuseBeforeAnyTerminalProbe(): void
    {
        // The refusal is a STDERR diagnostic (fwrite lane), so probe the
        // shipped bin with the same wall-clock-bounded recipe the
        // exit-code suite uses. It must also precede the no-terminal
        // probe: this sandbox has no tty, and the conflict sentence (not
        // runtime.no_terminal) must be what answers.
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $cmd = [
            'timeout', '-s', 'KILL', '20', PHP_BINARY,
            realpath(__DIR__ . '/../../bin/candy-scriptui'),
            'choose', '--filter', '--no-filter', 'apple', 'banana',
        ];
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        self::assertSame(1, $rc, 'stdout=' . $stdout . ' stderr=' . $stderr);
        self::assertStringContainsString('--filter', $stderr);
        self::assertStringContainsString('--no-filter', $stderr);
        self::assertStringNotContainsString('terminal', strtolower($stderr));
    }
}
