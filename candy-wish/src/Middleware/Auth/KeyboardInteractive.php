<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware\Auth;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Challenge-response keyboard-interactive authentication.
 *
 * Implements the SSH_MSG_USERAUTH_INFO_REQUEST exchange: the server
 * sends one or more prompts to the client, the client displays them
 * to the user, collects answers, and sends back SSH_MSG_USERAUTH_INFO_RESPONSE.
 *
 * In a CLI/PHP-FPM context the "client" is the SSH client's stdin.
 * This middleware:
 *
 *   1. Writes Name, Instruction, prompt count, and per-prompt
 *      prompt/echo-flag lines to STDOUT per RFC 4256.
 *   2. Reads newline-delimited responses from STDIN until all
 *      prompts have been answered.
 *   3. Optionally validates the responses via a callback. A validator
 *      that returns `false` rejects the session with a message on
 *      stderr; a validator that returns `true` (or no validator)
 *      passes control to `$next`.
 *
 * Wire format (RFC 4256, SSH_MSG_USERAUTH_INFO_REQUEST):
 *
 *     Name\r\n
 *     Instruction\r\n
 *     NumberOfPrompts\r\n
 *     Prompt1\r\n
 *     Echo1\r\n        <-- 'true' or 'false'
 *     Prompt2\r\n
 *     Echo2\r\n        <-- 'true' or 'false'
 *     ...
 *
 *     Response1\r\n
 *     Response2\r\n
 *     ...
 *
 * @property string $name        RFC-4256 Name field (e.g. "Login")
 * @property string $instruction RFC-4256 Instruction field
 */
final class KeyboardInteractive implements Middleware
{
    /** @var list<array{prompt: string, echo: bool}> */
    private array $challenges;

    /** @var callable(list<string>): bool|null */
    private $validate;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stderr;

    /** RFC-4256 Name field (written as first line of challenge output) */
    private string $name;

    /** RFC-4256 Instruction field (written as second line of challenge output) */
    private string $instruction;

    /**
     * @param list<array{prompt: string, echo?: bool}> $challenges Prompt list
     * @param callable(list<string>): bool|null        $validate   Receives responses, returns true to accept
     * @param string                                   $name       RFC-4256 Name (e.g. "Login")
     * @param string                                   $instruction RFC-4256 Instruction
     * @param resource|null                           $stdout
     * @param resource|null                           $stdin
     * @param resource|null                           $stderr
     */
    public function __construct(
        array $challenges,
        ?callable $validate = null,
        string $name = '',
        string $instruction = '',
        $stdout = null,
        $stdin = null,
        $stderr = null,
    ) {
        $this->challenges = $challenges;
        $this->validate = $validate;
        $this->name = $name;
        $this->instruction = $instruction;
        if ($stdout === null) {
            $stream = fopen('php://stdout', 'w');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stdout');
            }
            $this->stdout = $stream;
        } else {
            $this->stdout = $stdout;
        }
        if ($stdin === null) {
            $stream = fopen('php://stdin', 'r');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stdin');
            }
            $this->stdin = $stream;
        } else {
            $this->stdin = $stdin;
        }
        if ($stderr === null) {
            $stream = fopen('php://stderr', 'w');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stderr');
            }
            $this->stderr = $stream;
        } else {
            $this->stderr = $stderr;
        }
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $this->writeChallenges();
        $responses = $this->readResponses();

        if ($this->validate !== null && !($this->validate)($responses)) {
            fwrite($this->stderr, "Authentication failed.\n");
            return;
        }

        $derived = $ctx->withValue('auth.ki.responses', $responses);
        $next($derived, $session);
    }

    /**
     * Write challenges to stdout in RFC 4256 format.
     *
     * Emits: Name, Instruction, Count, then per-challenge Prompt and Echo flag.
     */
    private function writeChallenges(): void
    {
        $count = \count($this->challenges);
        fwrite($this->stdout, $this->name . "\n");
        fwrite($this->stdout, $this->instruction . "\n");
        fwrite($this->stdout, (string) $count . "\n");
        foreach ($this->challenges as $challenge) {
            $echo = ($challenge['echo'] ?? true) ? 'true' : 'false';
            fwrite($this->stdout, $challenge['prompt'] . "\n");
            fwrite($this->stdout, $echo . "\n");
        }
        fflush($this->stdout);
    }

    /**
     * Read exactly `$count` lines from stdin.
     *
     * @return list<string>
     */
    private function readResponses(): array
    {
        $responses = [];
        $count = \count($this->challenges);
        for ($i = 0; $i < $count; $i++) {
            $line = fgets($this->stdin);
            if ($line === false) {
                break;
            }
            $responses[] = rtrim($line, "\r\n");
        }
        return $responses;
    }
}
