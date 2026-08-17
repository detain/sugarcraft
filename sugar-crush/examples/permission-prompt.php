<?php

declare(strict_types=1);

/**
 * The blocking permission prompt, and what a refusal leaves in the transcript.
 *
 * The modal is raised by a `PreToolUse` hook answering
 * {@see \SugarCraft\Crush\Hooks\HookResult::ask()} — which a stock install has
 * no configured trigger for, so a demo that waited for one would record an
 * empty screen. This seeds the same {@see PermissionRequestMsg} that path
 * dispatches directly into `Chat`'s constructor instead: identical state, no
 * hook config, no tool actually poised to run.
 *
 * Answer with `n` (or Escape) to refuse and `y`/`a` to permit. The recorded
 * tape refuses, because refusing is the branch with something to show
 * afterwards — {@see \SugarCraft\Crush\Renderer::renderToolResults()} draws a
 * denied call's whole icon+name row struck through and leaves the reason
 * un-struck, which is the state worth a demo.
 *
 * `y`/`a` are safe but not interesting. Safe because the seeded request has no
 * gated batch parked with it — the hook path fills `pendingPermissionJobs`
 * alongside the request and this constructor does not, so approving reaches
 * `dispatchToolCalls()` with an empty job list and the `rm -rf build/` is never
 * forked. Not interesting because approving is not a no-op on screen either:
 * the assistant message joins the transcript with its Bash call drawn as a
 * `running` placeholder that no result arrives to replace, and the chat goes
 * in flight behind it. A half-finished call is a worse thing to record than a
 * refused one, which is the other reason the tape answers `n`. The approve
 * path is exercised for real, with tools that actually run, in
 * `tests/ChatTest.php`.
 *
 * @see .vhs/permission.tape
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PermissionRequestMsg;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\ToolCall;

$call = new ToolCall(
    name: 'Bash',
    arguments: ['command' => 'rm -rf build/ && composer install'],
    id: 'call_demo_1',
);

$assistant = Message::assistant(
    "I'll clear the stale build directory and reinstall dependencies."
)->withToolCalls([$call]);

$chat = new Chat(
    history: [
        Message::user('the vendor tree looks stale, can you rebuild it?'),
    ],
    themeName: 'adaptive',
    pendingPermission: new PermissionRequestMsg(
        assistantMessage: $assistant,
        toolCall: $call,
        prompt: 'Run `rm -rf build/ && composer install` in the project root?',
    ),
);

(new Program(App::new(new EchoProvider(), 'echo')->withChat($chat), Chat::programOptions()))->run();
