<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat;

use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * The transcript line and the outbound webhook a schedule fire verdict earns
 * (AUT-1, C-AGT-5.f), shared by the pump that decided the verdict and the
 * message that delivers one that settled later.
 *
 * Only the two verdicts the operator hears about are announced — `fired` as a
 * launch and `error` as a refused spawn. A skip stays quiet on both surfaces,
 * because the TUI itself does not notice it either.
 */
final class ScheduleVerdicts
{
    private function __construct()
    {
    }

    /**
     * @param list<array{name: string, action: string, sessionId?: string, error?: string}> $results
     *        the {@see \SugarCraft\Code\Background\ScheduleRegistry::fireDue()} verdicts, in due order
     * @param \Closure(WebhookEvent): void $webhook hands each announced verdict to the outbound dispatcher
     * @return list<Message> the transcript notices, in verdict order
     */
    public static function announce(array $results, \Closure $webhook): array
    {
        $notices = [];
        foreach ($results as $result) {
            $notice = match ($result['action']) {
                'fired' => Lang::t('chat.schedule.fired', ['name' => $result['name']]),
                'error' => Lang::t('chat.schedule.spawn_failed', ['name' => $result['name'], 'error' => $result['error'] ?? '']),
                default => null,
            };
            if ($notice !== null) {
                $notices[] = Message::notice($notice);
                // The `outcome` word is the transcript's own verdict vocabulary
                // (`launched` / `spawn_failed`), not the raw action name.
                $webhook(WebhookEvent::scheduleFired(
                    (string) ($result['sessionId'] ?? ''),
                    (string) $result['name'],
                    $result['action'] === 'fired' ? 'launched' : 'spawn_failed',
                ));
            }
        }

        return $notices;
    }
}
