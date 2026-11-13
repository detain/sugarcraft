<?php

/**
 * English (default) translations for candy-vcr.
 *
 * Placeholder names are identical across locales; only their order may move.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'app.unknown_subcommand' => 'candy-vcr: unknown subcommand \'{name}\'',
    'app.usage' => 'usage: candy-vcr <subcommand> [options...]',
    'app.subcommands' => 'subcommands:',
    'app.version_flag' => 'Print the version and exit.',
    'cli.usage_label' => 'usage:',
    'cli.err' => 'candy-vcr {verb}: {message}',
    'cli.unknown_option' => 'candy-vcr {verb}: unknown option {option}',
    'cli.idle_trim_positive' => '--idle-trim must be > 0 seconds',
    'cli.idle_trim_requires' => '--idle-trim requires a positive seconds argument',
    'choice.invalid' => '{flag} must be one of {known}, got "{got}"',
    'choice.invalid_type' => '{flag} must be one of {known}, got {got}',
    'fps.not_numeric' => '--fps must be a positive number, got {value}.',
    'fps.not_positive' => '--fps must be a positive finite number of frames per second, got {value}.',
    'summary.record' => 'Record a command\'s PTY session into a cassette',
    'summary.inspect' => 'List the events (or frames with --frames) in a cassette',
    'summary.replay' => 'Stream a cassette\'s recorded output to stdout',
    'summary.diff' => 'Compare two cassettes event-by-event',
    'summary.stats' => 'Show cassette statistics',
    'summary.migrate' => 'Migrate a cassette to the current format version',
    'desc.render_tape' => 'Render a .tape file to a .gif',
    'desc.render_batch' => 'Render all .tape files in a directory',
    'opt.tape' => 'Path to .tape file',
    'opt.dir' => 'Directory containing .tape files',
    'opt.output_gif' => 'Output .gif path (default: same as input with .gif extension)',
    'opt.output_dir' => 'Output directory for .gif files (default: same as source dir)',
    'opt.font' => 'TTF font family name (default: JetBrainsMono)',
    'opt.recursive' => 'Search recursively',
    'opt.theme' => 'Theme name (default: TokyoNight)',
    'opt.fps' => 'Frames per second (default: 30)',
    'opt.backend' => 'Rasterizer backend: gd|imagick (default: gd)',
    'opt.encoder' => 'GIF encoder: ffmpeg|php (default: ffmpeg)',
    'opt.strict' => 'Error on unknown directives instead of skipping',
    'opt.shell' => 'Execute typed commands in this shell under a real PTY and capture the program output (exec mode). Overrides the tape\'s Set Shell.',
    'opt.exec' => 'Enable exec mode with a default shell ($SHELL or /bin/sh) when the tape sets no Set Shell',
    'opt.dry_run' => 'Print the compiled event stream as JSONL instead of writing a GIF',
    'render.failed' => 'Failed: {message}',
    'render.cwd_hint' => ' (cwd: {cwd})',
    'render.tape_not_found' => 'tape file not found: {path}{hint}',
    'render.tape_too_large' => 'tape file {path} exceeds the {cap}-byte tape cap',
    'render.cannot_read_tape' => 'cannot read tape file: {path}',
    'render.rendering' => 'Rendering {name} ...',
    'render.progress' => '  {frames} frames rendered ({w}x{h} px cells)',
    'render.warning' => 'Warning: {warning}',
    'render.gif_written' => 'GIF written to {path}',
    'batch.not_directory' => 'Not a directory: {dir}',
    'batch.none_found' => 'No .tape files found in {dir}',
    'batch.rendering' => 'Rendering {total} tape(s) in {dir}',
    'batch.output_directory' => 'Output directory: {dir}',
    'batch.claimed' => 'skipped — {gif} already claimed by {tape}',
    'batch.col_filename' => 'filename',
    'batch.col_status' => 'status',
    'batch.col_time' => 'time',
    'batch.total' => 'Total: {total} | Succeeded: {ok} | Failed: {failed} | Time: {seconds}s',
    'diff.identical' => 'cassettes are identical (header fields match; events match kind, payload, and timestamps within 2 ms)',
    'diff.count' => '{count} difference(s)',
    'diff.dimensions' => 'header dimensions: {a_cols}x{a_rows} != {b_cols}x{b_rows}',
    'diff.event_count' => 'event count: {a} != {b}',
    'diff.missing_in_a' => 'event[{index}]: missing in A, present in B ({kind})',
    'diff.missing_in_b' => 'event[{index}]: present in A ({kind}), missing in B',
    'diff.kind_differs' => 'event[{index}]: kind {a} != {b}',
    'diff.time_differs' => 'event[{index}]: t differs ({a} != {b})',
    'diff.payload_differs' => 'event[{index}] payload differs ({kind})',
    'inspect.fps_requires_value' => '--fps requires a value',
    'inspect.since_invalid' => '--since must be a non-negative number',
    'inspect.until_invalid' => '--until must be a non-negative number',
    'inspect.shown' => '{shown} / {total} event(s) shown',
    'inspect.frames_footer' => 'frames: {total}  unique: {unique}  deduped: {deduped}',
    'inspect.header' => 'cassette v{version}  {cols}x{rows}  runtime={runtime}  created={created}  duration={duration}s  events={events}',
    'inspect.screenshot' => 'screenshot: {path}',
    'migrate.help_dry_run' => 'Validate migration without writing files',
    'migrate.help_info' => 'Show registered migrators and exit',
    'migrate.help_input' => 'Input: JSONL cassettes only (.cas/.jsonl); .cast/.yaml/.tape/.gz inputs are refused',
    'migrate.too_many' => 'too many arguments',
    'migrate.cannot_read' => 'cannot read {path}',
    'migrate.refused_kind' => '{path} is a {kind} file; migrate reads JSONL cassettes (.cas/.jsonl) only',
    'migrate.kind_cast' => 'asciinema cast',
    'migrate.kind_tape' => 'tape script',
    'migrate.already_latest' => 'cassette is already at the latest format version (v{version})',
    'migrate.dry_run' => 'dry-run: would migrate v{from} → v{to}',
    'migrate.output_gz' => 'output cannot be a .gz path; migrate writes plain JSONL',
    'migrate.backup_symlink' => 'backup path {path} is a symlink - refusing to write through it',
    'migrate.backup_exists' => 'backup {path} already exists - refusing to overwrite it',
    'migrate.backup_create_failed' => 'could not create backup at {path}',
    'migrate.chmod_failed' => 'cannot restrict permissions on {path}',
    'migrate.copy_failed' => 'could not copy {input} to {backup}',
    'migrate.backed_up' => 'backed up original to {path}',
    'migrate.write_failed' => 'could not write {path}: {reason}',
    'migrate.done' => 'migrated v{from} → v{to} ({count} events)',
    'migrate.info_title' => 'candy-vcr migration system',
    'migrate.info_none' => 'no migrators registered',
    'stats.unexpected_argument' => 'unexpected argument {arg}',
    'stats.header' => 'cassette v{version}  {cols}x{rows}',
    'stats.header_meta' => 'runtime={runtime}  created={created}',
    'stats.events' => 'Events: {count}',
    'stats.duration' => 'Duration: {seconds}s',
    'stats.raw_bytes' => 'raw bytes({count})',
    'stats.input_msgs' => 'Input msgs: {parts}',
    'stats.output_bytes' => 'Output bytes: {bytes}',
    'stats.avg_output' => 'Avg output/event: {bytes} bytes',
    'replay.speed_invalid' => '--speed must be \'instant\' or \'realtime\'',
    'replay.help_speed' => 'Default --speed is instant. The header\'s playbackSpeed multiplier
applies to rendering (render-tape/render-batch), not live replay.',
    'replay.interrupted' => 'interrupted after {played} of {total} events ({bytes} output bytes)',
    'replay.tape_no_output' => '{path} is a tape source with no recorded output bytes — replay shows nothing; use \'candy-vcr render-tape\' to render .tape files',
    'replay.played' => 'played {played} of {total} events ({bytes} output bytes, {seconds} s of recorded time)',
    'record.output_gz' => '--output cannot be a .gz path; record writes plain JSONL — use a .cas/.jsonl name (gzip via CompressedJsonlFormat in the API)',
    'record.refuse_overwrite' => 'refusing to overwrite existing cassette {path} — pass --force to replace',
    'record.command_not_found' => 'command not found: {cmd}',
    'record.incomplete' => 'cassette capture INCOMPLETE ({count} failed write(s)) at {path}',
    'record.recorded' => 'candy-vcr: recorded {path} (exit {code})',
    'record.output_requires' => '--output requires a path',
    'record.env_regex_invalid' => '--env-regex=\'{regex}\' is not a valid PCRE pattern',
    'record.unknown_option' => 'unknown option {option}',
    'record.shell_with_cmd' => '--shell may not be combined with a positional <cmd>',
    'record.dims_invalid' => '--cols and --rows must be positive integers (cols={cols} rows={rows})',
    'record.env_all_warning' => 'candy-vcr record: WARNING --env-all captured the full host environment WITHOUT secret filtering ({count} vars): {names}',
    'record.none' => '(none)',
    'record.termios_skipped' => 'termios snapshot skipped ({reason})',
    'record.raw_failed' => 'raw-mode apply failed ({reason})',
    'record.help_output' => 'Cassette file to write (default: session-<timestamp>.cas)',
    'record.help_force' => 'Replace an existing --output target. Without it, record
refuses (exit 2) rather than silently truncating a
cassette that is already on disk.',
    'record.help_cols' => 'Initial terminal columns (default: 80)',
    'record.help_rows' => 'Initial terminal rows (default: 24)',
    'record.help_no_ctty' => 'Spawn without a controlling terminal (Ctrl+C will not
reach the recorded program; default: ctty enabled)',
    'record.help_shell' => 'Spawn $SHELL -l (or /bin/sh -l) instead of an explicit
<cmd>. Mutually exclusive with positional <cmd>.',
    'record.help_env' => 'Capture the host environment into the cassette header.
Off by default — env capture is opt-in to avoid leaking
the caller\'s full shell environment. Keys matching the
secret regex (see --env-regex) are stripped.',
    'record.help_env_regex' => 'Override the secret-stripping regex (default conservative
/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i).
An empty pattern is rejected — it can no longer be used
to disable filtering. Implies --env.',
    'record.help_env_all' => 'DANGEROUS: capture the FULL host environment with secret
filtering disabled — the cassette will contain credential
values verbatim, and every captured var name is printed to
stderr as a warning. Only use in trusted, isolated
environments. Implies --env. (--env-allow-secrets is a
legacy alias.)',
    'record.help_idle_trim' => 'Compress inter-event gaps longer than N seconds. Trimmed
events carry both `t` (compressed) and `tRaw` (original)
so `replay --no-trim` can opt back into the real cadence.',
];
