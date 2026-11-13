<?php

/**
 * English (default) translations for sugar-core.
 *
 * Keys are flat dot-paths under the `core.*` namespace registered by
 * {@see \SugarCraft\Core\Lang}. Values may use `{name}` placeholders that
 * are substituted at lookup time by {@see \SugarCraft\Core\I18n\T::translate()}.
 *
 * To add a new locale, copy this file to `lang/<locale>.php` (e.g.
 * `lang/fr.php`) and translate the values, leaving the keys and
 * placeholder names intact.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'rgb component out of range [0,255]: {value}',
    'color.invalid_hex'           => 'invalid hex color: {hex}',
    'color.ansi_out_of_range'     => 'ansi index out of range [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ansi256 index out of range [0,255]: {index}',
    'color.parse_empty'           => 'cannot parse empty color name',
    'color.parse_unknown'          => 'unknown color name: {name}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'invalid 16-color fg code: {code}',
    'ansi.invalid_bg_code'        => 'invalid 16-color bg code: {code}',
    'ansi.component_out_of_range' => '{label} out of range [0,255]: {value}',
    'ansi.invalid_scs_slot'       => 'invalid SCS slot: {slot} (expected 0-3 for G0-G3)',
    'ansi.invalid_scs_designator' => 'invalid SCS designator: {designator} (expected one byte in 0x30-0x7E)',
    'ansi.invalid_sixel_repeat'   => 'sixel repeat count must not be negative: {count}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open failed for: {cmd}',

    // Util/Semaphore.php
    'semaphore.limit_invalid'     => 'semaphore limit must be at least 1, got {limit}',
    'semaphore.limit_busy'        => 'semaphore is busy: {held} running, {waiting} waiting',
    'semaphore.shutdown'          => 'semaphore closed before a permit could be granted',
    'semaphore.closed'            => 'semaphore is closed and cannot grant another permit',
    'semaphore.derive_closed'     => 'cannot derive a new pool from a closed semaphore',

    // Util/LruMap.php
    'lru_map.capacity_invalid'    => 'LRU capacity must be at least 1, got {capacity}',
    'lru_map.capacity_too_large'  => 'LRU capacity {capacity} exceeds growth guard {max}',
    'lru_map.evict_from_empty'    => 'cannot evict from an empty LRU map',

    // Util/Validation.php
    'errors.negative_not_allowed'  => 'negative not allowed for {name}',
    'errors.value_must_be_positive' => '{name} must be positive, got {value}',
    'errors.value_out_of_range'    => '{name} must be between {min} and {max}, got {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
    'ansi.selection_invalid' => 'clipboard selection parameter {selection} is not an OSC 52 selector (c, p, s, 0-7, comma-separated combinations)',
    'ansi.clipboard_payload_too_large' => 'clipboard payload is {bytes} bytes, over the {limit}-byte OSC 52 bound',
    'raw_mode.stream_not_mappable' => 'raw mode was requested on stream {stream}, but its descriptor names no stty-targetable device — pass a terminal stream that resolves to a path, or a non-tty stream, and the toggle no-ops',
    'raw_mode.stty_failed' => 'stty rejected mode {mode} with exit {status} — the terminal discipline was not changed',
'modifiers.unknown_bit' => 'unknown modifier bit {modifier}: expected one of Modifiers::SHIFT, Modifiers::ALT or Modifiers::CTRL',
'worker.string_task_removed' => 'string worker task `{name}` is not supported: the global-function channel was removed (sc-29) — pass a serializable callable such as [Class::class, method] or an invokable object',
'worker.malformed_response' => 'malformed worker response: {length} bytes, digest {digest} (frame contents withheld: response lines embed serialized task data)',
    'worker.stdin_write_timeout' => 'worker stdin write timed out after {seconds}s: only {written} of {total} bytes handed off (the child never drained the pipe)',
    'worker.queue_full' => 'worker pool at its outstanding-job cap: {pending} pending + {queue} queued against cap {cap} — dispatch refused (backpressure; construct with a larger cap or 0 to disable)',
    'worker.incomplete_result' => 'worker result carries a disallowed class instance (__PHP_Incomplete_Class): task results cross as scalars/arrays or classes named in the response allow-list — see the sc-33 envelope contract',
    'atomic_json.lock_timeout' => 'timed out after {seconds}s acquiring the atomic-json write lock on {path} (a co-operating writer is wedged; the file is unchanged)',

    // sc-20 (sugar-core-022): the localized throw corpus — en.php rows are the authentic byte-for-byte form of each swept message; the 15 fallback locales carry English verbatim per the in-step precedent.
    'builder.framerate_positive' => 'Framerate must be positive',
    'builder.window_size_keys' => 'windowSize must have cols and rows keys',
    'builder.window_size_positive' => 'windowSize cols and rows must be positive',
    'worker.cap_invalid' => 'WorkerPool outstanding-job cap must be 0 (unbounded) or positive, got {cap}',
    'worker.task_encoding_invalid' => 'Invalid task encoding',
    'worker.payload_not_callable' => 'Worker payload is not callable',
    'worker.no_idle_worker' => 'No idle worker available',
    'program.screen_stack_root_unwritable' => 'Screen stack root {class} can neither be cloned (no public withScreens()) nor written (no public writable $screens property) — pushed screens cannot persist their state. Add withScreens(ScreenStack): static or make $screens a public writable property.',
    'screen_stack.empty' => 'ScreenStack is empty',
    'program.init_failed' => 'program initialization failed',
    'render.buffer_overflow' => 'render buffer overflow: the frame exceeded the byte ceiling',
    'tty.open_failed' => 'could not open the terminal',
    'palettes.scheme_unknown' => 'Unknown palette scheme: {scheme}',
    'palettes.color_unknown' => "Unknown colour '{name}' in scheme '{scheme}'",
    'editor.temp_seed_failed' => 'Could not seed editor temp file: {path}',
    'editor.exited_status' => 'Editor exited with status {status}',
    'editor.temp_read_failed' => 'Could not read editor output file: {path}',
    'editor.none_found' => 'No usable editor found ($VISUAL/$EDITOR/{candidates}).',
    'editor.temp_create_failed' => 'Could not create temp file for editor.',
    'editor.temp_rename_failed' => 'Could not rename temp file to {path}.',
    'atomic_text.mode_invalid' => 'AtomicTextFile mode must be plain permission bits (0000..0777), got {mode} for {path}',
    'atomic_text.dir_create_failed' => 'Failed to create directory "{dir}" for atomic write.',
    'atomic_text.temp_open_failed' => 'Failed to open temporary file "{tmp}" for write of "{path}".',
    'atomic_text.permissions_failed' => 'Failed to set permissions 0{mode} on "{tmp}" (target "{path}").',
    'atomic_text.short_write' => 'Short write to temporary file "{tmp}" of "{path}".',
    'atomic_text.flush_failed' => 'Failed to flush temporary file "{tmp}" of "{path}".',
    'atomic_text.publish_failed' => 'Failed to atomically write "{path}".',
    'atomic_json.mode_invalid' => 'AtomicJsonFile mode must be plain permission bits (0000..0777), got {mode} for {path}',
    'atomic_json.read_failed' => 'Failed to read state file: {path}',
    'atomic_json.lock_open_failed' => 'Failed to open lock file: {path}',
    'atomic_json.lock_failed' => 'Failed to lock {path}: {reason}',
    'atomic_json.temp_open_failed' => 'Failed to open temp file: {path}',
    'atomic_json.temp_write_failed' => 'Failed to write temp file: {path}',
    'atomic_json.temp_flush_failed' => 'Failed to flush temp file: {path}',
    'atomic_json.rename_failed' => 'Failed to rename temp file onto: {path}',
    'atomic_json.dir_create_failed' => 'Cannot create state directory: {dir}',
    'atomic_json.dir_mode_failed' => 'Cannot set mode {mode} on state directory: {dir}',
    'atomic_json.temp_mode_failed' => 'Cannot set mode {mode} on temp state file: {tmp}',
    'atomic_json.base_missing' => 'Base directory does not exist: {dir}',
    'atomic_json.parent_missing' => 'Parent directory does not exist: {dir}',
    'atomic_json.path_escapes_base' => 'Path escapes base directory: {path}',
    'atomic_json.symlink_dangling' => 'Dangling symlink at target: {path}',
    'atomic_json.symlink_escapes_base' => 'Symlinked target escapes base directory: {path}',
    'proc.pid_positive' => 'pid must be positive, got {pid}',
    'width.wrap_split_failed' => 'Width::wrap() could not split paragraph: {reason}',
    'json.top_not_array' => 'Expected JSON top level to be an array, got {type}.',
    'windows_console.native_only' => 'WindowsConsole drives kernel32.dll through FFI and only exists on native Windows; gate callers with WindowsConsole::isSupported(), or inject a Kernel32Interface seam.',
    'tty.fd_not_terminal' => 'descriptor {fd} is not a terminal',
    'tty.winsize_failed' => 'TIOCGWINSZ ioctl failed on fd {fd} with rc={rc}',
    'legacy_env.empty_old_prefix' => 'LegacyEnv::import(): an old prefix must not be the empty string — it would match every env var.',
    'lock_file.acquire_timeout' => 'Timed out after {seconds}s waiting for the {kind} lock on {path} — another process holds it.',
    'image_overlay.id_out_of_range' => 'image id {id} out of range 0..{max}',
];
