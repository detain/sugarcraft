<?php

/**
 * Brazilian Portuguese UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'pontos: %d
linhas: %d
nível:  %d',
    'help_card'  => 'mover
girar horário
girar anti-horário
queda suave
queda rápida
guardar
pausar
reiniciar (fim)
sair',
    'next_label' => 'próxima:',
    'hold_label' => 'reserva:',
    'paused'     => '[ pausado — pressione p para retomar ]',

    // Game-over banner
    'over.title'                => 'FIM DE JOGO',
    'over.final_score'          => 'pontuação final: {points}',
    'over.restart_hint'         => 'pressione r para reiniciar — pressione q para sair',
    'over.reason'               => 'motivo: {reason}',
    'over.reason.spawn_blocked' => 'sem espaço para a próxima peça',
    'over.reason.top_out'       => 'a pilha atingiu o topo',
    'over.reason.crushed'       => 'esmagado pelo lixo',

    // On-frame cues
    'hold_used'  => '(usada)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B armado',
    'over.best'    => 'recorde: {points}',
    'over.new_best' => 'novo recorde!',

    // Versus renderer
    'label.player'   => 'JOGADOR',
    'label.computer' => 'COMPUTADOR',
    'win.player'     => 'VOCÊ VENCEU!',
    'win.computer'   => 'O COMPUTADOR VENCEU!',
    'over.quit_hint' => 'pressione q para sair',
    'vs_keys'        => 'queda
guardar
pausar
reiniciar
sair',
    'vs.record' => 'placar: {wins} vitórias, {losses} derrotas',
    'intro.title' => 'bem-vindo ao candy-tetris',
    'intro.goal' => 'elimine linhas - não chegue ao topo',
    'intro.press' => 'pressione {keys} para jogar',
    'intro.vs' => 'modo versus: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modo desconhecido "{flag}"',
    'help_text'    => 'candy-tetris - clone de Tetris

Uso: candy-tetris [opções]
  (sem args) Iniciar partida solo
  -v, --vs   Modo contra o computador
  -h, --help Mostrar esta ajuda
  --version  Exibir a versão
'
];
