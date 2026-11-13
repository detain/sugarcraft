<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Acp;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Acp\AcpSession;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;

final class AcpSessionTest extends TestCase
{
    private function session(string $id = 'sess-1'): AcpSession
    {
        return AcpSession::new(SessionHost::new($id, WorkspaceContext::new(), ephemeral: true));
    }

    public function testSessionIdAndHostComeFromTheHost(): void
    {
        $s = $this->session('abc');

        self::assertSame('abc', $s->sessionId());
        self::assertSame('abc', $s->host->sessionId());
    }

    public function testFreshSessionIsNotPrompting(): void
    {
        $s = $this->session();

        self::assertFalse($s->isPrompting());
        self::assertNull($s->promptId());
    }

    public function testBeginPromptTracksIdAndEndPromptReturnsOutcome(): void
    {
        $s = $this->session();
        $s->beginPrompt(7);

        self::assertTrue($s->isPrompting());
        self::assertSame(7, $s->promptId());

        $s->completed('end_turn', null);
        self::assertSame(['end_turn', null], $s->endPrompt());
        self::assertFalse($s->isPrompting());
        self::assertNull($s->promptId());
    }

    public function testPromptIdMayBeStringOrNull(): void
    {
        $s = $this->session();
        $s->beginPrompt('req-9');
        self::assertSame('req-9', $s->promptId());
        $s->beginPrompt(null);
        self::assertNull($s->promptId());
        self::assertTrue($s->isPrompting());
    }

    public function testEndPromptClearsOutcomeForTheNextPrompt(): void
    {
        $s = $this->session();
        $s->beginPrompt(1);
        $s->completed('cancelled', 'boom');
        self::assertSame(['cancelled', 'boom'], $s->endPrompt());

        self::assertSame([null, null], $s->endPrompt());
    }

    public function testBeginPromptDropsAStaleOutcome(): void
    {
        $s = $this->session();
        $s->completed('refusal', 'old');
        $s->beginPrompt(2);

        self::assertSame([null, null], $s->endPrompt());
    }

    public function testUnsentTextIsWholeSettledTextWhenNothingStreamed(): void
    {
        self::assertSame('hello', $this->session()->unsentText('hello'));
        self::assertSame('think', $this->session()->unsentThought('think'));
    }

    public function testUnsentTextIsTheRemainderAfterStreamedPrefix(): void
    {
        $s = $this->session();
        $s->streamedText('hel');
        $s->streamedText('lo ');
        $s->streamedThought('th');

        self::assertSame('world', $s->unsentText('hello world'));
        self::assertSame('ink', $s->unsentThought('think'));
    }

    public function testUnsentTextIsEmptyWhenStreamAlreadyCoveredIt(): void
    {
        $s = $this->session();
        $s->streamedText('done');

        self::assertSame('', $s->unsentText('done'));
    }

    public function testDivergentSettledTextSendsNothingRatherThanDuplicate(): void
    {
        $s = $this->session();
        $s->streamedText('abc');

        self::assertSame('', $s->unsentText('xyz'));
    }

    public function testBeginTurnResetsStreamAccounting(): void
    {
        $s = $this->session();
        $s->streamedText('abc');
        $s->streamedThought('def');
        $s->beginTurn();

        self::assertSame('abc', $s->unsentText('abc'));
        self::assertSame('def', $s->unsentThought('def'));
    }

    public function testBeginPromptAlsoStartsAFreshTurn(): void
    {
        $s = $this->session();
        $s->streamedText('abc');
        $s->beginPrompt(1);

        self::assertSame('abc', $s->unsentText('abc'));
    }

    public function testDetachRunsTheListenerOnceAndForgetsIt(): void
    {
        $s = $this->session();
        $calls = 0;
        $s->listening(static function () use (&$calls): void {
            $calls++;
        });

        $s->detach();
        $s->detach();

        self::assertSame(1, $calls);
    }

    public function testDetachWithoutListenerIsANoOp(): void
    {
        $this->session()->detach();
        $this->addToAssertionCount(1);
    }

    public function testListeningReplacesAnEarlierDetach(): void
    {
        $s = $this->session();
        $log = [];
        $s->listening(static function () use (&$log): void {
            $log[] = 'first';
        });
        $s->listening(static function () use (&$log): void {
            $log[] = 'second';
        });
        $s->detach();

        self::assertSame(['second'], $log);
    }
}
