<?php
declare(strict_types=1);

namespace Feed;

/** Report problémových riadkov: report.csv + index.html (GitHub Pages). */
final class Report
{
    public const LABELS = [
        'brand'             => 'iná značka',
        'visibility'        => 'nie je visible',
        'price'             => 'cena pod limitom',
        'not_bike'          => 'nie je bicykel / e-bike',
        'category_excluded' => 'vylúčená kategória',
        'category_unmapped' => 'nenamapovaná kategória',
        'stock'             => 'nie je skladom',
        'error'             => 'chyba (vylúčené)',
        'duplicate'         => 'duplicitný variant_id',
    ];

    /** @var list<array<string,string>> */
    private array $rows = [];

    public function add(string $level, string $reason, array $item): void
    {
        $this->rows[] = [
            'level'      => $level,
            'reason'     => $reason,
            'variant_id' => $item['variant_id'] ?? '',
            'name'       => $item['_name'] ?? '',
            'brand'      => $item['brand'] ?? '',
            'model'      => $item['model'] ?? '',
            'model_year' => $item['model_year'] ?? '',
            'year_source' => $item['_year_source'] ?? '',
            'size'       => $item['size'] ?? '',
            'color'      => $item['color'] ?? '',
            'category'   => $item['category'] ?? '',
            'source_category' => $item['_category_src'] ?? '',
            'price'      => $item['price'] ?? '',
            'quantity'   => $item['quantity'] ?? '',
        ];
    }

    public function count(string $level): int
    {
        return count(array_filter($this->rows, static fn ($r) => $r['level'] === $level));
    }

    public function writeCsv(string $path): void
    {
        $rows = $this->rows;
        usort($rows, static fn ($a, $b) => [$a['level'], $a['reason'], $a['name']] <=> [$b['level'], $b['reason'], $b['name']]);
        Csv::write($path, array_keys(self::emptyRow()), $rows);
    }

