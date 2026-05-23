<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Model;

use SugarCraft\Bits\Spinner\Spinner;
use SugarCraft\Bits\Spinner\Style as SpinStyle;
use SugarCraft\Bits\Spinner\TickMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Process\Process;

/**
 * Run a child {@see Process} while a {@see Spinner} animates beside an
 * optional status title. Each spinner tick polls the process; when the
 * child exits, the model captures its code and stops the loop. Pressing
 * `Esc` or `Ctrl+C` terminates the child early and exits with -1.
 */
final class SpinModel implements Model
{
    public static function spawn(
        Process $process,
        string $title = '',
        ?SpinStyle $style = null,
        string $align = 'left',
    ): self {
        return new self(
            Spinner::new($style ?? SpinStyle::dot()),
            $process,
            $title,
            false,
            null,
            $align,
        );
    }

    private function __construct(
        public readonly Spinner $spinner,
        public readonly Process $process,
        public readonly string $title,
        public readonly bool $done,
        public readonly ?int $exitCode,
        public readonly string $align = 'left',
    ) {}

    public function init(): ?\Closure
    {
        return $this->spinner->init();
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->done) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg
            && ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c'))) {
            $this->process->terminate();
            return [self::finish($this->spinner, $this->process, $this->title, -1, $this->align), Cmd::quit()];
        }
        if ($msg instanceof TickMsg && $msg->id === $this->spinner->id) {
            $code = $this->process->exitCode();
            if ($code !== null) {
                return [self::finish($this->spinner, $this->process, $this->title, $code, $this->align), Cmd::quit()];
            }
            [$next, $cmd] = $this->spinner->update($msg);
            return [new self($next, $this->process, $this->title, false, null, $this->align), $cmd];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $body = $this->title === ''
            ? $this->spinner->view()
            : ($this->align === 'right'
                ? $this->title . ' ' . $this->spinner->view()
                : $this->spinner->view() . ' ' . $this->title);
        return $body;
    }

    public function isDone(): bool { return $this->done; }
    public function exitCode(): ?int { return $this->exitCode; }

    private static function finish(Spinner $s, Process $p, string $title, int $exit, string $align): self
    {
        return new self($s, $p, $title, true, $exit, $align);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
