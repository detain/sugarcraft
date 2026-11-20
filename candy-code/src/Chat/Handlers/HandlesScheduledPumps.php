<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat\Handlers;

use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

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
     * @return array{0: list<Message>, 1: list<string>} notice rows, queued prompts
     */
    private function pumpSchedules(int $now): array
    {
        $registry = $this->scheduleRegistry;
        if ($registry === null) {
            return [[], []];
        }
        $registry->reloadIfChanged();
        $notices = [];
        $supervisor = $this->backgroundSupervisor;
        if ($supervisor !== null) {
            foreach ($registry->fireDue($now, \SugarCraft\Code\Background\ScheduleSpawner::closure(
                $supervisor,
                $this->agentManager,
                $this->projectRoot,
            )) as $result) {
                $notice = match ($result['action']) {
                    'fired' => Lang::t('chat.schedule.fired', ['name' => $result['name']]),
                    'error' => Lang::t('chat.schedule.spawn_failed', [
                        'name' => $result['name'],
                        'error' => $result['error'] ?? '',
                    ]),
                    default => null,
                };
                if ($notice !== null) {
                    $notices[] = Message::notice($notice);
                }
                // C-AGT-5.f: the same verdict, announced to outbound webhook
                // subscribers as `schedule.fired`. Only the two decision rows
                // the operator hears about ride out — a skip stays quiet
                // because the TUI itself does not notice it either, and the
                // `outcome` word is the transcript's own verdict vocabulary
                // (`launched` / `spawn_failed`), not the raw action name.
                if ($result['action'] === 'fired' || $result['action'] === 'error') {
                    $this->dispatchOutboundWebhook(WebhookEvent::scheduleFired(
                        (string) ($result['sessionId'] ?? ''),
                        (string) $result['name'],
                        $result['action'] === 'fired' ? 'launched' : 'spawn_failed',
                    ));
                }
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

        return [$notices, $queued];
    }
}
