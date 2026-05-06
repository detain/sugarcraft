<?php

declare(strict_types=1);

/**
 * Print every key the runtime parses. Useful as a sanity check for
 * Kitty / xterm modifier handling.
 *
 *   php examples/print-key.php
 *
 * Press anything; press Ctrl-C to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;

final class PrintKey implements Model
{
    /** @param list<string> $log */
    public function __construct(public readonly array $log = []) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->ctrl && $msg->rune === 'c') {
                return [$this, Cmd::quit()];
            }
            $entry = sprintf(
                'type=%-12s rune=%-6s ctrl=%s alt=%s shift=%s string=%s',
                $msg->type->name,
                $msg->rune === '' ? '∅' : json_encode($msg->rune),
                $msg->ctrl  ? '✓' : '·',
                $msg->alt   ? '✓' : '·',
                $msg->shift ? '✓' : '·',
                $msg->string(),
            );
            $log = array_slice([...$this->log, $entry], -12);
            return [new self($log), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $body = $this->log === []
            ? '(press anything)'
            : implode("\n", $this->log);
        return "Last 12 keys:\n\n$body\n\n(Ctrl-C to quit)\n";
    }
}

(new Program(new PrintKey()))->run();
