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
    public function complete(array $history, callable $onToken = null): Message
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

    public function completeAsync(array $history, callable $onToken = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
            try {
                $message = $this->complete($history, $onToken);
                $resolve($message);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }
}
