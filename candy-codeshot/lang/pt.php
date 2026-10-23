<?php

/**
 * Portuguese translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: opção desconhecida '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: tema desconhecido '{name}'. Conhecidos: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: estilo de janela desconhecido '{style}'. Conhecidos: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: formato desconhecido '{format}'. Conhecidos: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd é obrigatório para saída PNG',
    'cli.font_not_found'      => "candy-codeshot: arquivo de fonte não encontrado: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' não é um arquivo de fonte válido (esperado TTF, OTF, WOFF ou WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: formato de destaque inválido '{format}'. Use inicio:fim (ex. 3:7) ou inicio:fim:#cor (ex. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: falha ao ler a entrada',
    'cli.path_outside_cwd'    => "candy-codeshot: o caminho '{path}' está fora do diretório de trabalho atual (use --unsafe-paths para permitir)",
    'cli.write_failed'        => "candy-codeshot: falha ao escrever '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} requer um valor",
    'cli.bad_number'          => "candy-codeshot: {option} espera um inteiro não negativo, recebido '{value}'",
    'cli.png_ignores'         => "candy-codeshot: a saída PNG ignora opções somente-SVG: {options} (use --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: destaque '{spec}' rejeitado: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: o formato de saída '{format}' não corresponde à extensão de '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - renderiza código ou saída de terminal como captura SVG ou PNG

Uso:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Os valores das opções são argumentos separados: "--theme dracula", não "--theme=dracula"
(a forma "=" é rejeitada como opção desconhecida).

Opções:
  --theme <nome>          Nome do tema: dark, light, dracula, tokyo-night, nord (padrão: dark)
  --padding <n>           Espaçamento ao redor do conteúdo (padrão: 24)
  --no-window             Ocultar a janela (semáforos, barra de título)
  --no-shadow             Desativar a sombra
  --no-border             Ocultar a borda
  --line-numbers          Mostrar números de linha
  --border-radius <n>     Raio dos cantos (padrão: 8)
  --window-style <estilo> Estilo de janela: macos, windows-terminal, iterm, hyper, none (padrão: macos)
  --ligatures             Ativar ligaduras (font-variant-ligatures: normal; somente SVG)
  --font <caminho>        Caminho de um arquivo de fonte TTF/OTF/WOFF/WOFF2 para incorporar no SVG (somente SVG)
  --unsafe-paths          Permitir caminhos de entrada/--font/--output fora do CWD
  --highlight <inicio:fim[:cor]>
                          Destaca linhas inicio:fim com cor opcional (cor padrão: #fffbe6; somente SVG)
  --format <svg|png>      Formato de saída (padrão: svg; png requer ext-gd)
  -t, --type <idioma>     Força o idioma do realce de sintaxe (padrão: nome do arquivo, shebang, conteúdo)
  -o, --output <caminho>  Escrever a saída em arquivo em vez de stdout
  -h, --help              Mostrar esta mensagem de ajuda

Notas:
  Os caminhos de entrada e saída devem permanecer dentro do diretório de trabalho
  atual, salvo com --unsafe-paths. A saída PNG ignora as opções somente-SVG e anuncia
  cada opção descartada em stderr.

Códigos de saída:
  0  sucesso
  1  autoload do composer ausente (problema de instalação)
  2  erro de uso ou de entrada (opção inválida, caminho ilegível, destaque rejeitado, ...)

Exemplos:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
