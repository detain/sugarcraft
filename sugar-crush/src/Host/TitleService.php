<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\PromptSuggestionMsg;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Session\TitleSource;
use SugarCraft\Crush\SessionTitledMsg;

/**
 * The two cheap side-calls a session makes on its tool-less title backend:
 * the once-per-session auto-title, and the after-every-turn guess at the
 * user's next prompt (roadmap O-2d, Appendix O §4.2 "Dispatch … title
 * scheduling → `Host\TitleService`").
 *
 * WHY IT IS ITS OWN SERVICE. Both calls used to be built inside `Chat`, so a
 * host with no TUI — the server Appendix O designs — could not title a
 * session without a screen. Everything here is the decision (should this
 * turn ask?), the request (what the model is shown) and the reading of the
 * answer (sanitising, and the conditional store write); none of it touches
 * terminal state. `Chat` reaches it through
 * {@see WorkspaceContext::service()} and only wraps the call in a `Cmd`, so
 * the TUI and a server title sessions by one rule.
 *
 * WHAT A CALL RETURNS is a thunk — `\Closure(): PromiseInterface<?Msg>` —
 * rather than a started promise: the caller decides when the request goes
 * out (`Chat` hands it to `Cmd::promise()`, a server to its loop), and a
 * gate that says "not this turn" is a null before any I/O. The promise
 * resolves to the same {@see SessionTitledMsg} / {@see PromptSuggestionMsg}
 * `Chat::update()` folds, which carry the call's usage whatever became of
 * the answer — every provider call on the user's key reaches the tracker.
 *
 * ONLY EVER THE TOOL-LESS TITLE BACKEND, never a fallback to the main
 * conversation backend (audit 15b-12). The main backend may be agentic or
 * tool-armed: on the `SUGARCRUSH_BACKEND_CMD[_STREAM]` tier it is an external
 * command — often an agentic CLI — that would receive the first prompt a
 * SECOND time and could act on it twice, and an `EngineBackend` would run a
 * full tool-enabled turn in a fork to produce four to eight words. No title
 * backend, no title and no suggestion; `/rename` still names a session.
 *
 * Immutable like every value in this package; the one setting it holds is
 * whether prompt suggestions are offered at all, which a host with no input
 * box to paint them into turns off.
 */
final class TitleService
{
    /**
     * One-shot prompt for the background title call. Deliberately terse:
     * opencode's title agent sends barely more than "Generate a title for
     * this conversation" and a cheap model does worse, not better, with an
     * elaborate system prompt.
     */
    public const TITLE_PROMPT = 'Generate a session title in 4-8 words summarising this conversation. Reply with the title only: one line, no quotes, no trailing punctuation.';

    /**
     * Longest auto-title we keep. Matches opencode's own 100-char cap; a
     * tab strip has nowhere to put more than that anyway.
     */
    public const TITLE_MAX_CHARS = 100;

    /**
     * The guess-the-next-prompt call's framing ({@see suggestionCall()}).
     * The request rides as a final USER turn, after the conversation, so a
     * provider that wants the last word to be the user's gets it.
     */
    public const PROMPT_SUGGESTION_PROMPT = 'You predict what the user of a coding assistant will type next. '
        . 'You are shown the recent conversation between the user and the assistant.';

    public const PROMPT_SUGGESTION_REQUEST = 'Write the single message I (the user) am most likely to send next, '
        . 'in my voice, as I would type it: one short line, under 15 words, no quotes, no explanation. '
        . 'Prefer a concrete next step that follows from the last reply. '
        . 'If there is no useful next message, reply with exactly: NONE';

    /** The model's "nothing to suggest" answer; {@see sanitizeSuggestion()} maps it to ''. */
    public const PROMPT_SUGGESTION_NONE = 'NONE';

    /** Messages of recent history the suggestion call is shown. */
    public const PROMPT_SUGGESTION_HISTORY = 12;

    /** Characters of each of those messages it is shown. */
    public const PROMPT_SUGGESTION_MESSAGE_CHARS = 2000;

