<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Greeting;

use SugarCraft\Dash\Module\BaseModule;

/**
 * Greeting module that displays a time-of-day based greeting.
 */
final class GreetingModule extends BaseModule
{
    private string $greeting = '';

    public function name(): string
    {
        return 'greeting';
    }

    public function init(): array
    {
        $this->greeting = $this->getGreeting();
        return [
            'name' => $this->name(),
            'minSize' => [15, 3],
            'interval' => 300,
        ];
    }

    public function update(array $state): array
    {
        $this->greeting = $this->getGreeting();
        $state['greeting'] = $this->greeting;
        return $state;
    }

    public function view(array $state, int $width, int $height): string
    {
        return $this->greeting;
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
