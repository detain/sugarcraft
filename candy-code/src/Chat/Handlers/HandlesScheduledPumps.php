<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat\Handlers;

use React\Promise\PromiseInterface;
use SugarCraft\Code\App\ScheduleFiredMsg;
use SugarCraft\Code\Chat\ScheduleVerdicts;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;

/**
 * The schedule clock the two pumps share: fires due rows, retires settled
 * markers and queues ephemeral `/loop` prompts. Lives in a trait so the
 * ratcheted src/Chat.php stays under its line ceiling
 * (tests/Support/SourceSizeRatchetTest.php); it reads and writes the same
 * Chat properties, so behavior is unchanged.
 */
trait HandlesScheduledPumps
{
    /**
     * AUT-1's clock, run by the pumps (both hosts share the shape): reload if
     * the file moved, fire PERSISTED due rows as supervised daemons — behind
     * per-row file locks, so a second TUI or `serve` claiming the same row
     * skips instead of double-firing — retire the fired markers whose sessions
     * settled, and turn EPHEMERAL `/loop` due rows into queued prompts.
     *
     * Bounds here are due-COUNTS, never timeouts (E646): a row missed a week
     * of slots fires once and re-arms for the next live one
     * ({@see Schedule::nextDueAfter()}), and a row whose previous run is still
     * up is skipped-but-advanced rather than stacked.
     *
     * @return array{0: list<Message>, 1: list<string>, 2: ?\Closure} notice rows, queued prompts,
     *         and the Cmd that delivers the launch verdicts still pending (null when none)
     */
    private function pumpSchedules(int $now): array
    {
        $registry = $this->scheduleRegistry;
        if ($registry === null) {
            return [[], [], null];
        }
        $registry->reloadIfChanged();
        $notices = [];
        $fireCmd = null;
        $supervisor = $this->backgroundSupervisor;
        if ($supervisor !== null) {
            // A launch answers when its daemon authenticates, so the verdicts
            // usually arrive on a later loop turn. The batch is all-or-nothing:
            // when every launch in it has already settled, its verdicts land in
            // this tick's transcript; when any is still pending, the WHOLE batch
            // (already-decided refusals included) rides a Cmd as ScheduleFiredMsg
            // and lands together once the last launch settles.
            $fired = $registry->fireDue($now, \SugarCraft\Code\Background\ScheduleSpawner::closure(
                $supervisor,
                $this->agentManager,
                $this->projectRoot,
            ));
            $verdicts = null;
            $fired->then(static function (array $results) use (&$verdicts): void {
                $verdicts = $results;
            });
            if ($verdicts !== null) {
                $notices = ScheduleVerdicts::announce($verdicts, $this->dispatchOutboundWebhook(...));
            } else {
                $fireCmd = Cmd::promise(static fn (): PromiseInterface => $fired->then(
                    static fn (array $results): Msg => new ScheduleFiredMsg($results),
                ));
            }
            // A fired row's daemon settled (or vanished): the row may claim
            // its next slot. This runs on the NEXT tick's fireDue, so a row
            // settling and becoming due in the same second fires once, not
            // twice, per pump.
            foreach ($registry->firedNames() as $name) {
                $sessionId = $registry->busySession($name);
                $session = $sessionId === null ? null : $supervisor->session($sessionId);
                if ($session === null || $session->isSettled()) {
                    $registry->clearFired($name);
                }
            }
        }
        $queued = [];
        foreach ($registry->due($now) as $row) {
            if ($row->persisted) {
                continue;
            }
            // Ephemeral rows live in this process only: no lock to claim, no
            // file to advance, the prompt rides the queue like a settled
            // announcement.
            $registry->noteNextDue($row->name, $row->nextDueAfter($now));
            $queued[] = $row->prompt;
            $notices[] = Message::notice(Lang::t('chat.schedule.loop_queued', ['name' => $row->name]));
        }

        return [$notices, $queued, $fireCmd];
    }
}
