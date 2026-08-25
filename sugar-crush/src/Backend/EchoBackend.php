<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * Offline / development backend. Echoes the last user message
 * back, wrapped in a small Markdown frame so the rendering path
 * still gets exercised. Used as the default in `bin/sugarcrush`
 * (so the binary is runnable without network) and in every test.
 */
final class EchoBackend implements Backend
{
    /**
     * $onEvent is accepted and ignored: this backend never calls a tool, so it
     * has no {@see \SugarCraft\Crush\Events\ToolStarted}/{@see
     * \SugarCraft\Crush\Events\ToolFinished} lifecycle to report.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $lastUser = null;
        foreach (array_reverse($history) as $m) {
            if ($m->role === Role::User) {
                $lastUser = $m;
                break;
            }
        }
        $body = $lastUser === null
            ? "_No user message in history yet._"
            : "You said:\n\n> " . str_replace("\n", "\n> ", $lastUser->content);
        return Message::assistant($body);
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken, $cancellation): void {
            if ($cancellation?->isCancelled() === true) {
                $reject(new \RuntimeException('Request cancelled'));

                return;
            }
            try {
                $message = $this->complete($history, $onToken);
                $resolve($message);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }
}
