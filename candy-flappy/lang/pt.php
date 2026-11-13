<?php

/**
 * Portuguese translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'pontos: {score}',
    'ui.best'                => ' melhor: {best}',
    'ui.new_best'            => ' 🏆 NOVO RECORDE: {best}',
    'ui.plain_new_best'      => ' NOVO RECORDE: {best}',
    'ui.crash_full'          => '💥 splat — pressione r para tentar de novo, q para sair',
    'ui.crash_short'         => '💥 splat — r de novo · q sair',
    'ui.crash_floor'         => 'r de novo · q sair',
    'ui.ready_full'          => 'espaço/⏎/↑/w para começar · r reiniciar · q sair',
    'ui.ready_short'         => 'espaço para começar · q sair',
    'ui.live_full'           => 'espaço/⏎/↑/w bate · r reiniciar · q sair',
    'ui.live_short'          => 'espaço bate · q sair',
    'ui.plain_crash_full'    => 'x splat - pressione r para tentar de novo, q para sair',
    'ui.plain_crash_short'   => 'x splat - r de novo - q sair',
    'ui.plain_crash_floor'   => 'r de novo - q sair',
    'ui.plain_ready_full'    => 'espaco/Enter/^/w para comecar . r reiniciar . q sair',
    'ui.plain_ready_short'   => 'espaco para comecar . q sair',
    'ui.plain_live_full'     => 'espaco/Enter/^/w bate . r reiniciar . q sair',
    'ui.plain_live_short'    => 'espaco bate . q sair',
    'ui.quit_hint' => 'pressione q para sair.',
    'ui.quarantine'           => '⚠ A tabela de recordes anterior estava ilegível — arquivada como {file}',
    'ui.plain_quarantine'     => 'x A tabela de recordes anterior estava ilegível - arquivada como {file}',
    'notty.line1'            => 'o candy-flappy precisa de um terminal capaz de mostrar a sua saída.',
    'notty.line2'            => 'o stdout não é um terminal interativo ou foi detetado TERM=dumb.',
    'size.notice'            => 'o candy-flappy precisa de um terminal {need}; este tem {have}.',
    'size.resume'            => 'Redimensione a janela e o jogo continua exatamente aqui.',
];
