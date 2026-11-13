<?php

/**
 * Brazilian Portuguese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tabuleiro muito pequeno',
    'board.minecount_out_of_range' => 'número de minas fora do intervalo',
    'board.rows_shape_mismatch' => 'os dados da grade não correspondem ao tabuleiro {width}×{height}',
    'custom.min_rows' => 'as linhas devem ser no mínimo 2',
    'custom.min_cols' => 'as colunas devem ser no mínimo 2',
    'custom.max_rows' => 'as linhas devem ser no máximo 50',
    'custom.max_cols' => 'as colunas devem ser no máximo 50',
    'custom.min_mines' => 'pelo menos 1 mina é necessária',
    'custom.max_mines' => 'minas demais — deixe uma área inicial segura',
    'custom.min_area'            => 'um tabuleiro precisa de pelo menos 10 células (linhas × colunas)',
    'custom.label' => '{width}×{height} · {mines} minas',
    'ui.status' => 'minas: {mines}  ·  bandeiras: {flags}  ·  restantes: {remaining}  ·  tempo: {time}',
    'ui.status_stats' => '{preset}  ·  bandeiras: {flags}  ·  restantes: {remaining}  ·  tempo: {time}  ·  recorde: {best}',
    'ui.preset_easy' => 'fácil',
    'ui.preset_standard' => 'padrão',
    'ui.preset_medium' => 'médio',
    'ui.preset_expert' => 'expert',
    'ui.boom' => '💥 boom — pressione r para reiniciar · n para outro tabuleiro',
    'ui.cleared' => '★ vencido — pressione r para jogar novamente · n para outro tabuleiro',
    'ui.help_rules' => 'um número = minas próximas · marque as suspeitas · abra tudo para vencer',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  mover  ·  espaço / enter  revelar',
    'ui.help_board' => 'clique esquerdo  abrir · f / direito  bandeira · c / do meio  ao redor',
    'ui.help_loss' => '{mark} bandeira errada · * mina perdida',
    'ui.help_session' => 'r  reiniciar  ·  S  salvar  ·  L  carregar  ·  q / Esc / Ctrl+C  sair',
    'ui.saved' => 'Jogo salvo',
    'ui.restarted' => 'Tabuleiro reiniciado',
    'ui.loaded' => 'Jogo carregado',
    'ui.no_save' => 'Nenhum jogo para carregar',
    'ui.load_refused' => 'Jogo recusado (ilegível ou adulterado)',
    'ui.save_failed' => 'Falha ao salvar',
    'ui.chord_not_ready' => 'o acorde exige bandeiras iguais ao número',
    'ui.confirm_quit' => 'pressione q novamente para sair — o jogo não foi salvo',
    'ui.resume' => 'Há um jogo aguardando — pressione L para carregá-lo',
    'ui.new_best' => 'NOVO RECORDE',
    'ui.beat_best' => '{prev} → NOVO RECORDE',
    'ui.picker_title' => 'escolha um tabuleiro',
    'ui.picker_custom' => 'personalizado',
    'ui.picker_hint' => '1-4 começar · c tabuleiro personalizado · q / Esc voltar',
    'ui.form_width' => 'largura',
    'ui.form_height' => 'altura',
    'ui.form_mines' => 'minas',
    'ui.form_hint' => 'digite os números · enter avança · enter em minas inicia · esc volta',
    'ui.fit_notice' => 'o candy-minesweeper precisa de um terminal com {need} linhas; este tem {have}. Aumente a janela — o jogo retoma assim que couber. q para sair.',
];
