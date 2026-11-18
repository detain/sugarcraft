<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\RevisionCapable;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Revision-keyed view memo (P1-16): a {@see RevisionCapable} model whose
 * revision is unchanged is not asked to view() again, and the per-frame diff
 * hook observes the previous and current bodies.
 */
final class ProgramMemoizedViewTest extends TestCase
{
    /** @var list<resource> */
    private array $held = [];

    protected function tearDown(): void
    {
        foreach ($this->held as $res) {
            if (is_resource($res)) {
                fclose($res);
            }
        }
        $this->held = [];
    }

    /**
     * @return array{0: Program, 1: resource}
     */
    private function program(Model $model, bool $headless = false): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        self::assertNotFalse($out);
        $this->held = [$in, $writer, $out];
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            withoutRenderer: $headless,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));
        return [$program, $out];
    }

    private function renderFrame(Program $program): void
    {
        (new \ReflectionMethod(Program::class, 'renderFrame'))->invoke($program);
    }

    private function readOutput($out): string
    {
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public function testViewIsSkippedWhenRevisionUnchangedAndCalledOnChange(): void
    {
        $model = new RevisionModel();
        [$program, $out] = $this->program($model);

        $this->renderFrame($program);
        self::assertSame(1, $model->viewCalls, 'the first frame must call view()');
        self::assertStringContainsString('one', $this->readOutput($out));

        // The label moves without a revision bump: the memo must hide it.
        $model->label = 'two';
        $this->renderFrame($program);
        self::assertSame(1, $model->viewCalls, 'an unchanged revision must skip view()');
        self::assertStringNotContainsString('two', $this->readOutput($out));

        $model->revision = 2;
        $this->renderFrame($program);
        self::assertSame(2, $model->viewCalls, 'a changed revision must call view()');
        self::assertStringContainsString('two', $this->readOutput($out));
    }

    public function testModelWithoutRevisionCallsViewOnEveryFrame(): void
    {
        $model = new PlainModel();
        [$program] = $this->program($model);

        $this->renderFrame($program);
        $this->renderFrame($program);
        $this->renderFrame($program);

        self::assertSame(3, $model->viewCalls, 'a model without RevisionCapable behaves as before');
    }

    public function testNullRevisionAlwaysCallsView(): void
    {
        $model = new RevisionModel();
        $model->revision = null;
        [$program] = $this->program($model);

        $this->renderFrame($program);
        $this->renderFrame($program);

        self::assertSame(2, $model->viewCalls, 'a null revision means no memo');
    }

    public function testMemoIsNotReusedAcrossModelClasses(): void
    {
        $model = new RevisionModel();
        [$program, $out] = $this->program($model);
        $this->renderFrame($program);

        $other = new OtherRevisionModel();
        (new \ReflectionProperty(Program::class, 'model'))->setValue($program, $other);
        $this->renderFrame($program);

        self::assertSame(1, $other->viewCalls, 'a different model class must not reuse another class\'s view');
        self::assertStringContainsString('other', $this->readOutput($out));
    }

    public function testThrowingViewIsNotMemoized(): void
    {
        $model = new RevisionModel();
        $model->throwOnNextView = true;
        $caught = [];
        [$program] = $this->program($model);
        $program = $program->withExceptionHandler(
            static function (\Throwable $t) use (&$caught): void {
                $caught[] = $t->getMessage();
            },
        );

        $this->renderFrame($program);
        $this->renderFrame($program);

        self::assertSame(['view-boom'], $caught);
        self::assertSame(2, $model->viewCalls, 'an absorbed throw must not leave a memo behind');
    }

    public function testFrameDiffHookReceivesPreviousAndCurrentBodies(): void
    {
        $model = new RevisionModel();
        $seen = [];
        [$program] = $this->program($model);
        $program = $program->withFrameDiffHook(
            static function (?string $previous, string $current) use (&$seen): void {
                $seen[] = [$previous, $current];
            },
        );

        $this->renderFrame($program);
        $model->label = 'two';
        $this->renderFrame($program);
        $model->revision = 2;
        $this->renderFrame($program);

        self::assertSame(
            [[null, 'one'], ['one', 'one'], ['one', 'two']],
            $seen,
            'the hook sees the first paint with a null previous body, then each frame\'s previous and current bodies',
        );
    }

    public function testFrameDiffHookIsNotCalledInHeadlessMode(): void
    {
        $model = new RevisionModel();
        $seen = [];
        [$program] = $this->program($model, headless: true);
        $program = $program->withFrameDiffHook(
            static function (?string $previous, string $current) use (&$seen): void {
                $seen[] = [$previous, $current];
            },
        );

        $this->renderFrame($program);
        $model->label = 'two';
        $model->revision = 2;
        $this->renderFrame($program);

        self::assertSame([], $seen, 'withoutRenderer must never call the diff hook');
        self::assertSame(2, $model->viewCalls, 'headless frames still run view()');
    }

    public function testFrameDiffHookSeesNullPreviousAfterForceRepaint(): void
    {
        $model = new RevisionModel();
        $seen = [];
        [$program] = $this->program($model);
        $program = $program->withFrameDiffHook(
            static function (?string $previous, string $current) use (&$seen): void {
                $seen[] = [$previous, $current];
            },
        );

        $this->renderFrame($program);
        $this->renderFrame($program);
        $program->forceRepaint();
        $this->renderFrame($program);

        self::assertSame(
            [[null, 'one'], ['one', 'one'], [null, 'one']],
            $seen,
            'forceRepaint() must reset the previous body the hook sees to null',
        );
    }

    public function testFrameDiffHookThrowGoesToExceptionHandler(): void
    {
        $model = new RevisionModel();
        $caught = [];
        [$program] = $this->program($model);
        $program = $program
            ->withFrameDiffHook(static function (): void {
                throw new \RuntimeException('hook-boom');
            })
            ->withExceptionHandler(
                static function (\Throwable $t) use (&$caught): void {
                    $caught[] = $t->getMessage();
                },
            );

        $this->renderFrame($program);

        self::assertSame(['hook-boom'], $caught);
    }
}

/**
 * Probe model with a revision and a label; viewCalls proves when view() ran.
 */
final class RevisionModel implements Model, RevisionCapable
{
    use SubscriptionCapable;

    public int|string|null $revision = 1;
    public string $label = 'one';
    public int $viewCalls = 0;
    public bool $throwOnNextView = false;

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        $this->viewCalls++;
        if ($this->throwOnNextView) {
            $this->throwOnNextView = false;
            throw new \RuntimeException('view-boom');
        }
        return $this->label;
    }

    public function revision(): int|string|null
    {
        return $this->revision;
    }
}

/**
 * Model without the revision contract: view() must run on every frame.
 */
final class PlainModel implements Model
{
    use SubscriptionCapable;

    public int $viewCalls = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        $this->viewCalls++;
        return 'plain';
    }
}

/**
 * A second revision-capable class, reporting the same revision as
 * {@see RevisionModel} so a cross-class reuse would be visible.
 */
final class OtherRevisionModel implements Model, RevisionCapable
{
    use SubscriptionCapable;

    public int $viewCalls = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        $this->viewCalls++;
        return 'other';
    }

    public function revision(): int|string|null
    {
        return 1;
    }
}
