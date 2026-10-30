<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SEC-8.d (serve half): `session.send` neutralises invisible Unicode at
 * the host's submit choke, so a client cannot route around the strip the TUI
 * got in the same step.
 *
 * `Protocol\Methods\TurnMethods` hands the wire text straight to
 * {@see SessionHost::submit()}, which is BOTH doors' one funnel — admit,
 * queue and steer all take their text from here, so the bytes the model
 * reads, the transcript stores and any later renderer consumes are the
 * cleaned ones. The rule is the display path's own joiner tier
 * (`Sanitize::stripInvisibleFormatting`, audit 15b-28): a family emoji's
 * ZWJ is spelling and survives; a ZWJ between ASCII letters is a spoof and
 * goes. Exactly one notice carries the count, announced to attached clients
 * beside the prompt's own rows — the same write/save/announce shape the
 * idle advisory beside it uses.
 *
 * Attachments are NOT stripped, and that is the documented no-opt-out
 * answer for a user who means the bytes: the file content rides
 * {@see SubmitOptions::withAttachments()} untouched.
 */
final class SubmitInvisibleUnicodeTest extends TestCase
{
    use HomeSandboxTrait;

    /** Woman + ZWJ + laptop, U+1F469 U+200D U+1F4BB, one grapheme cluster. */
    private const FAMILY_GLYPH = "\u{1F469}\u{200D}\u{1F4BB}";

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-submit-invisible-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** Fresh two-message transcript, exactly the family fixture shape. */
    private function makeHost(): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                commandLoader: new CommandLoader(),
                backend: new EchoBackend(),
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
        );
    }

    /** @return list<Message> everything the send appended to the fixture */
    private function appended(SessionHost $host): array
    {
        return array_slice($host->history(), 2);
    }

    /** @return list<Message> the Role::System (notice) rows among them */
    private function noticesOf(array $rows): array
    {
        return array_values(array_filter($rows, static fn (Message $r): bool => $r->role === Role::System));
    }

    public function testTheServedBidiOverrideIsStrippedAndOneNoticeCarriesTheCount(): void
    {
        $host = $this->makeHost();
        $ticket = $host->submit("ship \u{202E}it\u{2066} now");

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        $rows = $this->appended($host);
        $user = array_values(array_filter($rows, static fn (Message $r): bool => $r->role === Role::User));
        self::assertCount(1, $user);
        self::assertSame('ship it now', $user[0]->content, 'both always-tier characters are gone from the stored row');

        $notices = $this->noticesOf($rows);
        self::assertCount(1, $notices, 'one notice for the whole send, naming the count');
        self::assertSame(Lang::t('chat.notice.invisible_stripped', ['count' => 2]), $notices[0]->content);
    }

    public function testAPlainServedPromptIsByteIdenticalAndUnnoticed(): void
    {
        $host = $this->makeHost();
        $host->submit('nothing hidden here');

        $rows = $this->appended($host);
        self::assertSame('nothing hidden here', $rows[0]->content);
        self::assertSame([], $this->noticesOf($rows));
    }

    public function testAJoinerFamilyServedOverTheWireKeepsItsBytes(): void
    {
        $host = $this->makeHost();
        $draft = 'look: ' . self::FAMILY_GLYPH . ' ships today';
        $host->submit($draft);

        $rows = $this->appended($host);
        self::assertSame($draft, $rows[0]->content, 'the ZWJ after a non-ASCII letter is spelling and stays');
        self::assertSame([], $this->noticesOf($rows));
    }

    public function testAWhitespaceOnlyInvisiblePromptIsRefusedAfterSayingSo(): void
    {
        // The draft is non-empty on the wire and empty after the strip:
        // refuse it AS the empty prompt, with the notice as the account of
        // where the bytes went — never dispatch a turn over nothing.
        $host = $this->makeHost();
        $ticket = $host->submit("\u{200B}\u{FEFF}");

        // The REFUSED shape, not merely "not STARTED": a ticket that came back
        // QUEUED, STEERED, PENDING or HANDLED would satisfy a negated STARTED
        // while describing a submission the host took. Refusal is the polarity
        // this arm promises.
        self::assertSame(TurnTicket::REFUSED, $ticket->admitted, 'nothing was taken');
        $rows = $this->appended($host);
        self::assertSame([], array_filter($rows, static fn (Message $r): bool => $r->role === Role::User));
        $notices = $this->noticesOf($rows);
        self::assertCount(1, $notices);
        self::assertSame(Lang::t('chat.notice.invisible_stripped', ['count' => 2]), $notices[0]->content);

        // The refusal is about THAT draft, not the host. A clean prompt sent
        // after it is admitted exactly as it would have been before, and adds
        // no notice — the refusal's own row is the whole account of the bytes
        // that went away, and an empty send must not leave the session deaf.
        $after = $host->submit('back to normal');
        self::assertSame(TurnTicket::STARTED, $after->admitted, 'the host still takes a clean draft');
        $later = $this->appended($host);
        $user = array_values(array_filter($later, static fn (Message $r): bool => $r->role === Role::User));
        self::assertCount(1, $user, 'the follow-up is the only user row the two sends produced');
        self::assertSame('back to normal', $user[0]->content, 'sent verbatim, with nothing extra to strip');
        self::assertCount(1, $this->noticesOf($later), 'the clean send adds no second notice');
    }

    public function testAttachmentsAreNotThePromptAndKeepTheirBytes(): void
    {
        // The design's escape hatch, pinned: the strip is over the text only.
        // A file attachment carrying a right-to-left override in its data
        // rides the option object untouched, and the user row is still clean.
        $dirty = "bytes\u{202E}\nhere";
        $host = $this->makeHost();
        $host->submit(
            "see the file\u{200B}",
            SubmitOptions::new()->withAttachments([
                new Attachment('notes.txt', AttachmentType::File, data: $dirty),
            ]),
        );

        $rows = $this->appended($host);
        $user = array_values(array_filter($rows, static fn (Message $r): bool => $r->role === Role::User));
        self::assertSame('see the file', $user[0]->content);
        self::assertSame($dirty, $user[0]->attachments[0]->data, 'attachment data is not submit text and is not stripped');
        self::assertCount(1, $this->noticesOf($rows), 'the notice counts the text strip only');
    }
}
