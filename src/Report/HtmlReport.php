<?php

declare(strict_types=1);

namespace AnyLint\Report;

/**
 * Самодостатній HTML-звіт: один файл без зовнішніх скриптів, шрифтів і
 * CDN - відкривається офлайн, прикріплюється як артефакт CI, світла й
 * темна тема за системними налаштуваннями.
 *
 * @phpstan-import-type ReportData from ProjectReport
 */
final class HtmlReport
{
    private const CSS = <<<'CSS'
        :root {
          --bg: #f7f7f5; --surface: #ffffff; --text: #1f2328; --muted: #5f6670;
          --border: #e3e3df; --bar: #4c6ef5; --error: #c92a2a; --warning: #b26b00; --info: #1971c2;
          color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
          :root {
            --bg: #15171a; --surface: #1e2125; --text: #e6e8eb; --muted: #9aa1aa;
            --border: #2e3237; --bar: #748ffc; --error: #ff6b6b; --warning: #fcc419; --info: #74c0fc;
            color-scheme: dark;
          }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
          font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 24px; word-break: break-all; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .tile, section { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .tile { padding: 14px 16px; }
        .tile b { display: block; font-size: 24px; font-variant-numeric: tabular-nums; }
        .tile span { color: var(--muted); font-size: 13px; }
        section { padding: 18px 20px; margin-bottom: 16px; overflow-x: auto; }
        .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .grid2 section { margin-bottom: 0; }
        .grid2 + section, .grid2 { margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; color: var(--muted); font-weight: 500; font-size: 13px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .path { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; word-break: break-all; }
        .bar { height: 8px; border-radius: 4px; background: var(--bar); min-width: 2px; }
        .barcell { width: 40%; }
        .sev { font-weight: 600; font-size: 12px; text-transform: uppercase; }
        .sev-error { color: var(--error); } .sev-warning { color: var(--warning); } .sev-info { color: var(--info); }
        .empty { color: var(--muted); margin: 0; }
        details { border-top: 1px solid var(--border); padding: 8px 0; }
        details:first-of-type { border-top: 0; }
        summary { cursor: pointer; }
        summary .n { color: var(--muted); margin-left: 8px; }
        details table { margin-top: 8px; }
        CSS;

    /** @param ReportData $report */
    public static function render(array $report, string $title): string
    {
        $s = $report['summary'];
        $sev = $report['severities'];
        $dup = $report['duplication'];

        $tiles = self::tile(number_format($s['files']), 'файлів')
            . self::tile(number_format($s['linesOfCode']), 'рядків коду')
            . self::tile(number_format($s['functions']), 'функцій')
            . self::tile(number_format($s['findings']), 'знахідок')
            . self::tile(sprintf('%.1f%%', $dup['percent']), 'дубльованого коду');

        $body = '<h1>Звіт anylint</h1><p class="sub">' . self::e($title) . '</p>'
            . '<div class="tiles">' . $tiles . '</div>'
            . '<div class="grid2">'
            . self::barSection('Мови', 'рядків коду', array_map(
                static fn (array $r) => [$r['language'] . ' · ' . $r['files'] . ' файлів', $r['linesOfCode']],
                $report['languages'],
            ))
            . self::barSection('Знахідки за правилами', 'кількість', array_map(
                static fn (array $r) => [$r['rule'], $r['count']],
                $report['rules'],
            ))
            . '</div>'
            . self::severitySection($sev)
            . self::tableSection(
                'Найскладніші функції',
                ['Функція', 'Файл', 'Когнітивна', 'Цикломатична', 'Стейтментів'],
                array_map(static fn (array $r) => [
                    self::e($r['name']),
                    '<span class="path">' . self::e($r['file']) . ':' . $r['line'] . '</span>',
                    $r['cognitive'],
                    $r['cyclomatic'],
                    $r['statements'],
                ], $report['complexFunctions']),
            )
            . self::tableSection(
                'Гарячі файли (помилка = 3, попередження = 1)',
                ['Файл', 'Вага', 'Помилок', 'Попереджень', 'Рядків'],
                array_map(static fn (array $r) => [
                    '<span class="path">' . self::e($r['file']) . '</span>',
                    $r['score'],
                    $r['errors'],
                    $r['warnings'],
                    $r['linesOfCode'],
                ], $report['hotspots']),
            )
            . self::tableSection(
                sprintf('Дублювання: %d фрагментів, %d рядків', $dup['fragments'], $dup['duplicatedLines']),
                ['Файл', 'Дубльованих рядків'],
                array_map(static fn (array $r) => [
                    '<span class="path">' . self::e($r['file']) . '</span>',
                    $r['duplicatedLines'],
                ], $dup['files']),
            )
            . self::findingsSection($report['findings']);

        return "<!doctype html>\n<html lang=\"uk\"><head><meta charset=\"utf-8\">"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Звіт anylint</title><style>' . self::CSS . '</style></head>'
            . '<body><main>' . $body . "</main></body></html>\n";
    }

    private static function tile(string $value, string $label): string
    {
        return '<div class="tile"><b>' . self::e($value) . '</b><span>' . self::e($label) . '</span></div>';
    }

    /** @param list<array{0: string, 1: int}> $rows */
    private static function barSection(string $title, string $unit, array $rows): string
    {
        if ($rows === []) {
            return '<section><h2>' . self::e($title) . '</h2><p class="empty">Немає.</p></section>';
        }
        $max = max(1, ...array_map(static fn (array $r) => $r[1], $rows));
        $html = '';
        foreach ($rows as [$label, $value]) {
            $width = round(100 * $value / $max, 1);
            $html .= '<tr><td>' . self::e($label) . '</td><td class="barcell"><div class="bar" style="width:' . $width . '%"></div></td>'
                . '<td class="n">' . number_format($value) . '</td></tr>';
        }
        return '<section><h2>' . self::e($title) . '</h2><table><thead><tr><th></th><th></th><th class="n">'
            . self::e($unit) . '</th></tr></thead><tbody>' . $html . '</tbody></table></section>';
    }

    /** @param array{error: int, warning: int, info: int} $sev */
    private static function severitySection(array $sev): string
    {
        $cells = '';
        foreach (['error' => 'помилки', 'warning' => 'попередження', 'info' => 'info'] as $key => $label) {
            $cells .= '<td><span class="sev sev-' . $key . '">' . $label . '</span></td><td class="n">' . $sev[$key] . '</td>';
        }
        return '<section><h2>Серйозність</h2><table><tbody><tr>' . $cells . '</tr></tbody></table></section>';
    }

    /**
     * @param list<string> $headers стовпці з числами вирівнюються праворуч
     * @param list<list<string|int>> $rows клітинки вже екрановані
     */
    private static function tableSection(string $title, array $headers, array $rows): string
    {
        if ($rows === []) {
            return '<section><h2>' . self::e($title) . '</h2><p class="empty">Немає.</p></section>';
        }
        $head = '';
        foreach ($headers as $i => $header) {
            $head .= (is_int($rows[0][$i] ?? null) ? '<th class="n">' : '<th>') . self::e($header) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= is_int($cell) ? '<td class="n">' . $cell . '</td>' : '<td>' . $cell . '</td>';
            }
            $body .= '</tr>';
        }
        return '<section><h2>' . self::e($title) . '</h2><table><thead><tr>' . $head . '</tr></thead><tbody>'
            . $body . '</tbody></table></section>';
    }

    /** @param list<array{file: string, line: int, rule: string, severity: string, message: string}> $findings */
    private static function findingsSection(array $findings): string
    {
        if ($findings === []) {
            return '<section><h2>Усі знахідки</h2><p class="empty">Проблем не знайдено.</p></section>';
        }
        $byFile = [];
        foreach ($findings as $finding) {
            $byFile[$finding['file']][] = $finding;
        }
        $html = '';
        foreach ($byFile as $file => $items) {
            $rows = '';
            foreach ($items as $f) {
                $rows .= '<tr><td class="n">' . $f['line'] . '</td><td><span class="sev sev-' . self::e($f['severity']) . '">'
                    . self::e($f['severity']) . '</span></td><td class="path">' . self::e($f['rule']) . '</td><td>'
                    . self::e($f['message']) . '</td></tr>';
            }
            $html .= '<details><summary><span class="path">' . self::e((string) $file) . '</span><span class="n">'
                . count($items) . '</span></summary><table><tbody>' . $rows . '</tbody></table></details>';
        }
        return '<section><h2>Усі знахідки</h2>' . $html . '</section>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
