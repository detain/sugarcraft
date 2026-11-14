<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;

/**
 * sc-02 (finding sugar-core-051, visible-notice leg): the openTty fallback
 * stopped being silent.
 *
 * A Program asking for the controlling terminal and NOT getting one used to
 * plain-STDIN onward with zero trace of the refused open. The constructor
 * now raises one E_USER_NOTICE carrying the localized reason (the sc-20
 * `tty.open_failed` key that had no emitter until this branch shipped).
 *
 * Host-conditional by design: when /dev/tty (POSIX) or CONIN$ (Windows)
 * genuinely opens, the failure branch is unreachable here and the pin
 * skips — the loud-degradation arm is what CI without a controlling
 * terminal exercises.
 */
final class ProgramOpenTtyNoticeTest extends TestCase
{
    public function testOpenTtyFallbackNoticesWhenTheConsoleCannotBeOpened(): void
    {
        if (Tty::openTty() !== null) {
            $this->markTestSkipped('a controlling terminal is attached; the fallback branch is unreachable here');
        }

        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            if ($errno === E_USER_NOTICE) {
                $notices[] = $message;

                return true;
            }

            return false;
        });

        try {
            new Program(new OpenTtyNoticeModel(), new ProgramOptions(openTty: true));
        } finally {
            restore_error_handler();
        }

        self::assertSame(
            ['could not open the terminal'],
            $notices,
            'exactly one localized notice names the refused open (en value pinned verbatim, ExceptionTest precedent)',
        );
    }
}

/**
 * Minimal resident model — the constructor leg never runs the loop, so the
 * model only has to satisfy the contract shape.
 */
final class OpenTtyNoticeModel implements \SugarCraft\Core\Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(\SugarCraft\Core\Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'notice-model';
    }
}
