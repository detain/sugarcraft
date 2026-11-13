<?php

/**
 * Brazilian Portuguese translations for candy-flappy. Keys mirror en.php
 * exactly; {placeholder} tokens, key names and separators are contract
 * (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'pontos: {score}',
    'ui.best'                => ' recorde: {best}',
    'ui.new_best'            => ' 🏆 NOVO RECORDE: {best}',
    'ui.plain_new_best'      => ' NOVO RECORDE: {best}',
    'ui.crash_full'          => '💥 {why} — pressione r para reiniciar · q para sair',
    'ui.crash_short'         => '💥 {why} — r reiniciar · q sair',
    'ui.crash_floor'         => 'r reiniciar · q sair',
    'ui.ready_full'          => 'espaço/⏎/↑/w para começar · q sair',
    'ui.ready_short'         => 'espaço para começar · q sair',
    'ui.live_full'           => 'espaço/⏎/↑/w bate · r reiniciar · q sair',
    'ui.live_short'          => 'espaço bate · q sair',
    'ui.plain_crash_full'    => 'x {why} - pressione r para reiniciar - q para sair',
    'ui.plain_crash_short'   => 'x {why} - r reiniciar - q sair',
    'ui.plain_crash_floor'   => 'r reiniciar - q sair',
    'ui.why_splat'            => 'splat',
    'ui.why_ceiling'          => 'bateu no teto',
    'ui.why_ground'           => 'bateu no chão',
    'ui.why_pipe'             => 'bateu num cano',
    'ui.plain_why_splat'      => 'splat',
    'ui.plain_why_ceiling'    => 'bateu no teto',
    'ui.plain_why_ground'     => 'bateu no chão',
    'ui.plain_why_pipe'       => 'bateu num cano',
    'ui.plain_ready_full'    => 'espaco/Enter/up/w para comecar . q sair',
    'ui.plain_ready_short'   => 'espaco para comecar . q sair',
    'ui.plain_live_full'     => 'espaco/Enter/up/w bate . r reiniciar . q sair',
    'ui.plain_live_short'    => 'espaco bate . q sair',
    'ui.quit_hint' => 'pressione q para sair.',
    'ui.quarantine'           => '⚠ A tabela de recordes anterior estava ilegível — arquivada como {file}',
    'ui.plain_quarantine'     => 'x A tabela de recordes anterior estava ilegível - arquivada como {file}',
    'notty.line1'            => 'o candy-flappy precisa de um terminal capaz de mostrar sua saída.',
    'notty.line2'            => 'o stdout não é um terminal interativo ou TERM=dumb foi detectado.',
    'size.notice'            => 'o candy-flappy precisa de um terminal {need}; este aqui é {have}.',
    'size.resume'            => 'Redimensione a janela e o jogo continua aqui mesmo.',
];
