<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

use Symfony\Component\Yaml\Yaml;

final class HookConfig
{
    /**
     * Load hooks from YAML file.
     *
     * @return array<array{event: string, matcher: string, command: string, description: string}>
     */
    public static function loadFromFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        return self::parse($content);
    }

    /**
     * Parse hooks from YAML content.
     *
     * @return array<array{event: string, matcher: string, command: string, description: string}>
     */
    public static function parse(string $content): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (\Exception $e) {
            return [];
        }

        $hooks = [];
        $hooksData = $data['hooks'] ?? [];

        foreach ($hooksData as $event => $configs) {
            foreach ($configs as $config) {
                $hooks[] = [
                    'event' => $event,
                    'matcher' => $config['matcher'] ?? '.*',
                    'command' => $config['command'] ?? '',
                    'description' => $config['description'] ?? '',
                ];
            }
        }

        return $hooks;
    }
}
