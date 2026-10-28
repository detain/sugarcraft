# C-AGT-5.e: Outbound webhooks core (sign, async send, retry)
wave 15 · area agt · src design-agt.md · item C-AGT-5 (sources: p1-miss: outbound webhooks, p2-miss: outbound webhooks)
kind: gap · effort M · phase normal · deps C-AGT-1.a, C-AGT-1.b, C-AGT-1.c (item order) · serialized none
ruling: none
risks: the tests must pin a stable loop clock (`LoopPin` via `candy-code/tests/bootstrap.php`, already present) for the backoff timers.
## approach
Add a self-contained `Automation` namespace, with no serialized files:
- `OutboundWebhookConfig` parses a user-tier map `{name: {url, secret, events[]}}`. It requires https, or http only to loopback, and refuses redirects;
- `WebhookSigner` sets `X-Candy-Code-Signature: sha256=<hmac>` and an `X-Candy-Code-Timestamp`;
- `WebhookEvent` builds the JSON body for `turn.completed`, `session.ended`, `permission.asked` and `schedule.fired`, with bounded fields and no transcript text;
- `WebhookDispatcher` POSTs with `React\Http\Browser` on the loop. It never blocks `update()`, uses a connect timeout plus a short per-POST cap (webhook, not an LLM call), retries 3× with 2/8/30 s backoff on loop timers, uses a bounded queue, and drops on overflow with a runtime notice.
- No fork, so no child-lifetime row.
## files.create
`candy-code/src/Automation/OutboundWebhookConfig.php`, `candy-code/src/Automation/WebhookSigner.php`, `candy-code/src/Automation/WebhookEvent.php`, `candy-code/src/Automation/WebhookDispatcher.php`
## files.modify
none
## tests
create `candy-code/tests/Automation/OutboundWebhookConfigTest.php`, `candy-code/tests/Automation/WebhookSignerTest.php` (known-vector HMAC), `candy-code/tests/Automation/WebhookDispatcherTest.php` (a fake Browser: retry/backoff on 5xx, no retry on 4xx, overflow drop, never throws)
## docs/guards
none in-step (5.f documents)
