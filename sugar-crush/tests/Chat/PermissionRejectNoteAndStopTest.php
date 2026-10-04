<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\ToolEventPumpMsg;

/**
 * The permission modal's two refusals beyond `n` (R-KEYBIND, beside 1.C-3):
 * `r` lets the user TYPE why — the note rides the refusal to the model — and
 * `x` refuses and stops the turn at its next step boundary.
 */
final class PermissionRejectNoteAndStopTest extends TestCase
{
    private const GENERATION = 7;

    public function testRLetsTheUserTypeTheNoteTheModelReads(): void
    {
        [$asking, $ask] = $this->asking();

        [$writing] = $asking->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame(PermissionPromptStage::WritingNote, $writing->permissionStage());
        self::assertFalse($ask->isSettled(), 'opening the note answers nothing');

        foreach (str_split('use git stash') as $char) {
            [$writing] = $writing->update(new KeyMsg(KeyType::Char, $char));
        }
        self::assertSame('use git stash', $writing->inputBuf, 'typed into the draft box');
        self::assertNotNull($writing->pendingPermission(), 'letters edit the note, they do not answer');
        self::assertStringContainsString('Why are you refusing? use git stash', self::plain(Renderer::render($writing)), 'and the modal shows it');

        [$answered] = $writing->update(new KeyMsg(KeyType::Enter));

        self::assertSame(PermissionReply::Reject, $ask->resolution()?->reply);
        self::assertSame('use git stash', $ask->resolution()?->note);
        self::assertNull($answered->pendingPermission());
        self::assertSame('', $answered->inputBuf, 'the note was sent, so the box is empty');
        self::assertTrue($answered->inFlight, 'a refusal with a note lets the model carry on');
    }

    public function testEscapeFromTheNoteGoesBackToTheQuestionKeepingTheText(): void
    {
        [$asking, $ask] = $this->asking();
        [$writing] = $asking->update(new KeyMsg(KeyType::Char, 'r'));
        [$writing] = $writing->update(new KeyMsg(KeyType::Char, 'n'));

        [$back] = $writing->update(new KeyMsg(KeyType::Escape, ''));

        self::assertSame(PermissionPromptStage::Armed, $back->permissionStage());
        self::assertFalse($ask->isSettled(), 'Escape in the note is not the refusal it is at the question');
        self::assertSame('n', $back->inputBuf);
    }

    public function testXRefusesAndStopsTheTurnAtItsBoundary(): void
    {
        $token = new CancellationToken();
        [$asking, $ask] = $this->asking($token);

        [$answered] = $asking->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertSame(PermissionReply::Reject, $ask->resolution()?->reply);
        self::assertTrue($token->isSoftCancelled(), 'the turn stops at the step boundary');
        self::assertFalse($token->isCancelled(), 'and is not killed mid-step');
        self::assertNull($answered->pendingPermission());
    }

    /**
     * Across the fork: the soft cancel `x` raises goes down AHEAD of the
     * refusal, so the child — which settles the refused call at once — sees
     * it at the very boundary that call ends, not one poll tick later.
     */
    public function testAcrossTheForkTheStopReachesTheChildBeforeTheRefusal(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('an engine turn asks over completeAsync()\'s fork, which needs ext-pcntl.');
        }

        $token = new CancellationToken();
        $asks = 0;
        $state = InteractiveTurnHarness::settle(
            InteractiveTurnHarness::backend(InteractiveTurnHarness::provider(calls: 3))->completeInteractive(
                [Message::user('go')],
                cancellation: $token,
                onEvent: static function (object $event) use ($token, &$asks): void {
                    if ($event instanceof PermissionAsked) {
                        $asks++;
                        $token->cancelSoft();
                        $event->ask->reply(PermissionReply::Reject, 'stop here');
                    }
                },
            ),
            Loop::get(),
        );

        self::assertTrue($state['settled']);
        self::assertNull($state['error']);
        self::assertSame(1, $asks, 'the turn stopped at the first boundary instead of asking again');
        self::assertFalse($state['value']->stepsTruncated, 'a requested stop, not a ceiling');
    }

    public function testADisarmedPromptIgnoresBothNewKeys(): void
    {
        $token = new CancellationToken();
        [$asking, $ask] = $this->asking($token);
        [$disarmed] = $asking->update(new KeyMsg(KeyType::Char, '/'));

        foreach (['r', 'x'] as $key) {
            [$disarmed] = $disarmed->update(new KeyMsg(KeyType::Char, $key));
        }

        self::assertSame(PermissionPromptStage::Disarmed, $disarmed->permissionStage());
        self::assertFalse($ask->isSettled());
        self::assertFalse($token->isSoftCancelled());
    }

    /** @return array{0: Chat, 1: PendingAsk} */
    private function asking(?CancellationToken $token = null): array
    {
        $inbox = new \ArrayObject();
        $chat = (new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            generation: self::GENERATION,
            liveToolEvents: $inbox,
            inFlightCancellation: $token,
        ))->withSize(120, 40);

        $ask = new PendingAsk(
            PendingAsk::askId('call_Bash', 'Bash', ['command' => 'git reset --hard']),
            'call_Bash',
            'Bash',
            ['command' => 'git reset --hard'],
            'needs approval',
            'gate',
            'default',
            [PermissionReply::Once->value, PermissionReply::Always->value, PermissionReply::Reject->value],
            ['tool' => 'Bash'],
            static function (PermissionResolved $resolution): void {
            },
        );
        $inbox[] = [self::GENERATION, new PermissionAsked($ask)];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        self::assertSame($ask, $asking->pendingPermission()?->pendingAsk, 'fixture: the modal is up');

        return [$asking, $ask];
    }

    private static function plain(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $frame);
    }
}
