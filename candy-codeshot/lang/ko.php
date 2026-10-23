<?php

/**
 * Korean translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: 인식할 수 없는 플래그 '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: 알 수 없는 테마 '{name}'. 알려진 테마: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: 알 수 없는 창 스타일 '{style}'. 알려진 스타일: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: 알 수 없는 형식 '{format}'. 알려진 형식: svg, png",
    'cli.gd_required'         => 'candy-codeshot: PNG 출력에는 ext-gd가 필요합니다',
    'cli.font_not_found'      => "candy-codeshot: 글꼴 파일을 찾을 수 없습니다: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}'는 유효한 글꼴 파일이 아닙니다 (TTF, OTF, WOFF 또는 WOFF2 필요)",
    'cli.bad_highlight'       => "candy-codeshot: 잘못된 강조 형식 '{format}'. 시작:끝 (예: 3:7) 또는 시작:끝:#색 (예: 3:7:#fffbe6)을 사용하세요",
    'cli.read_failed'          => 'candy-codeshot: 입력 읽기 실패',
    'cli.path_outside_cwd'    => "candy-codeshot: 경로 '{path}'가 현재 작업 디렉터리 밖에 있습니다 (허용하려면 --unsafe-paths 사용)",
    'cli.write_failed'        => "candy-codeshot: 쓰기 실패 '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option}에는 값이 필요합니다",
    'cli.bad_number'          => "candy-codeshot: {option}은(는) 음이 아닌 정수를 기대합니다, 받은 값 '{value}'",
    'cli.png_ignores'         => "candy-codeshot: PNG 출력은 SVG 전용 옵션을 무시합니다: {options} (--format svg를 사용하세요)",
    'cli.highlight_rejected'  => "candy-codeshot: 강조 '{spec}' 거부됨: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: 출력 형식 '{format}'이(가) '{path}'의 확장자와 일치하지 않습니다",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - 코드 또는 터미널 출력을 SVG 또는 PNG 스크린샷으로 렌더링

사용법:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

플래그 값은 별도 인자입니다: "--theme dracula", "--theme=dracula"가 아님
("=" 형식은 인식할 수 없는 플래그로 거부됩니다).

옵션:
  --theme <name>          테마 이름: dark, light, dracula, tokyo-night, nord (기본: dark)
  --padding <n>           내용 주변 여백 (기본: 24)
  --no-window             창 장식 숨김 (신호등, 제목 표시줄)
  --no-shadow             그림자 효과 비활성화
  --no-border             테두리 숨김
  --line-numbers          줄 번호 표시
  --border-radius <n>     모서리 반지름 (기본: 8)
  --window-style <style>  창 스타일: macos, windows-terminal, iterm, hyper, none (기본: macos)
  --ligatures             합자(Ligature) 활성화 (font-variant-ligatures: normal; SVG 전용)
  --font <path>           SVG에 포함할 TTF/OTF/WOFF/WOFF2 글꼴 파일 경로 (SVG 전용)
  --unsafe-paths          입력/--font/--output 경로의 CWD 외부 접근 허용
  --highlight <start:end[:color]>
                          시작:끝 줄을 선택적 색으로 강조 (기본 색: #fffbe6; SVG 전용)
  --format <svg|png>      출력 형식 (기본: svg; png는 ext-gd 필요)
  -t, --type <lang>       구문 강조 언어 강제 (기본: 파일명, shebang, 내용)
  -o, --output <path>     stdout 대신 파일로 출력
  -h, --help              이 도움말 표시

주의:
  입력 및 출력 경로는 --unsafe-paths가 없으면 현재 작업 디렉터리 내에
  있어야 합니다. PNG 출력은 위 SVG 전용 옵션을 무시하며 각 생략된 옵션을
  stderr로 알림니다.

종료 코드:
  0  성공
  1  composer autoload 없음 (설치 문제)
  2  사용법 또는 입력 오류 (잘못된 플래그, 읽을 수 없는 경로, 거부된 강조 등)

예시:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