    /** Longest suggestion kept - it is one line of the input box. */
    public const PROMPT_SUGGESTION_MAX_CHARS = 200;

    private function __construct(
        public readonly bool $promptSuggestions,
    ) {
    }

    /** Titles on, suggestions on (still subject to the env switch and the spend cap). */
    public static function new(): self
    {
        return new self(promptSuggestions: true);
    }

    /**
     * A copy that does (or never does) offer prompt suggestions. Off is for a
     * host with no input box to paint a ghost suggestion into; the
     * `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS` switch still turns them off on
     * top of an "on" here.
     */
    public function withPromptSuggestions(bool $on): self
    {
        return $this->mutate(['promptSuggestions' => $on]);
    }

    /**
     * The auto-title request for a session, or null when this turn should not
     * make one.
     *
     * Fires at most once per session, on the first real user turn, and only
     * when there is a store to persist into and no name yet (a manual
     * `/rename` or a prior auto-title both latch the name). Mirrors opencode's
     * `ensureTitle()` gating. The user turn is counted over what the model
     * sees (audit 15b-03): a `/permissions` echo is a user ROW but not a user
     * TURN, and counting it meant a session whose first input was a command
     * was never titled at all.
     *
     * The request is built here and nowhere else, with no `$onToken` /
     * `$onEvent` / cancellation threaded through: opencode's #20269 was a
     * main-turn parameter leaking into this cheap side-call via a shared
     * builder and silently killing titling.
     *
     * The store write is CONDITIONAL — {@see SessionStore::renameSessionIfUnnamed()},
     * which records {@see TitleSource::Auto} and refuses a row a user has named
     * (audit B2): a `/rename` typed while this request was in flight wins.
     *
     * Failure is silent TO THE USER by design — a session that stays unnamed is
     * a non-event — but never to the SPEND TRACKER: an unusable title and a
     * refused or failed rename still resolve to a {@see SessionTitledMsg}
     * whose title is '' and whose only job is to carry the call's cost. Only a
     * rejected request resolves to null, because a rejection carries no figure.
     *
     * NOT GATED BY THE SPEND CAP, and that is a measured claim rather than an
     * omission: `Chat` only asks from its turn-dispatch tail, which sits after
     * the turn-level spend-cap refusal, so a session already at its cap never
     * reaches here; the one window is the turn that CROSSES the cap, whose cost
     * is not known until after this call has gone out.
     *
     * @param list<Message> $history the transcript as it stands with the turn being dispatched
     *
     * @return (\Closure(): PromiseInterface<?Msg>)|null
     */
    public function titleCall(
        ?Backend $titleBackend,
        SessionStore|EnhancedSessionStore|null $store,
        ?string $sessionId,
        ?string $currentName,
        array $history,
    ): ?\Closure {
        if ($store === null || $sessionId === null || $currentName !== null || $titleBackend === null) {
            return null;
        }

        $visible = Message::agentVisible($history);
        $userTurns = 0;
        foreach ($visible as $message) {
            if ($message->role === Role::User) {
                ++$userTurns;
            }
        }
        if ($userTurns !== 1) {
            return null;
        }

        return self::titleThunk($titleBackend, $visible, $sessionId, $store);
    }

    /**
     * The title request made ON DEMAND — `/rename --auto`, or a blank inline
     * rename (roadmap P-A4) — or null when there is nothing to ask with.
     *
     * {@see titleCall()} without its two once-per-session gates: the caller
     * has just made the session unnamed again
     * ({@see SessionStore::clearSessionName()}), so "no name yet" holds by
     * construction, and the first-turn gate is exactly what an on-demand
     * request bypasses. What stays: a store, a session, the tool-less title
     * backend (audit 15b-12) and at least one agent-visible user turn — a
     * session with nothing said yet is named by its first reply instead.
     *
     * The write is the same conditional one, so a name the user types while
     * this request is in flight still wins (audit B2).
     *
     * @param list<Message> $history
     *
     * @return (\Closure(): PromiseInterface<?Msg>)|null
     */
    public function regenerateCall(
        ?Backend $titleBackend,
        SessionStore|EnhancedSessionStore|null $store,
        ?string $sessionId,
        array $history,
    ): ?\Closure {
        if ($store === null || $sessionId === null || $titleBackend === null) {
            return null;
        }

        $visible = Message::agentVisible($history);
        foreach ($visible as $message) {
            if ($message->role === Role::User) {
                return self::titleThunk($titleBackend, $visible, $sessionId, $store);
            }
        }

        return null;
    }

