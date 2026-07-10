<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;

/**
 * Bridges the chat-shell {@see Backend} seam to the full agent engine —
 * a {@see ProviderInterface} driven by the {@see Runtime}, with tools,
 * skills and hooks.
 *
 * This is what makes the merged product *work*: the tested {@see \SugarCraft\Crush\Chat}
 * Model keeps speaking its simple `complete(history): Message` contract,
 * while underneath each turn runs a bounded agentic loop — call the model,
 * execute any tool calls through the hook gate, feed the results back, and
 * repeat until the model stops calling tools (or {@see $maxSteps} is hit).
 *
 * The chassis works in the root {@see Message} value object; the engine
 * works in the typed {@see \SugarCraft\Crush\Messages\Message} hierarchy.
 * Conversion happens here at the seam.
 */
final class EngineBackend implements Backend
{
    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool>   $tools
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly string $model,
        private readonly array $tools = [],
        private readonly array $skills = [],
        private readonly ?HookManager $hookManager = null,
        private readonly int $maxSteps = 8,
        private readonly bool $hooksDisabled = false,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self($provider, $model);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool> $tools
     */
    public function withTools(array $tools): self
    {
        return new self($this->provider, $this->model, $tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function withSkills(array $skills): self
    {
        return new self($this->provider, $this->model, $this->tools, $skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled);
    }

    public function withHooks(HookManager $hookManager): self
    {
        // An explicit hook manager always wins and clears any prior opt-out.
        return new self($this->provider, $this->model, $this->tools, $this->skills, $hookManager, $this->maxSteps, false);
    }

    /**
     * Escape hatch for callers that deliberately want an UNGUARDED engine —
     * no built-in hooks, no custom manager. Everything else is safe-by-default
     * (see {@see resolveHookManager()}), so opting out is an explicit choice.
     */
    public function withoutHooks(): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, null, $this->maxSteps, true);
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, max(1, $maxSteps), $this->hooksDisabled);
    }

    public function complete(array $history, ?callable $onToken = null): Message
    {
        $runtime = new Runtime($this->provider, $this->resolveHookManager());

        $app = App::new($this->provider, $this->model)
            ->withTools($this->tools)
            ->withEnabledSkills($this->skills)
            ->withMessages($this->toTypedMessages($history));

        $lastAssistant = null;

        // Bounded agentic loop: keep running while the model asks for tools.
        // The Runtime resolves one assistant turn + its tool calls per run();
        // we feed the results back and re-run until the model answers without
        // tools — or we hit the step ceiling (guards against runaway loops,
        // which neither sugar-crush nor candy-crush had).
        for ($step = 0; $step < $this->maxSteps; $step++) {
            $assistant = null;
            $toolResults = [];

            foreach ($runtime->run($app) as $message) {
                if ($message instanceof AssistantMessage) {
                    $assistant = $message;
                } elseif ($message instanceof ToolResultMessage) {
                    $toolResults[] = $message;
                }
            }

            if ($assistant !== null) {
                $lastAssistant = $assistant;
            }

            if ($toolResults === []) {
                break; // model answered without calling tools — done
            }

            $app = $app->withMessages([
                ...$app->messages,
                ...($assistant !== null ? [$assistant] : []),
                ...$toolResults,
            ]);
        }

        $content = $lastAssistant?->content() ?? '';
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        return Message::assistant($content);
    }

    public function completeAsync(array $history, ?callable $onToken = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
            try {
                $resolve($this->complete($history, $onToken));
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Resolve the hook manager that gates every tool call this turn.
     *
     * Safe-by-default: a backend constructed without an explicit
     * {@see withHooks()} call still registers the built-in hooks
     * ({@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}) so Bash/Edit/Write
     * tools never run unguarded. Callers opt out explicitly via
     * {@see withoutHooks()}.
     */
    private function resolveHookManager(): HookManager
    {
        if ($this->hookManager !== null) {
            return $this->hookManager;
        }

        $manager = new HookManager(new HookRegistry());
        if (!$this->hooksDisabled) {
            $manager->registerBuiltIns();
        }

        return $manager;
    }

    /**
     * Convert the chassis's root Message history into the engine's typed
     * message hierarchy.
     *
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history): array
    {
        $out = [];
        foreach ($history as $msg) {
            $out[] = match ($msg->role->value) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
                default => new SystemMessage($msg->content),
            };
        }

        return $out;
    }
}
