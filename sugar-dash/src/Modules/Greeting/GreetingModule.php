<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Greeting;

use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Greeting module that displays a time-of-day based greeting.
 */
final class GreetingModule extends BaseModule
{
    public function name(): string
    {
        return 'greeting';
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $greeting = $this->getGreeting();
        return [$this->withState(['greeting' => $greeting]), null];
    }

    public function view(): string
    {
        $state = $this->getState();
        return $state['greeting'] ?? $this->getGreeting();
    }

    public function minSize(): array
    {
        return [15, 3];
    }

    private function getGreeting(): string
    {
        $hour = (int) date('G');

        return match (true) {
            $hour >= 5 && $hour < 12 => 'Good morning',
            $hour >= 12 && $hour < 17 => 'Good afternoon',
            $hour >= 17 && $hour < 21 => 'Good evening',
            default => 'Good night',
        };
    }
}