    /**
     * The one title request both entry points send: {@see TITLE_PROMPT} over
     * the agent-visible conversation, answered by a conditional store write.
     *
     * @param list<Message> $visible
     *
     * @return \Closure(): PromiseInterface<?Msg>
     */
    private static function titleThunk(
        Backend $titleBackend,
        array $visible,
        string $sessionId,
        SessionStore|EnhancedSessionStore $store,
    ): \Closure {
        $titlePrompt = [Message::system(self::TITLE_PROMPT), ...$visible];

        return static function () use ($titleBackend, $titlePrompt, $sessionId, $store): PromiseInterface {
            return $titleBackend->completeAsync($titlePrompt)->then(
                static function (Message $msg) use ($store, $sessionId): ?Msg {
                    // Every exit from here resolves to a Msg, including the two
                    // that produce no title, because the Msg is also what
                    // carries the call's COST to the tracker. An empty title is
                    // dropped by the consumer; the usage is not.
                    $title = self::sanitizeTitle($msg->content);
                    if ($title === '') {
                        return new SessionTitledMsg($sessionId, '', $msg->usage);
                    }
                    try {
                        // Conditional (audit B2): a `/rename` typed while this
                        // request was in flight already named the row, and the
                        // user's name wins. A refused write is reported like an
                        // unusable title — usage only, nothing to latch.
                        if (!$store->renameSessionIfUnnamed($sessionId, $title)) {
                            return new SessionTitledMsg($sessionId, '', $msg->usage);
                        }
                    } catch (\Throwable) {
                        // AN HONEST GAP: this exit is the same construction as the
                        // empty-title one above, which IS pinned
                        // (ChatTest::testAnEmptyGeneratedTitleIsNeverPersistedButItsCostStillIs),
                        // but it has no test of its own. Both store classes are
                        // `final`, so a throwing store cannot be substituted, and
                        // provoking a real PDO write failure mid-suite (a chmod'd
                        // sqlite file) is not deterministic across the users CI
                        // runs as. Named rather than faked with a presence check.
                        return new SessionTitledMsg($sessionId, '', $msg->usage);
                    }

                    return new SessionTitledMsg($sessionId, $title, $msg->usage);
                },
                // Nothing on a rejection, and deliberately silent: there is no
                // Message, so no figure, and nothing for the user to be told.
                static fn (\Throwable $e): ?Msg => null,
            );
        };
    }

