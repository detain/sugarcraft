<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ModelChoice;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderInterface;

/**
 * `/model [provider [model]]` on a served session (WEB-2) — the switch the
 * web's model picker needs, answered from the same authorities the TUI's
 * palette reads: the provider roster is `Bootstrap::availableProviders()`,
 * and the new backend is built by the workspace's own factory, the same
 * construction {@see \SugarCraft\Code\Host\WorkspaceContext::backendFor()}
 * gives the palette.
 *
 * W7a-2: the model choice PERSISTS, through the native mechanism and in the
 * native order. A choice is saved before the backend is built, into the
 * per-provider `models` setting through the workspace's
 * {@see SettingsWriter} (decision D9; the serve path rides the exact route
 * `Chat::selectPaletteProvider()` uses), so a serve restart or `--continue`
 * resume resolves the chosen id through `Bootstrap::selectedModelName()`.
 * {@see EngineBackend::withModel()} still overlays the id afterwards, so the
 * choice wins even where `--model` or `$CANDY_CODE_MODEL` outrank the saved
 * entry, and where nothing could be saved. A save that fails is reported,
 * never fatal: the switch still happens for this session. The switch itself
 * stays session state (`SetModel` → {@see \SugarCraft\Code\Host\SessionHost}'s
 * session backend); only the model id rides settings, exactly as the
 * terminal's does — the provider axis is not persisted on either surface.
 *
 * `session.setMode` is permission-mode and nothing else; conflating the two
 * was WEB-2's named trap — the model choice stays on `command.exec`.
 */
final class ModelHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);

        if ($words === []) {
            return CommandResult::reply($text, self::listing($context));
        }

        if (count($words) > 2) {
            return CommandResult::reply($text, Lang::t('chat.model.usage', [
                'available' => implode(', ', self::providerNames()),
            ]));
        }

        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/model']));
        }

        [$provider, $modelArg] = [$words[0], $words[1] ?? ''];
        $model = trim($modelArg) === '' ? null : trim($modelArg);

        // The save rides the native route: only a named model is persisted,
        // only under a real provider name, and a failure is carried into the
        // reply rather than aborting the switch.
        $saved = null;
        if ($model !== null) {
            $writer = $context->workspace?->service(SettingsWriter::class);
            if (!$writer instanceof SettingsWriter) {
                $saved = Lang::t('chat.provider.model_not_savable');
            } elseif (!\in_array($provider, self::providerNames(), true)) {
                // Never save a model under a name that is not a provider: the
                // entry would sit in config.json, read by nothing.
                $saved = null;
            } else {
                try {
                    $layered = Bootstrap::readUserConfig()['models'] ?? [];
                    $path = ModelChoice::persist($writer, $provider, $model, \is_array($layered) ? $layered : []);
                    $saved = Lang::t('chat.provider.model_saved', [
                        'provider' => PermissionsCommand::reportField($provider),
                        'path' => PermissionsCommand::reportField($path),
                    ]);
                } catch (\Throwable $e) {
                    $saved = Lang::t('chat.provider.model_not_saved', ['error' => PermissionsCommand::reportField($e->getMessage())]);
                }
            }
        }

        try {
            $backend = $context->workspace?->backendFor($provider);
        } catch (\Throwable $e) {
            $backend = null;
        }
        if ($backend === null) {
            return CommandResult::errorReply($text, Lang::t('host.model.unknown', [
                'name' => $provider,
                'available' => implode(', ', self::providerNames()),
            ]));
        }

        // A model is a property of the engine; a command-line backend has no
        // model to switch, and saying "switched" would be a lie — the native
        // door's verdict, word for word. A choice saved before this refusal
        // stays saved (it is a valid entry for a real provider).
        if ($model !== null) {
            if (!$backend instanceof EngineBackend) {
                return CommandResult::errorReply($text, Lang::t('chat.provider.no_model_choice'));
            }
            $backend = $backend->withModel($model);
        }

        $report = match (true) {
            $model === null => Lang::t('chat.provider.switched', ['provider' => $provider]),
            $saved === null => Lang::t('chat.provider.switched_model', [
                'provider' => $provider,
                'model' => PermissionsCommand::reportField($model),
            ]),
            default => Lang::t('chat.provider.switched_model_saved', [
                'provider' => $provider,
                'model' => PermissionsCommand::reportField($model),
                'saved' => $saved,
            ]),
        };

        return CommandResult::reply($text, $report)
            ->withEffect(CommandEffect::setModel($provider, $model ?? '', $backend));
    }

    /** The bare-`/model` answer: every selectable provider, current one first. */
    private static function listing(CommandContext $context): string
    {
        $current = self::providerName($context->backend);
        $currentModel = $context->backend instanceof EngineBackend ? $context->backend->model() : '';

        $lines = [Lang::t('host.model.current', [
            'provider' => $current === '' ? Lang::t('host.model.current.none') : '`' . $current . '`',
            'model' => $currentModel === '' ? '' : ' (`' . $currentModel . '`)',
        ])];
        $names = self::providerNames();
        sort($names);

        return $lines[0] . "\n" . Lang::t('host.model.list', ['available' => implode(', ', $names)]);
    }

    /** @return list<string> */
    private static function providerNames(): array
    {
        try {
            return array_map('strval', array_keys(Bootstrap::availableProviders()));
        } catch (\Throwable) {
            return [];
        }
    }

    private static function providerName(?\SugarCraft\Code\Backend $backend): string
    {
        if (!$backend instanceof EngineBackend) {
            return '';
        }
        $provider = $backend->provider();

        return $provider instanceof ProviderInterface ? $provider->name() : '';
    }
}
