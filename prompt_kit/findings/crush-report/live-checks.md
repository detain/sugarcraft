# Live checks (Part VI) — results

Run on 2026-10-05 from the W10-i worktree with `sugar-crush/scripts/provider-cache-live-probe.php`:

```sh
cd sugar-crush && php scripts/provider-cache-live-probe.php --check=all
```

Verdicts: **PASS**, **FAIL** (the endpoint answered and contradicted the claim), or **BLOCKED** (no credentials, or the credentials may not call the model, so nothing was learned about the claim). The script exits 0 when every requested check passes, 1 on any FAIL and 3 when nothing failed but something was blocked. It never prints a key, token or account id: credentials are only tested for presence, and every provider error is redacted (ARNs, 12-digit account ids, `sk-ant-` keys, bearer tokens) before it is shown.

| ID | Verdict | Evidence / blocker |
|---|---|---|
| LIVE-0.1 | **PASS** | skynet2, see below |
| LIVE-X31b | BLOCKED | `ANTHROPIC_API_KEY` is not set on this host |
| LIVE-A15b | BLOCKED | the AWS `[default]` IAM user may not call `bedrock:InvokeModel` |
| LIVE-A15v | BLOCKED | no `GCP_PROJECT_ID`, no Application Default Credentials |
| LIVE-A21b | BLOCKED | no `GCP_PROJECT_ID`, no Application Default Credentials |
| LIVE-CH | BLOCKED | runs on Bedrock (default) or Vertex; both are blocked as above |

## LIVE-0.1: SGLang cache reuse across a write step

The deployment is `https://skynet2.interserver.net/v1`, serving `Qwen/Qwen3.8-Flash-Next-FP8`. Its `/server_info` reports `enable_cache_report: true`, `disable_radix_cache: false` and `page_size: 64`. The probe sends the project's `dev-sglang` id (`Qwen/Qwen3.8-Flash-Next`); the request is accepted because the provider sends the served model instead (audit A26).

The probe does three things:
1. Step 1 sends a 24-rule system prompt that opens with a per-run nonce, plus a `write_file` tool. The model is asked to write the numbers 1–150, so the tool call fills several pages.
2. Step 2 replays that assistant row **with** its `reasoning_content`, followed by the tool result.
3. A control request replays the same row **without** the reasoning.

Measured, one representative run (three runs agreed within a page):

| Request | Prompt tokens | `cached_tokens` |
|---|---|---|
| step 1 | 1,822 (output 557) | 448: only the shared tools/template preamble; the nonce makes the rest cold |
| step 2, reasoning replayed | 2,398 | **2,304**: all of step 1's prompt (1,792 = 28 pages) plus 482 tokens into the replayed tool-call row |
| control, no reasoning | 2,398 | 1,792: stops at step 1's prompt; the row diverges at its start |

So roadmap 0.1 holds on the live server. Replaying `reasoning_content` keeps the assistant tool-call row byte-stable with what the model generated, and RadixAttention reuse runs on into it. Without the replay, reuse ends at the row.

### Findings for the integrator

1. **SGLang reports a zero hit as `null`, not `0`.** A request with no cache hit returns `"prompt_tokens_details": null`, even with `--enable-cache-report` on. A request with a hit returns `{"cached_tokens": N}`. This was measured with raw requests, so it is the server's behaviour and not the provider's. `SglangProvider::parseUsage` therefore reads every cold request as **unreported** (`cacheReadTokens === null`), not as a measured zero. The consequences:
   - the 0.13-b cache segment stays hidden on a cold request;
   - the `CacheHealthWatch::observeReuse` split is skipped for that request.

   This is defensible under `Usage`'s null-vs-0 doctrine, but it does not follow from the earlier "the server is not launched with cache reporting" explanation in that method's docblock. That docblock (`SglangProvider::parseUsage`, measured 2026-09-02) is now stale: the current deployment **does** report cache hits. W10-i did not edit `SglangProvider` (it is a hotspot outside this group's row). Treating `null` as `0` would need a decision.
2. **Reuse is whole pages.** `cached_tokens` is always a multiple of `page_size` (64). Any pass condition must compare against the last full page, and the probe does (`--page-size`).
3. **Reuse can drop under load.** In one early run, an identical re-send of a 2,409-token prompt read only 64 cached tokens. Every later run reused the whole prompt. This is consistent with LRU eviction on a shared server, so the probe is a spot check and not a CI gate.

## LIVE-X31b: tool-calling `anthropic` request

This check is blocked for lack of a key: no `ANTHROPIC_API_KEY` in the environment, and none in `.sugar-crush/config*.json`.

The probe was also run with a deliberately invalid key, which proved the wiring without spending anything. The request went to `POST https://api.anthropic.com/v1/chat/completions` and was answered `401 authentication_error`, **not** `404`. So the X-31b `/v1` base fix reaches a real endpoint. The tool-calling half (`tool_calls` coming back for `get_weather`) still needs a real key.

To unblock: `ANTHROPIC_API_KEY=… php scripts/provider-cache-live-probe.php --check=LIVE-X31b`.

## LIVE-A15b and LIVE-CH: Bedrock

`~/.aws/credentials` has a `[default]` profile, and the AWS SDK resolves it. Every Converse call is refused before inference:

> AccessDeniedException: User: arn:aws:<redacted> is not authorized to perform: bedrock:InvokeModel on resource: arn:aws:<redacted> (inference-profile/us.anthropic.claude-sonnet-4-6) because no identity-based policy allows the bedrock:InvokeModel action

To unblock, the user must do two things:
1. Grant that IAM user `bedrock:InvokeModel` and `bedrock:InvokeModelWithResponseStream` on the `us.anthropic.claude-sonnet-4-6` inference profile and the foundation models behind it.
2. Enable model access for Claude Sonnet 4.6 in the Bedrock console.

Then run:

```sh
php scripts/provider-cache-live-probe.php --check=LIVE-A15b,LIVE-CH
```

LIVE-CH runs three requests with caching off. It passes only if both buckets are reported as `0` (not `null`) on every reply and the notice fires once, on the third. If Bedrock omits `cacheReadInputTokens`/`cacheWriteInputTokens` when no cache point is sent, the check fails as "did not report both cache buckets as 0", and that is a finding in its own right: the notice could then never fire on Bedrock.

## LIVE-A15v and LIVE-A21b: Vertex

This host has no `GCP_PROJECT_ID`, no `GOOGLE_APPLICATION_CREDENTIALS`, no gcloud ADC file and no metadata server. Both checks are blocked.

The probe does not use the factory's outdated default (`claude-3-sonnet@20240229`). It sends `--model`:
- `claude-sonnet-4-6` by default for A15v; the exact Vertex model id is unverified and should be confirmed when credentials exist;
- `gemini-2.5-flash` with `--thinking-budget=1024` and a 1,536-token output budget for A21b.

To unblock:

```sh
GCP_PROJECT_ID=… GOOGLE_APPLICATION_CREDENTIALS=… php scripts/provider-cache-live-probe.php --check=LIVE-A15v,LIVE-A21b
```

LIVE-CH can also run on Vertex with `--provider=vertex`.
