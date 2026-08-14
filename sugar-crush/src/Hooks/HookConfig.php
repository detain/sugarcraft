<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses the YAML hook file into the plain config arrays
 * {@see ScriptHook::fromConfig()} consumes.
 *
 * The shape a hook author writes:
 *
 * ```yaml
 * hooks:
 *   PreToolUse:
 *     - name: confirm-deploy      # optional; defaults to `command`
 *       matcher: '^Bash$'
 *       command: ./hooks/confirm-deploy.sh
 *       description: Ask before anything touches production
 * ```
 *
 * What the script's EXIT CODE means — 0 allow, 1/2 deny, 3 ask, 4 modify — is
 * documented in full on {@see ScriptHook}, which is the class that reads it.
 * Exit 3 (ask) is the one that reaches the blocking permission prompt, and is
 * the reason this file format is worth anything beyond allow/deny.
 *
 * `name` is carried through because {@see HookRegistry} keys hooks by name:
 * without it, two entries sharing a command on one event silently collapse
 * into a single registration.
 */
final class HookConfig
{
    /**
     * Load hooks from YAML file.
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string}>
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
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string}>
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
                $command = $config['command'] ?? '';

                $hooks[] = [
                    'name' => (string) ($config['name'] ?? $command),
                    'event' => $event,
                    'matcher' => $config['matcher'] ?? '.*',
                    'command' => $command,
                    'description' => $config['description'] ?? '',
                ];
            }
        }

        return $hooks;
    }
}