    /**
     * The guess-the-next-prompt request after a settled turn, or null when this
     * settle should not ask.
     *
     * Skipped when this service has suggestions off, when
     * `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS` is set, once the spend cap is
     * reached — unlike the once-per-session title this fires after every turn,
     * so it is exactly the kind of call-on-the-app's-initiative a cap exists to
     * stop — and when the last agent-visible row is not a non-empty assistant
     * reply (audit 15b-03: a guess should follow the conversation, not
     * `/help`'s listing). Only the last {@see PROMPT_SUGGESTION_HISTORY}
     * messages go out, each clipped to {@see PROMPT_SUGGESTION_MESSAGE_CHARS}:
     * the guess needs the drift of the conversation, not every tool dump in it.
     *
     * The resolved {@see PromptSuggestionMsg} is stamped with $generation, the
     * transcript length and $sessionId, so a consumer can drop a suggestion
     * that lands after the conversation moved on.
     *
     * @param list<Message> $history
     *
     * @return (\Closure(): PromiseInterface<?Msg>)|null
     */
    public function suggestionCall(
        ?Backend $titleBackend,
        array $history,
        int $generation,
        ?string $sessionId,
        bool $spendCapReached,
    ): ?\Closure {
        if (
            $titleBackend === null
            || !$this->promptSuggestions
            || self::envFlag('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS')
            || $spendCapReached
        ) {
            return null;
        }

        $visible = Message::agentVisible($history);
        $last = $visible[count($visible) - 1] ?? null;
        if ($last === null || $last->role !== Role::Assistant || trim($last->content) === '') {
            return null;
        }

        $tail = [];
        foreach (array_slice($visible, -self::PROMPT_SUGGESTION_HISTORY) as $message) {
            if ($message->role === Role::System) {
                continue;
            }
            $content = mb_substr($message->content, 0, self::PROMPT_SUGGESTION_MESSAGE_CHARS);
            $tail[] = $message->role === Role::User ? Message::user($content) : Message::assistant($content);
        }
        $prompt = [
            Message::system(self::PROMPT_SUGGESTION_PROMPT),
            ...$tail,
            Message::user(self::PROMPT_SUGGESTION_REQUEST),
        ];
        $historyCount = count($history);

        return static function () use ($titleBackend, $prompt, $generation, $historyCount, $sessionId): PromiseInterface {
            return $titleBackend->completeAsync($prompt)->then(
                static fn (Message $msg): Msg => new PromptSuggestionMsg($msg->content, $generation, $historyCount, $sessionId, $msg->usage),
                // Silent, like the titler: a missing suggestion is a
                // non-event, and a rejection carries no cost to report.
                static fn (\Throwable $e): ?Msg => null,
            );
        };
    }

    /**
     * Reduce raw model output to something safe to persist and to paint into
     * a one-line-per-tab strip.
     *
     * A title is untrusted text from a model: left alone it can carry an ESC
     * sequence that repaints the chrome around the tab, or embedded newlines
     * that blow the strip's single-row layout apart. Reasoning models
     * additionally prefix a `<think>` block that is not the answer.
     *
     * The whole ECMA-48 escape family goes through the canonical, C1-aware
     * `Sanitize::untrusted()` (`Ansi::strip()` + a C0/DEL sweep): an 8-bit C1
     * introducer (`\x9b` CSI, `\x9d` OSC, `\x90` DCS, `\x9f` APC) or an
     * unterminated DCS/APC payload must not reach a real terminal. Newlines
     * survive it only to be split on below, so the single-line contract holds;
     * see docs/research/ansi-tmux-ansicode-audit.md #9 for the taxonomy.
     */
    public static function sanitizeTitle(string $raw): string
    {
        $text = preg_replace('#<think>.*?</think>#is', '', $raw) ?? $raw;
        $text = Sanitize::untrusted($text);

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return trim(mb_substr($line, 0, self::TITLE_MAX_CHARS));
            }
        }

        return '';
    }

    /**
     * Reduce the model's guess to one safe line for the input box: `<think>`
     * blocks dropped, every escape and control byte stripped, the first
     * non-empty line only, a `User:` label or wrapping quotes peeled off, and
     * the model's "nothing to suggest" answer turned into ''.
     */
    public static function sanitizeSuggestion(string $raw): string
    {
        $text = preg_replace('#<think>.*?</think>#is', '', $raw) ?? $raw;
        $text = Sanitize::untrusted($text);

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = trim((string) preg_replace('/^(?:user|me)\s*:\s*/i', '', $line));
            $line = trim($line, " \"'`“”‘’");
            if ($line === '' || strcasecmp($line, self::PROMPT_SUGGESTION_NONE) === 0) {
                return '';
            }

            return trim(mb_substr($line, 0, self::PROMPT_SUGGESTION_MAX_CHARS));
        }

        return '';
    }

    /** Set to anything but empty or `0`. */
    private static function envFlag(string $name): bool
    {
        $value = getenv($name);

        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'promptSuggestions' => $this->promptSuggestions,
        ], $changes));
    }
}
