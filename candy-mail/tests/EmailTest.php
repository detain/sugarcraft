<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $email = new Email(
            from:    ['me@example.com'],
            to:      ['you@example.com'],
            subject: 'Test',
            body:    'Hello world',
        );

        $this->assertSame(['me@example.com'], $email->from);
        $this->assertSame(['you@example.com'], $email->to);
        $this->assertSame('Test', $email->subject);
        $this->assertSame('Hello world', $email->body);
        $this->assertSame([], $email->cc);
        $this->assertSame([], $email->bcc);
        $this->assertSame([], $email->attachments);
    }

    public function testTrimsWhitespace(): void
    {
        $email = new Email(
            from:    ['  me@example.com  '],
            to:      ['  you@example.com  ', ' other@example.com  '],
        );

        $this->assertSame('me@example.com', $email->from[0]);
        $this->assertSame('you@example.com', $email->to[0]);
        $this->assertSame('other@example.com', $email->to[1]);
    }

    public function testWithFrom(): void
    {
        $email = (new Email(['old@example.com'], ['you@example.com']))
            ->withFrom('new@example.com');

        $this->assertSame(['new@example.com'], $email->from);
    }

    public function testWithToAddsRecipients(): void
    {
        $email = (new Email(['me@example.com'], ['a@example.com']))
            ->withTo('b@example.com', 'c@example.com');

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $email->to);
    }

    public function testWithCc(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withCc('cc@example.com');

        $this->assertSame(['cc@example.com'], $email->cc);
    }

    public function testWithBcc(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBcc('bcc@example.com');

        $this->assertSame(['bcc@example.com'], $email->bcc);
    }

    public function testWithSubject(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withSubject('New Subject');

        $this->assertSame('New Subject', $email->subject);
    }

    public function testWithBody(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('New body text');

        $this->assertSame('New body text', $email->body);
    }

    public function testWithHtmlBody(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withHtmlBody('<p>HTML body</p>');

        $this->assertSame('<p>HTML body</p>', $email->htmlBody);
    }

    public function testWithReplyTo(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withReplyTo('reply@example.com');

        $this->assertSame('reply@example.com', $email->replyTo);
    }

    public function testWithSignature(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('The body')
            ->withSignature('-- The Team');

        $this->assertSame("The body\n\n-- The Team", $email->bodyWithSignature());
    }

    public function testBodyWithSignatureSkipsWhenNoBody(): void
    {
        $email = new Email(['me@example.com'], ['you@example.com']);
        $this->assertNull($email->bodyWithSignature());
    }

    public function testBodyWithSignatureSkipsWhenNoSignature(): void
    {
        $email = (new Email(['me@example.com'], ['you@example.com']))
            ->withBody('Just body');

        $this->assertSame('Just body', $email->bodyWithSignature());
    }

    public function testAllRecipients(): void
    {
        $email = (new Email(
            from:    ['me@example.com'],
            to:      ['a@example.com', 'b@example.com'],
            cc:      ['c@example.com'],
            bcc:     ['d@example.com'],
        ));

        $all = $email->allRecipients();
        $this->assertContains('a@example.com', $all);
        $this->assertContains('b@example.com', $all);
        $this->assertContains('c@example.com', $all);
        $this->assertContains('d@example.com', $all);
    }

    public function testAllRecipientsDeduplicates(): void
    {
        $email = new Email(
            from: ['me@example.com'],
            to:   ['dup@example.com', 'dup@example.com'],
        );

        $all = $email->allRecipients();
        $this->assertCount(1, $all);
    }

    public function testAllRecipientsDeduplicatesAcrossListsByDomainCase(): void
    {
        // m7 (lib-reviews-1102): the old raw array_unique compared byte-wise,
        // so A@Example.COM in To and A@example.com in Cc became two RCPTs.
        // The dedupe now follows the list-merge key law: local verbatim (case
        // is technically significant), domain case-insensitive; the first
        // occurrence is kept verbatim.
        $email = new Email(
            from: ['me@example.com'],
            to:   ['A@Example.COM'],
            cc:   ['A@example.com', 'c@d.example'],
            bcc:  ['c@D.EXAMPLE'],
        );

        $this->assertSame(
            ['A@Example.COM', 'c@d.example'],
            \array_values($email->allRecipients()),
        );
    }

    public function testRejectsCrlfInSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Email(
            from:    ['me@example.com'],
            to:      ['you@example.com'],
            subject: "Hello\r\nMalicious-Header: value",
        );
    }

    /**
     * candy-mail-003: the subject obeys the same C0/DEL refusal parity as
     * addresses (loud refusal, never stripping). Exact refused class:
     * 0x00-0x08, 0x0B-0x0C, 0x0E-0x1F, 0x7F. TAB stays legal (header WSP);
     * CR/LF are refused separately as the CRLF pair.
     */
    public function testRefusesC0ControlByteClassInSubject(): void
    {
        $matrix = [
            'NUL' => "\x00",
            'BEL' => "\x07",
            'BS'  => "\x08",
            'VT'  => "\x0B",
            'FF'  => "\x0C",
            'SO'  => "\x0E",
            'ESC' => "\x1B",
            'US'  => "\x1F",
        ];
        foreach ($matrix as $name => $byte) {
            try {
                new Email(
                    from:    ['me@example.com'],
                    to:      ['you@example.com'],
                    subject: "Report {$byte} summary",
                );
                $this->fail("subject carrying {$name} (0x" . \dechex(\ord($byte)) . ") must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }

        // DEL. And the OSC-forgery shape the finding cited: escape sequences
        // must refuse, not ride raw into receivers' logs and terminals.
        $this->expectException(\InvalidArgumentException::class);
        new Email(from: ['a@b.c'], to: ['d@e.f'], subject: "a\x7Fb");

        $this->expectException(\InvalidArgumentException::class);
        new Email(from: ['a@b.c'], to: ['d@e.f'], subject: "a\x1B]0;x\x07b");
    }

    public function testAcceptsTabAndSpaceInSubjectAsHeaderWsp(): void
    {
        $email = new Email(
            from:    ['me@example.com'],
            to:      ['you@example.com'],
            subject: "Quarterly\treport",
        );

        $this->assertSame("Quarterly\treport", $email->subject);
    }

    public function testRejectsCrlfInRecipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected');

        new Email(
            from: ['me@example.com'],
            to:   ["you@example.com\r\nMalicious: value"],
        );
    }

    public function testRefusesEveryC0ControlAndDelInRecipient(): void
    {
        // Step 34 gate class: CRLF rides its precise label, the remaining
        // C0 bytes and DEL are refused under the generic invalid-address
        // label. Tab is included on purpose - a legal-but-exotic
        // display-name tab is cheaper to lose than a silent log-corrupter.
        foreach (["\x00", "\x07", "\x09", "\x0B", "\x0C", "\x1B", "\x1F", "\x7F"] as $byte) {
            try {
                new Email(
                    from: ['me@example.com'],
                    to:   ['you@example.com' . $byte . 'x'],
                );
                $this->fail('address carrying ' . \bin2hex($byte) . ' rode through the gate');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid email address', $e->getMessage());
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        new Email(
            from: ['not-an-email'],
            to:   ['you@example.com'],
        );
    }

    public function testWithToDedupesTheDomainPartCaseInsensitively(): void
    {
        $email = (new Email(from: ['me@example.com'], to: ['user@Gmail.com']))
            ->withTo('user@gmail.com', 'other@gmail.com');

        // RFC 5321: the domain is case-insensitive, so these are the same
        // address; the first spelling survives and no duplicate rides the wire.
        $this->assertSame(['user@Gmail.com', 'other@gmail.com'], $email->to);
    }

    public function testWithToKeepsDistinctLocalPartsRegardlessOfDomainCase(): void
    {
        $email = (new Email(from: ['me@example.com'], to: ['Alice@Example.com']))
            ->withTo('alice@example.com');

        // The local part is case-sensitive, so these stay two distinct mailboxes.
        $this->assertSame(['Alice@Example.com', 'alice@example.com'], $email->to);
    }

    public function testWithCcAndBccDedupeCaseInsensitively(): void
    {
        $email = (new Email(from: ['me@example.com'], to: ['t@example.com'], cc: ['Cc@X.com'], bcc: ['Bcc@Y.com']))
            ->withCc('Cc@x.com')
            ->withBcc('Bcc@y.com');

        $this->assertSame(['Cc@X.com'], $email->cc);
        $this->assertSame(['Bcc@Y.com'], $email->bcc);
    }

    public function testStrayAngleBracketsAreRefused(): void
    {
        // RFC 5322 sect. 3.4: the only bracketed form is a single trailing
        // angle-addr; anything else was silently accepted and shipped.
        foreach (['weird <a@b.example> </x>', 'Name <a@b.example> junk', 'a@b.example<c@d.example>'] as $hostile) {
            try {
                new Email(from: ['ok@example.com'], to: [$hostile]);
                $this->fail('hostile address accepted: ' . $hostile);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid email address', $e->getMessage());
            }
        }
    }

    public function testDisplayNameFormSurvivesTheAngleGate(): void
    {
        $email = new Email(from: ['"Q, uote" <a@b.example>'], to: ['Display Name <c@d.example>']);
        $this->assertSame(['"Q, uote" <a@b.example>'], $email->from);
        $this->assertSame(['Display Name <c@d.example>'], $email->to);
    }

    public function testControlBytesAreStrippedFromBodies(): void
    {
        $email = new Email(
            from:      ['a@b.example'],
            to:        ['c@d.example'],
            body:      "Hi\x00the\x07re",
            htmlBody:  "<p>x\x1By</p>",
            signature: "sig\x01\r\nline",
        );
        $this->assertSame('Hithere', $email->body);
        $this->assertSame('<p>xy</p>', $email->htmlBody);
        $this->assertSame("sig\r\nline", $email->signature);

        // Tabs and newlines (C0 members with layout meaning) are preserved.
        $keep = new Email(from: ['a@b.example'], to: ['c@d.example'], body: "keep\tme\nas-is");
        $this->assertSame("keep\tme\nas-is", $keep->body);
    }
}
