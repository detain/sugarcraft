<?php

/**
 * Spanish translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: bandera desconocida '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: tema desconocido '{name}'. Conocidos: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: estilo de ventana desconocido '{style}'. Conocidos: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: formato desconocido '{format}'. Conocidos: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd es obligatorio para la salida PNG',
    'cli.font_not_found'      => "candy-codeshot: archivo de fuente no encontrado: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' no es un archivo de fuente válido (se esperaba TTF, OTF, WOFF o WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: formato de resaltado inválido '{format}'. Use inicio:fin (p. ej. 3:7) o inicio:fin:#color (p. ej. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: error al leer la entrada',
    'cli.path_outside_cwd'    => "candy-codeshot: la ruta '{path}' está fuera del directorio de trabajo actual (use --unsafe-paths para permitirlo)",
    'cli.write_failed'        => "candy-codeshot: error al escribir '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} requiere un valor",
    'cli.bad_number'          => "candy-codeshot: {option} espera un entero no negativo, se recibió '{value}'",
    'cli.png_ignores'         => "candy-codeshot: la salida PNG ignora opciones solo-SVG: {options} (use --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: resaltado '{spec}' rechazado: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: el formato de salida '{format}' no coincide con la extensión de '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - renderiza código o salida de terminal a una captura SVG o PNG

Uso:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Los valores de las banderas son argumentos separados: "--theme dracula", no "--theme=dracula"
(la forma "=" se rechaza como bandera no reconocida).

Opciones:
  --theme <nombre>        Nombre del tema: dark, light, dracula, tokyo-night, nord (por defecto: dark)
  --padding <n>           Relleno alrededor del contenido (por defecto: 24)
  --no-window             Ocultar la ventana (semáforos, barra de título)
  --no-shadow             Desactivar la sombra
  --no-border             Ocultar el borde
  --line-numbers          Mostrar números de línea
  --border-radius <n>     Radio de las esquinas (por defecto: 8)
  --window-style <estilo> Estilo de ventana: macos, windows-terminal, iterm, hyper, none (por defecto: macos)
  --ligatures             Activar ligaduras (font-variant-ligatures: normal; solo SVG)
  --font <ruta>           Ruta a un archivo de fuente TTF/OTF/WOFF/WOFF2 para incrustar en el SVG (solo SVG)
  --unsafe-paths          Permitir rutas de entrada/--font/--output fuera del CWD
  --highlight <inicio:fin[:color]>
                          Resalta líneas inicio:fin con color opcional (color por defecto: #fffbe6; solo SVG)
  --format <svg|png>      Formato de salida (por defecto: svg; png requiere ext-gd)
  -t, --type <idioma>     Fuerza el idioma del resaltado de sintaxis (por defecto: nombre de archivo, shebang, contenido)
  -o, --output <ruta>     Escribir la salida a un archivo en lugar de stdout
  -h, --help              Mostrar este mensaje de ayuda

Notas:
  Las rutas de entrada y salida deben permanecer dentro del directorio de trabajo
  actual salvo con --unsafe-paths. La salida PNG ignora las opciones solo-SVG y
  anuncia cada opción descartada en stderr.

Códigos de salida:
  0  éxito
  1  autoload de composer ausente (problema de instalación)
  2  error de uso o de entrada (bandera inválida, ruta ilegible, resaltado rechazado, ...)

Ejemplos:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