    /**
     * Kombinácie značka+model+farba vo feede bez roku – v rovnakom formáte ako data/manual_years.csv.
     * @param array<string, array<string,string>> $items
     * @return list<array<string,string>>
     */
    public static function missingYears(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if ($i['model_year'] !== '') continue;
            $k = $i['_year_key'] ?? ($i['brand'] . '|' . $i['model'] . '|' . $i['color']);
            if (!isset($out[$k])) {
                $out[$k] = ['brand' => $i['brand'], 'model' => $i['model'], 'color' => $i['color'], 'year' => '',
                            'note' => '', '_ids' => [], '_hint' => $i['_year_hint'] ?? ''];
            }
            $out[$k]['_ids'][] = $i['variant_id'] . ' ' . $i['size'];
        }
        foreach ($out as &$r) {
            $r['note'] = trim(($r['_hint'] !== '' ? $r['_hint'] . '; ' : '') . 'kódy: ' . implode(', ', $r['_ids']));
        }
        unset($r);
        usort($out, static fn ($a, $b) => [$a['brand'], $a['model'], $a['color']] <=> [$b['brand'], $b['model'], $b['color']]);
        return $out;
    }

    /** @param array<string, array<string,string>> $items */
    public function writeMissingYears(string $path, array $items): void
    {
        Csv::write($path, YearResolver::MANUAL_COLUMNS, self::missingYears($items));
    }

    /**
     * @param array<string,int|string> $stats
     * @param array<string, array<string,string>> $items
     * @param list<array<string,string|int>> $unusedManual riadky z manual_years.csv bez zodpovedajúceho produktu
     */
    public function writeHtml(string $path, array $stats, string $generatedAt, ?string $repoUrl, array $items = [], array $unusedManual = []): void
    {
        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $statRows = '';
        foreach ($stats as $k => $v) {
            $statRows .= '<tr><td>' . $h($k) . '</td><td class="n">' . $h($v) . '</td></tr>';
        }

        $sections = '';

        // chýbajúce roky – na doplnenie do data/manual_years.csv
        $missing = self::missingYears($items);
        $edit = $repoUrl ? ' <a href="' . $h($repoUrl) . '/edit/main/data/manual_years.csv">Upraviť data/manual_years.csv</a> ·' : '';
        $sections .= '<h2>Chýbajúci rok – na doplnenie (' . count($missing) . ' kombinácií)</h2>'
            . '<p class="muted">Rok platí pre všetky veľkosti danej kombinácie značka + model + farba.' . $edit
            . ' <a href="missing_years.csv">missing_years.csv</a> (rovnaký formát, stačí skopírovať riadky a doplniť rok)</p>';
        if ($missing) {
            $sections .= '<div class="scroll"><table><thead><tr><th>brand</th><th>model</th><th>color</th><th>Dohľadanie</th><th>Kódy (veľkosť)</th></tr></thead><tbody>';
            foreach ($missing as $m) {
                $sections .= '<tr><td>' . $h($m['brand']) . '</td><td>' . $h($m['model']) . '</td><td>' . $h($m['color'])
                    . '</td><td>' . $h($m['_hint']) . '</td><td>' . $h(implode(', ', $m['_ids'])) . '</td></tr>';
            }
            $sections .= '</tbody></table></div>';
        }

        // nepoužité ručné roky – na upratanie data/manual_years.csv (robot ich nikdy nemaže sám)
        $sections .= '<h2>Nepoužité riadky v manual_years.csv (' . count($unusedManual) . ')</h2>';
        if ($unusedManual) {
            $sections .= '<p class="muted">Tieto riadky nezodpovedajú žiadnemu produktu, ktorý by išiel do feedu (vypredaný, skrytý, zmazaný…). '
                . 'Môžeš ich bez rizika zmazať; ak sa produkt vráti, objaví sa znova v chýbajúcich rokoch. '
                . 'Ak si riadok pridal práve teraz a je tu, skontroluj preklep v značke, modeli alebo farbe.'
                . ($repoUrl ? ' <a href="' . $h($repoUrl) . '/edit/main/data/manual_years.csv">Upraviť data/manual_years.csv</a>' : '') . '</p>'
                . '<div class="scroll"><table><thead><tr><th class="n">Riadok</th><th>brand</th><th>model</th><th>color</th><th>year</th></tr></thead><tbody>';
            foreach ($unusedManual as $m) {
                $sections .= '<tr><td class="n">' . $h($m['line']) . '</td><td>' . $h($m['brand']) . '</td><td>' . $h($m['model'])
                    . '</td><td>' . $h($m['color']) . '</td><td>' . $h($m['year']) . '</td></tr>';
            }
            $sections .= '</tbody></table></div>';
        } else {
            $sections .= '<p class="muted">Žiadne – všetky ručne zadané roky sa používajú.</p>';
        }

        $titles = [
            'ERROR'   => 'Chyby – riadky NIE sú vo feede',
            'WARNING' => 'Varovania – riadky sú vo feede, ale Bikeroom ich nechá offline',
            'INFO'    => 'Vylúčené kategórie (na kontrolu)',
        ];
        foreach ($titles as $level => $title) {
            $rows = array_values(array_filter($this->rows, static fn ($r) => $r['level'] === $level));
            $sections .= '<h2>' . $h($title) . ' (' . count($rows) . ')</h2>';
            if (!$rows) {
                $sections .= '<p class="muted">Žiadne.</p>';
                continue;
            }
            $sections .= '<div class="scroll"><table><thead><tr><th>Dôvod</th><th>variant_id</th><th>Názov</th><th>Model</th><th>Rok</th><th>Veľkosť</th><th>Farba</th><th>Kategória</th><th>Cena</th><th>Ks</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $sections .= '<tr><td>' . $h($r['reason']) . '</td><td>' . $h($r['variant_id']) . '</td><td>' . $h($r['name'])
                    . '</td><td>' . $h($r['model']) . '</td><td>' . $h($r['model_year']) . '</td><td>' . $h($r['size'])
                    . '</td><td>' . $h($r['color']) . '</td><td>' . $h($r['category'] ?: $r['source_category'])
                    . '</td><td class="n">' . $h($r['price']) . '</td><td class="n">' . $h($r['quantity']) . '</td></tr>';
            }
            $sections .= '</tbody></table></div>';
        }

        $history = $repoUrl ? ' · <a href="' . $h($repoUrl) . '/tree/main/changes">História zmien</a> · <a href="' . $h($repoUrl) . '/commits/main/docs/feed.csv">História feedu</a>' : '';

        $html = <<<HTML
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bikeroom feed report</title>
<style>
:root { --bg:#fff; --fg:#1d1d1f; --muted:#6e6e73; --line:#e5e5ea; --head:#f5f5f7; --link:#0a66c2; }
@media (prefers-color-scheme: dark) { :root { --bg:#161618; --fg:#f2f2f7; --muted:#a1a1a6; --line:#2c2c2e; --head:#1f1f22; --link:#5ea8ff; } }
body { background:var(--bg); color:var(--fg); font:14px/1.45 system-ui, -apple-system, Segoe UI, sans-serif; margin:0 auto; padding:24px 16px; max-width:1400px; }
a { color:var(--link); } h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:17px; margin:32px 0 8px; }
.muted { color:var(--muted); } .scroll { overflow-x:auto; }
table { border-collapse:collapse; width:100%; } th, td { border-bottom:1px solid var(--line); padding:5px 8px; text-align:left; vertical-align:top; }
th { background:var(--head); position:sticky; top:0; font-weight:600; } td.n { text-align:right; font-variant-numeric:tabular-nums; }
table.stats { width:auto; min-width:320px; }
</style>
</head>
<body>
<h1>Bikeroom feed – KostraBike</h1>
<p class="muted">Vygenerované {$h($generatedAt)}</p>
<p><a href="feed.csv">feed.csv</a> · <a href="report.csv">report.csv</a>{$history}</p>
<h2>Súhrn</h2>
<table class="stats"><tbody>{$statRows}</tbody></table>
{$sections}
</body>
</html>
HTML;
        file_put_contents($path, $html);
    }

    private static function emptyRow(): array
    {
        return array_fill_keys(['level', 'reason', 'variant_id', 'name', 'brand', 'model', 'model_year', 'year_source', 'size', 'color', 'category', 'source_category', 'price', 'quantity'], '');
    }
}
