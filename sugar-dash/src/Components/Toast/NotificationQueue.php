<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

/**
 * Manages a queue of toast notifications for sequential display.
 *
 * Handles:
 * - Adding toasts to the queue
 * - Retrieving next toast to display
 * - Tracking position and timing
 * - Dismissing toasts
 */
final class NotificationQueue
{
    /**
     * @var array<int, Toast>
     */
    private array $queue = [];

    private int $currentIndex = 0;

    private ?NoticePosition $position = null;

    private int $maxVisible = 3;

    public function __construct(
        private readonly int $displayDuration = 3000,
    ) {}

    /**
     * Add a toast to the queue.
     */
    public function add(Toast $toast): self
    {
        $clone = clone $this;
        $clone->queue[] = $toast;
        return $clone;
    }

    /**
     * Add a toast with a specific level to the queue.
     */
    public function addWithLevel(string $message, Level $level): self
    {
        $toast = match ($level) {
            Level::Info => Toast::info($message),
            Level::Warning => Toast::warning($message),
            Level::Error => Toast::error($message),
            Level::Success => Toast::success($message),
        };

        return $this->add($toast);
    }

    /**
     * Get all queued toasts.
     *
     * @return array<int, Toast>
     */
    public function all(): array
    {
        return $this->queue;
    }

    /**
     * Get the number of toasts in the queue.
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /**
     * Get the next toast to display.
     */
    public function next(): ?Toast
    {
        if ($this->currentIndex >= count($this->queue)) {
            return null;
        }

        return $this->queue[$this->currentIndex];
    }

    /**
     * Advance to the next toast in the queue.
     */
    public function advance(): self
    {
        $clone = clone $this;
        $clone->currentIndex++;
        return $clone;
    }

    /**
     * Reset the queue to the beginning.
     */
    public function reset(): self
    {
        $clone = clone $this;
        $clone->currentIndex = 0;
        return $clone;
    }

    /**
     * Clear all toasts from the queue.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->queue = [];
        $clone->currentIndex = 0;
        return $clone;
    }

    /**
     * Get the display duration in milliseconds.
     */
    public function getDisplayDuration(): int
    {
        return $this->displayDuration;
    }

    /**
     * Set the position for toasts.
     */
    public function withPosition(NoticePosition $position): self
    {
        $clone = clone $this;
        $clone->position = $position;
        return $clone;
    }

    /**
     * Get the position for toasts.
     */
    public function getPosition(): ?NoticePosition
    {
        return $this->position;
    }

    /**
     * Set the maximum number of visible toasts at once.
     */
    public function withMaxVisible(int $max): self
    {
        $clone = clone $this;
        $clone->maxVisible = $max;
        return $clone;
    }

    /**
     * Get the maximum number of visible toasts at once.
     */
    public function getMaxVisible(): int
    {
        return $this->maxVisible;
    }

    /**
     * Check if there are more toasts to display.
     */
    public function hasMore(): bool
    {
        return $this->currentIndex < count($this->queue);
    }

    /**
     * Get visible toasts (for multi-toast display).
     *
     * @return array<int, Toast>
     */
    public function getVisible(): array
    {
        return array_slice($this->queue, $this->currentIndex, $this->maxVisible);
    }
}
