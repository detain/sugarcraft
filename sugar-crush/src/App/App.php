<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tui\Pane;

/**
 * Main application state - immutable with*() builders.
 * Mirrors the canonical candy-sprinkles/src/Style.php pattern.
 */
final class App
{
    private function __construct(
        public readonly ProviderInterface $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools,
        public readonly Pane $pane,
        public readonly ?string $error,
        public readonly ?string $status,
        public readonly ?string $sessionId,
        public readonly array $contextFiles,
        public readonly array $enabledSkills,
        public readonly SkillRegistry $availableSkills,
        public readonly array $activeHooks,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self(
            provider: $provider,
            model: $model,
            messages: [],
            tools: [],
            pane: Pane::Chat,
            error: null,
            status: null,
            sessionId: null,
            contextFiles: [],
            enabledSkills: [],
            availableSkills: new SkillRegistry(),
            activeHooks: [],
        );
    }

    // Immutable with*() builders
    public function withProvider(ProviderInterface $v): self
    {
        return $this->mutate(provider: $v);
    }

    public function withModel(string $v): self
    {
        return $this->mutate(model: $v);
    }

    public function withMessages(array $v): self
    {
        return $this->mutate(messages: $v);
    }

    public function withTools(array $v): self
    {
        return $this->mutate(tools: $v);
    }

    public function withPane(Pane $v): self
    {
        return $this->mutate(pane: $v);
    }

    public function withError(?string $v): self
    {
        return $this->mutate(error: $v);
    }

    public function withStatus(?string $v): self
    {
        return $this->mutate(status: $v);
    }

    public function withSessionId(?string $v): self
    {
        return $this->mutate(sessionId: $v);
    }

    public function withContextFiles(array $v): self
    {
        return $this->mutate(contextFiles: $v);
    }

    public function withEnabledSkills(array $v): self
    {
        return $this->mutate(enabledSkills: $v);
    }

    public function withAvailableSkills(SkillRegistry $registry): self
    {
        return $this->mutate(availableSkills: $registry);
    }

    public function withActiveHooks(array $v): self
    {
        return $this->mutate(activeHooks: $v);
    }

    /**
     * Apply enabled skills to the base system prompt.
     */
    public function applySkillsToSystemPrompt(string $baseSystemPrompt): string
    {
        $result = $baseSystemPrompt;

        foreach ($this->enabledSkills as $skill) {
            if ($skill instanceof Skill) {
                $result .= $skill->systemPromptContribution();
            }
        }

        return $result;
    }

    /**
     * Find skills that match a task description.
     *
     * @return array<Skill>
     */
    public function findSkillsForTask(string $task): array
    {
        return $this->availableSkills->findForPrompt($task);
    }

    /**
     * Add a message to the conversation.
     */
    public function withMessage(Message $msg): self
    {
        return $this->mutate(messages: [...$this->messages, $msg]);
    }

    /**
     * Update the state from a message.
     * Returns [newApp, command] where command is a Cmd to execute or null.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof UserInputMsg => $this->handleUserInput($msg),
            $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
            $msg instanceof ToolResultMsg => $this->handleToolResult($msg),
            $msg instanceof ErrorMsg => [$this->withError($msg->message), null],
            $msg instanceof StatusMsg => [$this->withStatus($msg->message), null],
            default => [$this, null],
        };
    }

    /**
     * Handle user input message.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleUserInput(UserInputMsg $msg): array
    {
        $userMsg = new UserMessage($msg->content);
        $newApp = $this->withMessage($userMsg);
        // The actual AI call happens in the runtime loop
        return [$newApp, new RunCompletionCmd($userMsg)];
    }

    /**
     * Handle tool result message.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleToolResult(ToolResultMsg $msg): array
    {
        $toolMsg = new ToolResultMessage($msg->toolCallId, $msg->content, $msg->isError);

        return [$this->withMessage($toolMsg), null];
    }

    /**
     * Rebuild the immutable App with the named changes applied.
     *
     * Uses array_key_exists (not ??) so that nullable fields — error,
     * status, sessionId — can be reset to null. A readonly property
     * cannot be reassigned after construction, so we always go through
     * the constructor rather than clone-and-mutate.
     */
    private function mutate(mixed ...$changes): self
    {
        return new self(
            provider: array_key_exists('provider', $changes) ? $changes['provider'] : $this->provider,
            model: array_key_exists('model', $changes) ? $changes['model'] : $this->model,
            messages: array_key_exists('messages', $changes) ? $changes['messages'] : $this->messages,
            tools: array_key_exists('tools', $changes) ? $changes['tools'] : $this->tools,
            pane: array_key_exists('pane', $changes) ? $changes['pane'] : $this->pane,
            error: array_key_exists('error', $changes) ? $changes['error'] : $this->error,
            status: array_key_exists('status', $changes) ? $changes['status'] : $this->status,
            sessionId: array_key_exists('sessionId', $changes) ? $changes['sessionId'] : $this->sessionId,
            contextFiles: array_key_exists('contextFiles', $changes) ? $changes['contextFiles'] : $this->contextFiles,
            enabledSkills: array_key_exists('enabledSkills', $changes) ? $changes['enabledSkills'] : $this->enabledSkills,
            availableSkills: array_key_exists('availableSkills', $changes) ? $changes['availableSkills'] : $this->availableSkills,
            activeHooks: array_key_exists('activeHooks', $changes) ? $changes['activeHooks'] : $this->activeHooks,
        );
    }
}

// Msg types (internal)
interface Msg {}

/**
 * Message from user input.
 */
final readonly class UserInputMsg implements Msg
{
    public function __construct(public string $content) {}
}

/**
 * Message to select a pane.
 */
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

/**
 * Message containing tool execution result.
 */
final readonly class ToolResultMsg implements Msg
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {}
}

/**
 * Error message.
 */
final readonly class ErrorMsg implements Msg
{
    public function __construct(public string $message) {}
}

/**
 * Status update message.
 */
final readonly class StatusMsg implements Msg
{
    public function __construct(public string $message) {}
}

// Cmd types (side-effects to execute)
interface Cmd {}

/**
 * Command to run completion.
 */
final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}

/**
 * Command to call a tool.
 */
final readonly class CallToolCmd implements Cmd
{
    public function __construct(public string $toolName, public array $args) {}
}
