# C-AGT-5.e — Outbound webhooks core (sign, async send, retry)

VERDICT: DONE. Commit 2ebd53542 on cc/C-AGT-5.e (base 2a6cd3c1f), never pushed. 8 files, +1401/−3.

## Delivered
- src/Automation/OutboundWebhookConfig.php — user-tier {name:{url,secret,events[]}} fail-closed
  parse (https required, loopback http carved out, name grammar, secret floor, unknown settings
  refused by name); parse-time refuses, no dialing.
- src/Automation/WebhookEvent.php — four bounded shapes (turn.completed / session.ended /
  permission.asked / schedule.fired), identifiers only (clipped/trimmed), JSON_PRESERVE_ZERO_FRACTION
  envelope {event,timestamp,data}; transcript text is unrepresentable by construction.
- src/Automation/WebhookSigner.php — X-Candy-Code-Signature sha256=HMAC(body) + X-Candy-Code-Timestamp
  (send-time stamp, replay-fresh per attempt; explicit-ts seam for pins).
- src/Automation/WebhookDispatcher.php — dispatch() returns before the loop runs; Browser with
  CONNECT_TIMEOUT 2.0 / per-POST cap 10.0 (E646-legal webhook boundary, no blanket request timeout),
  withFollowRedirects(false)+withRejectErrorResponse(false) applied by THIS class; ladder 2/8/30
  (injectable ms rescale) on loop timers for transport/5xx, 4xx+3xx final-first-time; bounded queue
  (128) drop-on-overflow; once-per-streak/episode notices via RuntimeNoticeSink — zero raw emitters.

## Gates (plain pipe, cwd=candy-code, -c phpunit.xml)
- 'OutboundWebhookConfig|WebhookSigner|WebhookDispatcher': 54T/166A OK. Red-before: 51T/51E
  class-not-found with src/Automation moved aside (/tmp/opencode/C-AGT-5e/red-before.txt; no stash).
- 'Automation': 55T/172A OK.
- 'EnvRosterDrift|StderrEmitterCensus|DocFigureProseDrift|GlobDialectDifferential|DuplicatedTestHelperDrift':
  250T/63984A, 1F — INHERITED base-red, see seams.

## New test files (welder: durations.tsv rows owed)
tests/Automation/OutboundWebhookConfigTest.php · tests/Automation/WebhookSignerTest.php ·
tests/Automation/WebhookDispatcherTest.php

## Deviations / seams
- StderrEmitterCensusTest roster flipped IN-STEP (WebhookDispatcher => 2, prose FORTY-FIVE/
  NINETEEN, NUMBER_WORDS +forty-five) — the brief's own "roster in-step" clause.
- INHERITED (not mine): DocFigureProseDriftTest::testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory
  red at base — docs say "thirty-seven" and src/Tools/BuiltIn holds 37 since C-TOOL-4.a (1945e347f),
  but that arm's local word map stops at thirty-six. Fix belongs to its owner/welder.
- Zero env keys minted (EnvRoster owed nothing); no forks (child-lifetime row owed nothing);
  suite-figure.json/durations.tsv/dist untouched. Docs rows land in 5.f.
