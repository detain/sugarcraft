<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Bits\Spinner\TickMsg;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\SpinModel;
use CandyCore\Shell\Process\FakeProcess;
use PHPUnit\Framework\TestCase;

final class SpinModelTest extends TestCase
{
    public function testInitialState(): void
    {
        $model = SpinModel::spawn(new FakeProcess(), 'building');
        $this->assertFalse($model->isDone());
        $this->assertNull($model->exitCode());
        $this->assertStringContainsString('building', $model->view());
    }

    public function testTickAdvancesSpinnerWhileRunning(): void
    {
        $model = SpinModel::spawn(new FakeProcess(), '');
        $first = $model->spinner->view();
        [$next, $cmd] = $model->update(new TickMsg($model->spinner->id));
        $this->assertFalse($next->isDone());
        $this->assertNotSame($first, $next->spinner->view());
        $this->assertNotNull($cmd); // a fresh tick is reschedule
    }

    public function testIgnoresTickForOtherSpinner(): void
    {
        $model = SpinModel::spawn(new FakeProcess(), '');
        [$next, $cmd] = $model->update(new TickMsg($model->spinner->id + 100));
        $this->assertSame($model, $next);
        $this->assertNull($cmd);
    }

    public function testProcessExitTerminatesLoop(): void
    {
        $proc  = new FakeProcess();
        $model = SpinModel::spawn($proc, '');
        $proc->finish(0);
        [$next, $cmd] = $model->update(new TickMsg($model->spinner->id));
        $this->assertTrue($next->isDone());
        $this->assertSame(0, $next->exitCode());
        $this->assertNotNull($cmd);
    }

    public function testNonZeroExitCodeForwarded(): void
    {
        $proc  = new FakeProcess();
        $proc->finish(42);
        $model = SpinModel::spawn($proc, '');
        [$next, ] = $model->update(new TickMsg($model->spinner->id));
        $this->assertSame(42, $next->exitCode());
    }

    public function testEscTerminatesProcess(): void
    {
        $proc  = new FakeProcess();
        $model = SpinModel::spawn($proc, '');
        [$next, $cmd] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($next->isDone());
        $this->assertSame(-1, $next->exitCode());
        $this->assertTrue($proc->terminated);
        $this->assertNotNull($cmd);
    }

    public function testCtrlCTerminatesProcess(): void
    {
        $proc  = new FakeProcess();
        $model = SpinModel::spawn($proc, '');
        [$next, ] = $model->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($next->isDone());
        $this->assertTrue($proc->terminated);
    }

    public function testIgnoresFurtherUpdatesAfterDone(): void
    {
        $proc  = new FakeProcess();
        $proc->finish(0);
        $model = SpinModel::spawn($proc, '');
        [$model, ] = $model->update(new TickMsg($model->spinner->id));
        $this->assertTrue($model->isDone());
        [$same, $cmd] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertSame($model, $same);
        $this->assertNull($cmd);
    }
}
