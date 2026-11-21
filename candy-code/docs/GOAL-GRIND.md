# Goals and grinds: writing a condition the judge can check

`/goal <condition>` and `/grind <condition>` keep the agent working until a
condition is met. After every turn, a judge reads the transcript and says whether
the condition holds. If it does not, the agent gets a follow-up prompt that quotes
what is still missing, and the loop goes on. The two commands differ only in how
many follow-ups they allow: `/goal` sends up to 10, and `/grind` up to 50.

The judge decides from the transcript and nothing else. It cannot run a command,
open a file, or see anything the agent did not print. A condition is therefore
only as good as the evidence the agent leaves in the conversation, so write the
condition with that evidence in mind.

## How a goal runs

1. **Set.** The command records the goal in the session and sends the agent its
   first turn at once. That turn names the goal and tells the agent that saying
   the work is done does not count, only shown output does.
2. **Judge.** After each turn, including the ones you type, the title model reads
   the conversation and answers in strict JSON: a score from 0 to 100, whether the
   goal is `complete`, and a `missing` list with one item per part that is not
   verifiably done. The main model is never the judge.
3. **Continue or stop.** A complete verdict ends the goal as met. An incomplete
   verdict sends a follow-up that quotes the `missing` list, and the agent tries
   again. The loop stops early in three cases: the follow-up budget is spent, the
   spend cap is reached, or the judge call fails or returns no usable verdict. The
   notice says which one happened, and when the budget ran out it lists what was
   still missing.

The score is shown for information. Only the `complete` flag ends the loop.

### What the judge reads

The judge sees the transcript as plain text, oldest row first. It keeps the last
60 rows, each cut to 3,000 characters, and at most 40,000 characters in all, so a
long session costs a bounded call. Tool output stays in, so a test run the agent
made is evidence the judge can use. Output that scrolls out of those limits is
gone from the judge's view, so the agent should print the proof again near the
end of its work.

## What counts as evidence

The judge's rules are fixed, and they decide how you should phrase a condition:

- A requirement is satisfied only when the transcript shows evidence for it:
  command or test output, file contents, or a tool result.
- A requirement the agent claims but the transcript does not show is **not**
  satisfied.
- The goal is complete only when every part of it is satisfied.
- The judge does not add requirements of its own, so the condition is the whole
  contract.

## Writing a condition

A good condition is one the judge could check by reading the transcript. Four
habits make that likely.

**Name the proof.** Say which command or file shows the result. "`vendor/bin/phpunit`
ends with zero failures" can be verified. "the tests pass" leaves the judge to
guess which output counts.

**Split it into numbered parts.** The judge marks each part separately and quotes
the unmet ones back to the agent. A condition with three parts gives the agent three
things to finish, instead of one that is either met or not.

**Keep it to what can be shown.** Subjective qualities such as "the code is clean"
or "the design feels right" cannot be shown in a transcript, so the judge will
never mark them met. Replace them with a checkable proxy, such as "the linter exits
with no warnings" or "the function has a test for the empty input".

**Ask for the output, not the claim.** Tell the agent to print the summary line of
the command that proves the work. The judge accepts that line and does not accept
"I ran the tests and they passed".

| Weak condition | Stronger condition |
|---|---|
| `/goal fix the login bug` | `/goal Login with an expired token redirects to the sign-in page. Show a passing run of tests/Auth/LoginTest.php.` |
| `/goal make the tests pass` | `/goal vendor/bin/phpunit reports zero failures and zero errors, with the summary line shown in the transcript.` |
| `/goal clean up the code` | `/goal No file in src/Export still imports the removed LegacyWriter class. php -l passes on every file in src/Export, and grep for LegacyWriter prints nothing.` |
| `/grind finish the migration` | `/grind Every table in migrations/ has a down() method. The count printed by the check script is 0 for "missing down". Show that output.` |

## Choosing between `/goal` and `/grind`

Use `/goal` for a bounded task with a clear finish, such as fixing one failing test
or adding one endpoint with its tests. Ten follow-ups are enough when the agent is
close to the answer.

Use `/grind` for long work that has several stages and needs many rounds, such as
a migration across a codebase. It allows 50 follow-ups. Its judge is the same as
`/goal`'s, so a grind ends as soon as its conditions are met, not when the budget
runs out.

Each round is a full agent turn plus one judge call, so a long grind costs more than
one turn. Set a cap before you start one: `--max-cost` or `CANDY_CODE_MAX_COST`
stops the loop when the spend reaches it, and `/budget` shows what the session has
spent so far (see [USAGE.md](USAGE.md)).

## Controlling a running goal

- `/goal` with no argument reports the current goal, its round, and its budget.
- `/goal clear` ends it. `off` and `stop` do the same.
- **Esc Esc** cancels the round in progress. The goal stays set, and the judge
  reads the transcript again after your next turn.
- A prompt you queue while the judge is working is sent before the follow-up.
- A goal lives in the session transcript. Resuming the session resumes the goal,
  and `/clear` removes it.

## Without a title model

The judge runs on the title model, so a goal needs one. Without it, `/goal` sets
nothing and says so. Configure `titleModel` or `CANDY_CODE_TITLE_MODEL`. Left unset, the
title model is the provider's cheap auxiliary model where one exists, and otherwise
the provider block's own model. The judge makes
tool-less calls only, so a judge can never run a command or change a file.

## See also

- [COMMANDS.md](COMMANDS.md): the `/goal` and `/grind` rows, and the other commands that start a turn.
- [CONTEXT.md](CONTEXT.md): what happens to long conversations, which also bounds what the judge sees.
- [USAGE.md](USAGE.md): the spend ledger and `/budget`.
- [TUTORIAL.md](TUTORIAL.md): a first session from start to a committed change.
