# Execution plan: what is left

The roadmap ran as eleven fix waves (W1–W11), a remainder wave and a final verification pass, all committed straight to `master`.

What remains is unscheduled: the five live checks below, which wait on credentials, and the open follow-ups listed in the synthesis ("Open follow-ups").

## Blocked live checks

| ID | Check | Needs |
|---|---|---|
| LIVE-X31b | one tool-calling `anthropic` request after the `/v1` fix | `ANTHROPIC_API_KEY` |
| LIVE-A15b | Bedrock prompt-cache marks: creation tokens > 0, then read tokens > 0 | AWS `bedrock:InvokeModel` (+`WithResponseStream`) and Claude Sonnet 4.6 model access |
| LIVE-A15v | Vertex prompt-cache marks (overriding the outdated default model) | `GCP_PROJECT_ID` and application-default credentials |
| LIVE-A21b | Gemini 2.5 output budget with `thinkingConfig` | `GCP_PROJECT_ID` and application-default credentials |
| LIVE-CH | cache-health notice after three consecutive replies with zero buckets | as LIVE-A15b (Bedrock) or LIVE-A15v (Vertex) |

Run them from `sugar-crush/` once the credentials exist:

```sh
php scripts/provider-cache-live-probe.php --check=<ID>[,<ID>…]
```

The probe, its verdicts and the unblocking steps for each check are in `prompt_kit/findings/crush-report/live-checks.md`.
