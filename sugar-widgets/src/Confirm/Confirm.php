<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Confirm;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Widgets\Lang;

/**
 * A yes/no prompt that can demand a typed confirmation word before a
 * destructive action.
 *
 * `Confirm::new($prompt)` answers on one key: `y`/`Y` confirms, `n`/`N` or
 * Esc cancels, and every other key is ignored, so a stray Enter never
 * confirms. `withTypedConfirm('delete')` raises the bar: printable keys and
 * Space type into a buffer, Backspace deletes, and Enter submits. Only the
 * exact word confirms (case-sensitive, no trimming); a wrong word leaves the
 * prompt pending with the text kept for correction and {@see isRejected()}
 * set until the next edit. Esc cancels in both modes.
 *
 * The key that resolves the prompt returns a Cmd that sends a
 * {@see ConfirmResolvedMsg}; every other key returns a null Cmd, and once
 * answered the prompt ignores keys until {@see reset()}. Unlike Tabs or
 * Disclosure there is no focus gate: a confirm is modal, so the host routes
 * keys to it only while it is shown. Key releases and Ctrl or Alt chords are
 * ignored, so a terminal reporting press and release answers once.
 *
 * The prompt and the word are sanitized to one line and stripped of zone
 * sentinels, as Disclosure does for its title, and typed keys are cleaned
 * the same way, so a pasted escape never reaches the terminal and the typed
 * text compares against the word in the same form. The prompt is printed
 * as given; a host that wants the word spelled out puts it in the prompt.
 *
 * Immutable: every `with*()`, {@see reset()} and {@see update()} returns a
 * new instance through `mutate()` from sugar-core `Concerns\Mutable`. A
 * `with*()` setter applied to an answered prompt re-arms it: the result is
 * pending with no typed text, so a stale answer never stands for a question
 * or word that was not answered.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no confirm component,
 * so there is no upstream Mirrors citation (huh's Confirm field is ported
 * separately as sugar-forms `Field\Confirm`, a form toggle with no typed
 * word).
 */
final class Confirm implements Model
{
    use Mutable;

    /**
     * @param ?bool $answer null while pending, true once confirmed, false once cancelled
     */
    private function __construct(
        public readonly string $prompt,
        public readonly string $word,
        public readonly string $typed,
        public readonly ?bool $answer,
        public readonly bool $rejected,
    ) {
    }

    public static function new(string $prompt): self
    {
        return new self(
            prompt: self::cleanLine($prompt),
            word: '',
            typed: '',
            answer: null,
            rejected: false,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * @return array{0: Confirm, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->answer !== null
            || !$msg instanceof KeyMsg
            || $msg instanceof KeyReleaseMsg
            || $msg->ctrl
            || $msg->alt
        ) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape) {
            return $this->resolve(false);
        }

        return $this->word === '' ? $this->updateSingleKey($msg) : $this->updateTyped($msg);
    }

    public function view(): string
    {
        if ($this->word === '') {
            return $this->prompt . ' [y/n]';
        }
        return $this->prompt . "\n> " . $this->typed;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    /** The word that must be typed to confirm, or `''` in single-key mode. */
    public function typedConfirm(): string
    {
        return $this->word;
    }

    /** The text typed so far in typed-word mode. */
    public function typed(): string
    {
        return $this->typed;
    }

    public function isConfirmed(): bool
    {
        return $this->answer === true;
    }

    public function isCancelled(): bool
    {
        return $this->answer === false;
    }

    public function isPending(): bool
    {
        return $this->answer === null;
    }

    /** True after a wrong word was submitted, until the next edit. */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * Require `$word` to be typed and submitted with Enter before the prompt
     * confirms. Clears any typed text and makes an answered prompt pending
     * again, since the new word was never typed. A word that is empty once
     * sanitized throws rather than silently falling back to a single `y`.
     *
     * @throws \InvalidArgumentException when the word is empty after sanitizing
     */
    public function withTypedConfirm(string $word): self
    {
        $clean = self::cleanLine($word);
        if ($clean === '') {
            throw new \InvalidArgumentException(Lang::t('confirm.word_empty'));
        }
        return $this->mutate(['word' => $clean, 'typed' => '', 'answer' => null, 'rejected' => false]);
    }

    /** Back to single-key mode; clears any typed text and makes an answered prompt pending again. */
    public function withoutTypedConfirm(): self
    {
        return $this->mutate(['word' => '', 'typed' => '', 'answer' => null, 'rejected' => false]);
    }

    /**
     * A new prompt is a new question, so an answered prompt becomes pending
     * again with no typed text; a pending prompt keeps what was typed.
     */
    public function withPrompt(string $prompt): self
    {
        $clean = self::cleanLine($prompt);
        if ($this->answer === null) {
            return $this->mutate(['prompt' => $clean]);
        }
        return $this->mutate(['prompt' => $clean, 'typed' => '', 'answer' => null, 'rejected' => false]);
    }

    /** Pending again, with no typed text, keeping the prompt and the word. */
    public function reset(): self
    {
        return $this->mutate(['typed' => '', 'answer' => null, 'rejected' => false]);
    }

    /**
     * @return array{0: Confirm, 1: ?\Closure}
     */
    private function updateSingleKey(KeyMsg $msg): array
    {
        if ($msg->type !== KeyType::Char) {
            return [$this, null];
        }
        return match ($msg->rune) {
            'y', 'Y' => $this->resolve(true),
            'n', 'N' => $this->resolve(false),
            default => [$this, null],
        };
    }

    /**
     * @return array{0: Confirm, 1: ?\Closure}
     */
    private function updateTyped(KeyMsg $msg): array
    {
        switch ($msg->type) {
            case KeyType::Enter:
                if ($this->typed === $this->word) {
                    return $this->resolve(true);
                }
                return [$this->rejected ? $this : $this->mutate(['rejected' => true]), null];
            case KeyType::Backspace:
                if ($this->typed === '') {
                    return [$this, null];
                }
                return [$this->mutate(['typed' => mb_substr($this->typed, 0, -1), 'rejected' => false]), null];
            case KeyType::Space:
                return [$this->mutate(['typed' => $this->typed . ' ', 'rejected' => false]), null];
            case KeyType::Char:
                $text = self::cleanLine($msg->rune);
                if ($text === '') {
                    return [$this, null];
                }
                return [$this->mutate(['typed' => $this->typed . $text, 'rejected' => false]), null];
            default:
                return [$this, null];
        }
    }

    /**
     * @return array{0: Confirm, 1: \Closure}
     */
    private function resolve(bool $confirmed): array
    {
        $next = $this->mutate(['answer' => $confirmed, 'rejected' => false]);
        return [$next, Cmd::send(new ConfirmResolvedMsg($next->prompt, $confirmed))];
    }

    /**
     * One line: escapes go first, whole, so no `[31m` remnant survives; the
     * zone sweep keeps a hostile prompt from forging sugar-mouse markers in
     * a marked frame; then the line breaks and tabs that `untrusted()` keeps
     * by design fold to spaces.
     */
    private static function cleanLine(string $text): string
    {
        return str_replace(["\r\n", "\n", "\r", "\t"], ' ', Sanitize::untrustedForMarkedFrames($text));
    }
}
