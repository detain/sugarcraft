<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\RebindsModel;

/**
 * `/model [provider [model]]` on a served session (WEB-2) — the switch the
 * web's model picker needs, answered from the same authorities the TUI's
 * palette reads: the provider roster is `Bootstrap::availableProviders()`
 * (READ — the command never writes settings), and the new backend is built
 * by the workspace's own factory, the same construction
 * {@see \SugarCraft\Code\Host\WorkspaceContext::backendFor()} gives the
 * palette, with {@see EngineBackend::withModel()} overlaying a named model
 * the way the terminal's `N-P3b` slot does.
 *
 * The choice rides {@see CommandEffect::setModel()} into the driver, which
 * stores it as a SESSION override — the shape `SetPermissionMode` already
 * has. It is deliberately NOT persisted to the launch config: the terminal's
 * `/model` saves the provider's `models` map through a settings writer a
 * served session has no business reaching for, and a silent write would
 * change the NEXT launch from a command that reads like a switch. A resumed
 * session starts from its configured model again, and the row says so.
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

        [$provider, $model] = [$words[0], $words[1] ?? ''];

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

        if ($model !== '') {
            if (!$backend instanceof EngineBackend || !$backend->provider() instanceof RebindsModel) {
                return CommandResult::errorReply($text, Lang::t('host.model.no_rebind', [
                    'name' => self::providerName($backend),
                ]));
            }
            $backend = $backend->withModel($model);
        }

        return CommandResult::reply($text, Lang::t('host.model.switched', [
            'target' => $model === '' ? $provider : $provider . ' ' . $model,
        ]))->withEffect(CommandEffect::setModel($provider, $model, $backend));
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
        return array_keys(Bootstrap::availableProviders());
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
