<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

/**
 * The stdout protocol of {@see StreamingCommandBackend}, fed one read at a time
 * by its ChildRun live feed, and the same for the blocking and the promise entry
 * points so they cannot disagree about what a line means.
 *
 * ONE TOKEN PER TERMINATED LINE. A line is a token only once its newline has
 * arrived, so a read boundary inside a line never splits it; `\r` before the
 * `\n` is removed, and an empty line is the literal `"\n"` token. An unterminated
 * remainder is one last token when the child ends ({@see flush()}).
 *
 * A failing `$onToken` is recorded rather than thrown into the reader, and the
 * `$abort` callback is called once so the child is stopped instead of left to run
 * unread. {@see failure()} hands the exception back to the caller, which rethrows
 * it (complete()) or rejects with it (completeAsync()).
 */
final class StreamingTokenSink
{
    private ?\Closure $onToken;

    private string $partial = '';

    /** @var list<string> */
    private array $tokens = [];

    private ?\Throwable $failure = null;

    /**
     * @param null|callable(string): void $onToken Called once per token, as it is emitted
     * @param \Closure(): void $abort Stops the child when $onToken throws
     */
    public function __construct(?callable $onToken, private readonly \Closure $abort)
    {
        $this->onToken = $onToken === null ? null : \Closure::fromCallable($onToken);
    }

    /** Offer the bytes one stdout read returned; every line they complete is emitted. */
    public function offer(string $chunk): void
    {
        if ($this->failure !== null) {
            return;
        }

        $this->partial .= $chunk;
        while (($newline = strpos($this->partial, "\n")) !== false) {
            $line = substr($this->partial, 0, $newline);
            $this->partial = substr($this->partial, $newline + 1);
            $this->emit(self::tokenForLine($line));
            if ($this->failure !== null) {
                return;
            }
        }
    }

    /**
     * The bytes of an unterminated last line were still paid for. An empty
     * remainder after its trailing `\r` is no line at all, so it is not emitted.
     */
    public function flush(): void
    {
        if ($this->partial === '') {
            return;
        }

        $token = rtrim($this->partial, "\r");
        $this->partial = '';
        if ($token !== '' && $this->failure === null) {
            $this->emit($token);
        }
    }

    /** Every token emitted so far, joined with nothing and trimmed at the ends. */
    public function body(): string
    {
        return trim(implode('', $this->tokens));
    }

    public function failure(): ?\Throwable
    {
        return $this->failure;
    }

    private function emit(string $token): void
    {
        $this->tokens[] = $token;
        if ($this->onToken === null) {
            return;
        }

        try {
            ($this->onToken)($token);
        } catch (\Throwable $e) {
            $this->failure = $e;
            ($this->abort)();
        }
    }

    /**
     * One token from one COMPLETE line of stdout, its trailing `\r` removed. An
     * empty line becomes a literal newline, because this protocol cannot otherwise
     * express a line break at all.
     */
    private static function tokenForLine(string $line): string
    {
        $token = rtrim($line, "\r");

        return $token === '' ? "\n" : $token;
    }
}
