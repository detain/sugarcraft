<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * A hook that executes an external script.
 */
final readonly class ScriptHook implements HookInterface
{
    public function __construct(
        private string $name,
        private HookEvent $event,
        private string $matcher,
        private string $command,
        private string $description,
    ) {}

    /**
     * Create a ScriptHook from a config array.
     */
    public static function fromConfig(array $config): self
    {
        $eventString = $config['event'] ?? 'PreToolUse';
        $event = HookEvent::tryFrom($eventString) ?? HookEvent::PreToolUse;

        return new self(
            name: $config['command'] ?? uniqid('hook_'),
            event: $event,
            matcher: $config['matcher'] ?? '.*',
            command: $config['command'] ?? '',
            description: $config['description'] ?? '',
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function matcher(): string
    {
        return $this->matcher;
    }

    public function execute(HookContext $context): HookResult
    {
        $env = [
            'CRUSH_SESSION_ID' => $context->sessionId,
            'CRUSH_TOOL_NAME' => $context->toolName,
            'CRUSH_TOOL_INPUT' => $context->toolInput,
            'CRUSH_TOOL_OUTPUT' => $context->toolOutput,
            'CRUSH_MODEL' => $context->model,
            'CRUSH_PROVIDER' => $context->provider,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $context->projectRoot,
            $env
        );

        if (!is_resource($process)) {
            return HookResult::allow();
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return HookResult::allow(trim($output));
        }

        return HookResult::deny(trim($errors) ?: "Hook exited with code $exitCode");
    }
}
