<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\DoctorChecks;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\DoctorHostCommand;
use SugarCraft\Code\Host\Commands\HostCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Core\Util\Width;

/**
 * C-TUI-5.a. `/doctor` in a headless host — the shape a web or ACP client
 * drives — answers with the same rows `candy-code doctor` prints, because both
 * read {@see DoctorChecks::run()}. The point of these pins is that the command
 * grew no opinions of its own: not a roster, not a verdict wording, not a
 * second sandbox probe, and not an argument it pretends to honour.
 */
final class DoctorHostCommandTest extends TestCase
{
    private string $scratch;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/c-tui-5a-doctor-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
        $this->store = new EnhancedSessionStore($this->scratch . '/session.db');
        $this->store->createSession('doc', 'project', 'echo');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $leftover) {
            @unlink((string) $leftover);
        }
        @rmdir($this->scratch);
    }

    public function testDoctorIsAHostCommandBody(): void
    {
        self::assertTrue((new ReflectionClass(DoctorHostCommand::class))->isFinal());
        self::assertInstanceOf(HostCommand::class, new DoctorHostCommand());
        // Built with nothing: a client must not be able to hand it a narrower
        // question than the install can answer, so there is no seam to fill.
        self::assertNull(
            (new ReflectionClass(DoctorHostCommand::class))->getConstructor(),
            'DoctorHostCommand grew a constructor argument — a host builds these with no arguments',
        );
    }

    public function testTheHeadlessHostAnswersWithOneLocalReport(): void
    {
        $host = $this->doctorHost();
        $ticket = $host->submit('/doctor');

        self::assertSame(TurnTicket::HANDLED, $ticket->admitted);
        self::assertFalse($host->isBusy(), 'a report holds no turn — nothing reaches a provider');

        $rows = $host->history();
        self::assertCount(2, $rows, '/doctor is an echo and its answer');
        self::assertSame('/doctor', $rows[0]->content);
        self::assertTrue($rows[0]->uiOnly && $rows[1]->uiOnly, 'an install report is never sent to the model as a prompt');

        $report = $rows[1]->content;
        self::assertStringContainsString(Lang::t('host.doctor.heading'), $report);
        foreach (array_column(DoctorChecks::run(null), 'label') as $label) {
            self::assertStringContainsString($label, $report, "`/doctor` stopped printing the {$label} row the CLI prints");
        }
        self::assertStringContainsString(
            self::expectedVerdict(),
            $report,
            'the command contradicts the roster it just read — the verdict is DoctorChecks\' count, not its own',
        );
        $host->release();
    }

    public function testAnOperandIsIgnoredRatherThanNarrowingTheReport(): void
    {
        $plain = $this->doctorHost();
        $plain->submit('/doctor');
        $argued = $this->doctorHost();
        $argued->submit('/doctor --json --all sessions');

        self::assertSame(
            '/doctor --json --all sessions',
            $argued->history()[0]->content,
            'what the operator typed is echoed as typed',
        );
        self::assertSame(
            $plain->history()[1]->content,
            $argued->history()[1]->content,
            'the report is already total — an argument that silently narrowed it would read as a different answer from the same command',
        );
        $plain->release();
        $argued->release();
    }

    public function testTheTableIsPlainTextAndFitsThePane(): void
    {
        $host = $this->doctorHost();
        $host->submit('/doctor');
        $report = (string) $host->history()[1]->content;
        $host->release();

        // A transcript body is markdown-ish and the renderer styles it; the
        // cells themselves must carry no SGR bytes and no emphasis of their own,
        // or a detail like `bwrap` would come out as a style change.
        self::assertStringNotContainsString("\x1b[", $report, 'raw ANSI reached a transcript row');
        $pane = CommandContext::HEADLESS_COLS - TranscriptTable::CHROME_COLS;
        foreach (explode("\n", $report) as $line) {
            self::assertLessThanOrEqual(
                $pane,
                Width::string($line),
                'a /doctor line ran past the pane the headless host builds',
            );
        }
    }

    public function testDoctorAnswersInAReadOnlyWindow(): void
    {
        $allowlist = (new ReflectionClass(Chat::class))
            ->getReflectionConstant('READ_ONLY_COMMANDS')
            ->getValue();

        self::assertContains('doctor', $allowlist, 'the diagnosis an operator wants most in a read-only window must run there');
    }

    public function testTheVerdictWordingIsTheSubcommandsWordForWord(): void
    {
        // Two readers, one catalogue: the CLI and the command must not grow
        // separate sentences for the same count, which is how a doc page ends up
        // quoting a verdict nobody prints.
        $command = (string) file_get_contents((new ReflectionClass(DoctorHostCommand::class))->getFileName());
        $cli = (string) file_get_contents((new ReflectionClass(Subcommands::class))->getFileName());
        foreach (['cli.doctor.no_problems', 'cli.doctor.failed.one', 'cli.doctor.failed.many'] as $key) {
            self::assertSame(
                1,
                substr_count($command, "'" . $key . "'"),
                "`/doctor` no longer words its {$key} verdict like `candy-code doctor` does",
            );
            self::assertStringContainsString("Lang::t('" . $key . "'", $cli, "the CLI stopped using the shared {$key} sentence");
        }

        self::assertStringContainsString('DoctorChecks::run(', $command, 'the command must read the shared roster, not name probes itself');
        foreach (['sandboxProbe(', 'bwrap', 'sessionStore('] as $ownOpinion) {
            self::assertSame(0, substr_count($command, $ownOpinion), "`/doctor` grew its own {$ownOpinion} — that is a second answer to one question");
        }
    }

    // ---- fixtures -------------------------------------------------------------

    private function doctorHost(): SessionHost
    {
        return SessionHost::new(
            'doc',
            WorkspaceContext::new(root: $this->scratch, sessionStore: $this->store),
        );
    }

    /** The verdict the shipped roster earns right now, spelled the shared way. */
    private static function expectedVerdict(): string
    {
        $failed = array_values(array_filter(
            DoctorChecks::run(null),
            static fn (array $row): bool => ($row['status'] ?? '') === 'FAIL',
        ));

        return match (true) {
            $failed === [] => Lang::t('cli.doctor.no_problems'),
            \count($failed) === 1 => Lang::t('cli.doctor.failed.one', ['count' => 1]),
            default => Lang::t('cli.doctor.failed.many', ['count' => \count($failed)]),
        };
    }
}
