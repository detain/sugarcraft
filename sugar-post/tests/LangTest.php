<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Lang;

/**
 * Tests for Lang i18n wrapper.
 *
 * Verifies the 'post' namespace, placeholder substitution, and that
 * inherited ::t() from SugarCraft\Core\I18n\Lang dispatches correctly.
 */
final class LangTest extends TestCase
{
    public function testTReturnsTranslatedString(): void
    {
        $result = Lang::t('mailer.no_recipient');

        $this->assertSame(
            'Email must have at least one recipient (to, cc, or bcc)',
            $result
        );
    }

    public function testTWithPlaceholderSubstitution(): void
    {
        $result = Lang::t('smtp.send_failed', ['message' => 'connection reset']);

        $this->assertSame('SMTP send failed: connection reset', $result);
    }

    public function testTWithMultiplePlaceholders(): void
    {
        $result = Lang::t('smtp.connect_failed', [
            'addr'   => 'smtp.example.com:587',
            'errstr' => 'Connection refused',
            'errno'  => '111',
        ]);

        $this->assertSame(
            'Cannot connect to smtp.example.com:587: Connection refused (111)',
            $result
        );
    }

    public function testTResolvesAttachmentTranslations(): void
    {
        $result = Lang::t('attachment.unreadable', ['path' => '/tmp/missing.txt']);

        $this->assertSame(
            'Attachment file is not readable: /tmp/missing.txt',
            $result
        );
    }

    public function testTResolvesEmailTranslations(): void
    {
        $result = Lang::t('email.invalid_address', ['addr' => 'not-an-email']);

        $this->assertSame(
            'Invalid email address: not-an-email',
            $result
        );
    }

    public function testTResolvesResendTranslations(): void
    {
        $result = Lang::t('resend.network_error', ['error' => 'timeout']);

        $this->assertSame('Resend network error: timeout', $result);
    }

    public function testTResolvesCliTranslations(): void
    {
        $result = Lang::t('cli.no_transport');

        $this->assertSame(
            'No transport configured. Set RESEND_API_KEY or POP_SMTP_HOST environment variable.',
            $result
        );
    }

    public function testTPrefixedWithNamespaceWhenNotFound(): void
    {
        // A key that does not exist in any loaded locale is returned prefixed
        // with the namespace (post.this.does.not.exist) so callers can identify
        // the missing translation.
        $result = Lang::t('this.does.not.exist');

        $this->assertSame('post.this.does.not.exist', $result);
    }

    public function testTWithEmptyParams(): void
    {
        $result = Lang::t('mailer.no_from');

        $this->assertSame('Email must have a from address', $result);
    }
}
