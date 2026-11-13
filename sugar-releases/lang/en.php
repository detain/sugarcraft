<?php

/**
 * English (default) translations for sugar-releases.
 *
 * Every string takes `{app}` (the tool's display name); the composer-mode ones
 * also take `{package}` (its Composer package). Keys mirror candy-code's former
 * `cli.selfupdate.*` / `startup.update.*` rows so an adopting app can map them
 * one-to-one through a translator closure.
 *
 * @return array<string, string>
 */
return [
    'selfupdate.channel_invalid' => '{value}: {app} self-update --channel accepts stable or dev.',
    'selfupdate.check_available' => '{app}: update available — this install is {current}, the latest release is {latest}. (--check reports only; nothing was installed.)',
    'selfupdate.check_unknown' => '{app}: cannot compare this install ({current}) with the latest release ({latest}).',
    'selfupdate.check_up_to_date' => '{app}: up to date — this install is {current}, the latest release is {latest}.',
    'selfupdate.composer_mode' => '{app}: self-update replaces a phar install, and this copy was installed through Composer — there is no phar here to swap. Update it with: {command} (a global install runs `composer global update {package}` instead).',
    'selfupdate.composer_mode_root' => '{app}: self-update replaces a phar install, and this copy is the {package} package installed as the Composer root — there is no phar here to swap, and a root package cannot refresh itself through its own dependency command. Update it with: {command} (the clone pulls the new source, then rebuilds the vendor tree).',
    'selfupdate.dir_not_writable' => '{app}: cannot replace the phar — directory {dir} is not writable.',
    'selfupdate.downgrade_refused' => '{app}: refusing to downgrade — this install is {current}, the latest release is {latest}. (--rollback restores the kept copy; nothing else goes backwards.)',
    'selfupdate.download_failed' => '{app}: could not download {asset}: {reason}',
    'selfupdate.empty_download' => '{app}: the download of {asset} produced no bytes.',
    'selfupdate.fetch_failed' => '{app}: could not read the release list: {reason}',
    'selfupdate.flags_exclusive' => '--check reports only; pass it alone, not with --rollback or --allow-unsigned.',
    'selfupdate.hash_mismatch' => '{app}: the downloaded phar hashes to {actual}, not the {expected} the sidecar signs. Nothing was swapped.',
    'selfupdate.hash_unreadable' => 'nothing (the temp file could not be read)',
    'selfupdate.installed' => '{app}: self-update replaced {path} ({from} -> {to}); the previous phar is kept at {old} — `self-update --rollback` restores it once.',
    'selfupdate.no_dev_release' => '{app}: no published dev release (tag {tag}) carries both {phar} and {sidecar}.',
    'selfupdate.no_release' => '{app}: no published {app} release carries both {phar} and {sidecar}.',
    'selfupdate.phar_not_writable' => '{app}: cannot replace the phar — {path} is not writable.',
    'selfupdate.preserve_failed' => '{app}: the running phar could not be copied to {old}; nothing was swapped.',
    'selfupdate.rename_failed' => '{app}: the verified phar could not be moved into place; the running copy is intact and the kept copy remains at {old}.',
    'selfupdate.rollback_failed' => '{app}: could not restore {old} over {path}.',
    'selfupdate.rollback_missing' => '{app}: no kept copy to restore: {old} does not exist (it appears only after a successful update).',
    'selfupdate.rollback_restored' => '{app}: rollback restored {old} over {path}; the kept copy is consumed, this restore is one-shot.',
    'selfupdate.sidecar_malformed' => '{app}: the {asset} sidecar is not a lowercase 64-hex-digit sha256 for {phar}. Nothing was swapped.',
    'selfupdate.sidecar_names' => '{app}: the sidecar names {found}, not {phar}. Nothing was swapped.',
    'selfupdate.sig.deprecated' => '{app}: this release signs the raw phar bytes with {asset}; that legacy leg reads the whole artifact into memory and is accepted for one more release cycle. Publish {replacement} — an Ed25519 signature over the sha256 sidecar body — so verification never leaves the stream.',
    'selfupdate.sig.sidecar_invalid' => '{app}: the {asset} does not verify the {sidecar} sidecar of the downloaded {phar} against the pinned release key. Nothing was swapped.',
    'selfupdate.signature_invalid' => '{app}: the {asset} does not verify the downloaded {phar} against the pinned release key. Nothing was swapped.',
    'selfupdate.signature_missing' => '{app}: this release carries no {asset} and no {sig_sidecar}, and the pinned release key requires one for {phar}. Nothing was swapped (--allow-unsigned is the dev-only bypass).',
    'selfupdate.staged_windows' => '{app}: the update is staged for the relaunch dance — {new} swaps onto {path} when this process exits; progress is logged to {path}.swap.log and the kept copy remains at {old}.',
    'selfupdate.swap_leftover' => '{app}: a previous self-update left a staged swap at {new} that was never applied (its swap log records the abandoned attempts). This run replaces the stage before updating.',
    'selfupdate.swap_spawn_failed' => '{app}: the swap helper could not be started; the verified bytes stay staged at {new} and the running copy is intact. Complete the rename onto {path} by hand, or re-run self-update once nothing holds the file.',
    'selfupdate.uncomparable' => '{app}: this install reports {current}, which is not a release version self-update can order.',
    'selfupdate.unexpected_operand' => '{operand}: {app} self-update takes no operands.',
    'selfupdate.unsigned_allowed' => '{app}: --allow-unsigned skips the pinned-key signature leg; the sha256 sidecar still bound these bytes. Dev/test bypass — never a release habit.',
    'selfupdate.up_to_date' => '{app}: self-update: this install is already the latest release ({current}).',
    'selfupdate.usage' => 'Usage: {app} self-update [--check] [--rollback] [--allow-unsigned] [--channel=stable|dev]',
    'update.available' => 'update available: {release} — run `{app} self-update`',
];
