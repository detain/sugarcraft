<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Hook;

use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Hook that injects metadata into the cassette header or adds annotations
 * to events. Useful for CI tracking, test identification, or debugging
 * context.
 *
 * Usage:
 * ```php
 * $recorder->addHook(new MetadataHook([
 *     'CI_RUN_ID' => getenv('GITHUB_RUN_ID'),
 *     'test_name' => 'MyTest::testViewOutput',
 * ]));
 * ```
 */
final class MetadataHook implements Hook
{
    /** @var array<string, string> */
    private array $metadata;

    /** @var bool */
    private bool $firstEvent = true;

    /**
     * @param array<string, string> $metadata Key-value pairs to inject
     */
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    public function beforeSave(Event $event): ?Event
    {
        // Add metadata to the first output event's payload as a marker
        if ($this->firstEvent && $event->kind === EventKind::Output) {
            $this->firstEvent = false;
            $payload = $event->payload;
            $payload['__meta'] = $this->metadata;
            return new Event($event->t, $event->kind, $payload);
        }
        return $event;
    }

    public function afterCapture(Event $event): void
    {
        // No-op
    }
}
