### C-SEC-6.e — OTel safety events per verdict
- item: C-SEC-6 (sources: CC-new#4)
- kind: gap · effort S
- approach:
  - Every refusal already reaches the encoder: `ToolFinished->result->denial` (`src/ToolResult.php:140`, a `DenialKind` of refused / unanswered / hook) crosses the fork today. Asks arrive as `PermissionAsked`, and rejections as `PermissionResolved`.
  - `OtlpEncoder` (`src/Observability/OtlpEncoder.php:158-171`, :311-327) emits a `candy_code.safety_event` span event for:
    - each denial (`decision=deny`, `kind`, tool, first reason token);
    - each ask (`decision=ask`, `source`, `mode`);
    - each rejection.
  - No Runtime or fork-channel change is needed.
- files.create: `candy-code/tests/Observability/OtlpSafetyEventTest.php`
- files.modify: `candy-code/src/Observability/OtlpEncoder.php`, `candy-code/docs/ENVIRONMENT.md` (OTel section: event name and attributes)
- tests: the new test covers deny, hook-deny, ask, reject and allow (no event). Modify `candy-code/tests/Observability/OtlpEncoderTest.php` if it counts events per span.
- docs/guards: `EnvRosterDriftTest` is untouched (no env var).
- serialized: [hot:candy-code/src/Observability/OtlpEncoder.php] [docs:ENVIRONMENT.md]
- deps: C-SES-4 (span timestamps, the off-TUI export path and serve/`-p` sink threading all change the encoder and sink first)
- phase: normal
- ruling: none
- risks: a denial reason can quote command text. Emit the `DenialKind` token and tool name only, never the reason body.

