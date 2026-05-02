<?php

declare(strict_types=1);

namespace CandyCore\Crush\Backend;

use CandyCore\Crush\Backend;
use CandyCore\Crush\Message;
use CandyCore\Crush\Role;

/**
 * Offline / development backend. Echoes the last user message
 * back, wrapped in a small Markdown frame so the rendering path
 * still gets exercised. Used as the default in `bin/sugarcrush`
 * (so the binary is runnable without network) and in every test.
 */
final class EchoBackend implements Backend
{
    public function complete(array $history): Message
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
}
