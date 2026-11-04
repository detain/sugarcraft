<?php

/**
 * Portuguese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tabuleiro demasiado pequeno',
    'board.minecount_out_of_range' => 'número de minas fora do intervalo',
    'board.rows_shape_mismatch' => 'o formato das linhas não corresponde a largura/altura',
    'custom.min_rows' => 'as linhas devem ser pelo menos 2',
    'custom.min_cols' => 'as colunas devem ser pelo menos 2',
    'custom.max_rows' => 'as linhas devem ser no máximo 50',
    'custom.max_cols' => 'as colunas devem ser no máximo 50',
    'custom.min_mines' => 'pelo menos 1 mina é obrigatória',
    'custom.max_mines' => 'minas demais — deixe uma área inicial segura',
    'ui.status' => 'minas: {mines}  ·  bandeiras: {flags}  ·  restantes: {remaining}  ·  tempo: {time}',
    'ui.boom' => '💥 boom — prima r para reiniciar',
    'ui.cleared' => '★ limpo — prima r para jogar novamente',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  mover  ·  espaço / enter  revelar',
    'ui.help_board' => 'f  bandeira  ·  c / botão do meio  ao redor  ·  botão direito  bandeira',
    'ui.help_session' => 'r  reiniciar  ·  S  guardar  ·  L  carregar  ·  q / Esc / Ctrl+C  sair',
    'ui.saved' => 'Jogo guardado',
    'ui.restarted' => 'Tabuleiro reiniciado',
    'ui.loaded' => 'Jogo carregado',
    'ui.no_save' => 'Nenhum jogo para carregar',
    'ui.load_refused' => 'Jogo recusado (ilegível ou adulterado)',
    'ui.save_failed' => 'Falha ao guardar',
    'ui.resume' => 'Há um jogo à espera — prima L para o carregar',
    'ui.new_best' => 'NOVO RECORDE',
];
