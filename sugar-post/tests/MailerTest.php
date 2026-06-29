<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Post\Email;
use SugarCraft\Post\Mailer;

/**
 * Tests for Mailer.
 */
final class MailerTest extends TestCase
{
    public function testSendPassesEmailToTransport(): void
    {
        $fake = new FakeTransportForTest();
        $mailer = new Mailer($fake);

        $email = new Email(['from@example.com'], ['to@example.com'], 'Subject', 'Body');
        $mailer->send($email);

        $this->assertTrue($fake->wasSendCalled());
        $this->assertCount(1, $fake->getSent());
        $this->assertSame('Subject', $fake->getSent()[0]->subject);
    }

    public function testCancelledTokenAbortsBeforeSend(): void
    {
        $fake = new FakeTransportForTest();
        $mailer = new Mailer($fake);

        $source = CancellationSource::new();
        $source->cancel();
        $token = $source->token();

        $email = new Email(['from@example.com'], ['to@example.com'], 'Subject', 'Body');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled');

        try {
            $mailer->send($email, $token);
        } finally {
            // Transport send() must NOT have been reached
            $this->assertFalse($fake->wasSendCalled(), 'Transport send() should not be reached when token is pre-cancelled');
        }
    }

    public function testMailerThrowsWhenNoRecipients(): void
    {
        $fake = new FakeTransportForTest();
        $mailer = new Mailer($fake);

        $email = new Email(['from@example.com'], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient');

        $mailer->send($email);
    }

    public function testMailerThrowsWhenNoFrom(): void
    {
        $fake = new FakeTransportForTest();
        $mailer = new Mailer($fake);

        $email = new Email([], ['to@example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from');

        $mailer->send($email);
    }
}

/**
 * Minimal in-memory Transport for tests; records send() calls.
 */
final class FakeTransportForTest implements \SugarCraft\Post\Transport
{
    /** @var list<Email> */
    private array $sent = [];
    private bool $sendCalled = false;

    public function send(\SugarCraft\Post\Email $email, ?CancellationToken $token = null): void
    {
        $this->sendCalled = true;
        $this->sent[] = $email;
    }

    public function name(): string
    {
        return 'fake';
    }

    /** @return list<Email> */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function wasSendCalled(): bool
    {
        return $this->sendCalled;
    }
}
