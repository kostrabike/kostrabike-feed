<?php
declare(strict_types=1);

/**
 * Generátor Bikeroom feedu z Shoptet exportu.
 *
 *   php build.php                  stiahne zdroj z FEED_SOURCE_URL
 *   php build.php --source=file.xlsx   použije lokálny súbor (testovanie)
 *
 * Výstupy:
 *   docs/feed.csv        – feed pre Bikeroom (iba validné riadky)
 *   docs/report.csv      – problémové riadky
 *   docs/index.html      – prehľad (GitHub Pages)
 *   changes/*.md         – história zmien (iba keď sa niečo zmenilo)
 *   tmp/notify.md        – text notifikácie (iba keď sa niečo zmenilo)
 */

use Feed\Csv;
use Feed\Diff;
use Feed\Mapper;
use Feed\Report;
use Feed\Source;
use Feed\YearResolver;

require __DIR__ . '/vendor/autoload.php';

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['timezone']);
$opts = getopt('', ['source:']);

try {
    $paths = $cfg['paths'];
    foreach ($paths as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
    }
    @unlink($paths['tmp'] . '/notify.md');

    $sourcePath = $opts['source'] ?? Source::download($cfg['source_url'], $paths['tmp']);

    $mapper = new Mapper($cfg);
    $report = new Report();
    $filtered = array_fill_keys(['brand', 'visibility', 'price', 'not_bike', 'category_excluded', 'category_unmapped', 'stock'], 0);
    $items = [];
    $candidates = [];
    $incomplete = [];
    $sourceRows = 0;
    $withWarnings = 0;

    foreach (Source::rows($sourcePath) as $r) {
        $sourceRows++;
        [$reason, $category] = $mapper->filter($r);
        if ($reason !== null) {
            $filtered[$reason]++;
            if ($reason === 'category_excluded' || $reason === 'category_unmapped') {
                $item = $mapper->map($r, '');
                $report->add($reason === 'category_unmapped' ? 'ERROR' : 'INFO', Report::LABELS[$reason], $item);
            }
            continue;
        }

        $candidates[] = $mapper->map($r, $category);
    }

    if ($sourceRows === 0) {
        throw new RuntimeException('Zdroj neobsahuje žiadne riadky.');
    }

    // ---- modelový rok: ručne > Shoptet > dohľadanie na webe výrobcu
    YearResolver::ensureManualFile($paths['data'] . '/manual_years.csv');
    $years = new YearResolver($cfg['year_lookup'], $paths['data'] . '/manual_years.csv', $paths['data'] . '/model_years.csv');
    $years->apply($candidates);
    foreach ($years->notes as $note) {
        echo $note . "\n";
    }

    foreach ($candidates as $item) {
        $check = $mapper->validate($item);
        if ($check['errors']) {
            $report->add('ERROR', implode('; ', $check['errors']), $item);
            continue;
        }
        if ($check['warnings']) {
            $withWarnings++;
            if ($cfg['exclude_rows_with_warnings']) {
                // neúplný riadok – do feedu nejde, ale zostane v reporte a v missing_years.csv
                $report->add('ERROR', 'neúplné: ' . implode('; ', $check['warnings']), $item);
                $incomplete[] = $item;
                continue;
            }
            $report->add('WARNING', implode('; ', $check['warnings']), $item);
        }
        if (isset($items[$item['variant_id']])) {
            $report->add('ERROR', Report::LABELS['duplicate'], $item);
            continue;
        }
        $items[$item['variant_id']] = $item;
    }

    uasort($items, static fn ($a, $b) => [$a['brand'], $a['model'], $a['color'], $a['size'], $a['variant_id']]
                                       <=> [$b['brand'], $b['model'], $b['color'], $b['size'], $b['variant_id']]);
    $columns = $cfg['columns'];
    $new = array_map(static fn ($i) => array_intersect_key($i, array_flip($columns)), $items);

    // ---- poistka proti publikovaniu rozbitého feedu
    $feedPath = $paths['public'] . '/feed.csv';
    $old = Csv::readIndexed($feedPath, 'variant_id');
    $safety = $cfg['safety'];
    if (count($new) < $safety['min_rows']) {
        throw new RuntimeException(sprintf('Feed má iba %d riadkov (minimum %d) – nepublikujem.', count($new), $safety['min_rows']));
    }
    if (count($old) > 0 && count($new) < count($old) * (1 - $safety['max_drop_ratio'])) {
        throw new RuntimeException(sprintf('Počet riadkov klesol z %d na %d (viac ako %d %%) – nepublikujem. Ak je to v poriadku, uprav safety.max_drop_ratio.',
            count($old), count($new), $safety['max_drop_ratio'] * 100));
    }

    // ---- výstupy
    Csv::write($feedPath, $columns, $new);
    $report->writeCsv($paths['public'] . '/report.csv');

    $now = date('Y-m-d H:i');
    $stats = [
        'Riadkov v zdroji'                     => $sourceRows,
        'Vyradené – ' . Report::LABELS['brand']      => $filtered['brand'],
        'Vyradené – ' . Report::LABELS['visibility'] => $filtered['visibility'],
        'Vyradené – ' . Report::LABELS['price']      => $filtered['price'],
        'Vyradené – ' . Report::LABELS['not_bike']   => $filtered['not_bike'],
        'Vyradené – ' . Report::LABELS['category_excluded'] => $filtered['category_excluded'],
        'Vyradené – ' . Report::LABELS['category_unmapped'] => $filtered['category_unmapped'],
        'Vyradené – ' . Report::LABELS['stock']      => $filtered['stock'],
        'Vyradené – neúplné (chýba rok / veľkosť / farba)' => count($incomplete),
        'Chyby (nie sú vo feede)'               => $report->count('ERROR') - $filtered['category_unmapped'] - count($incomplete),
        'Riadky vo feede'                       => count($new),
        '… z toho s varovaním (offline na Bikeroom)' => $cfg['exclude_rows_with_warnings'] ? 0 : $withWarnings,
    ];
    // roky a chýbajúce roky počítame zo všetkých kandidátov (vo feede + vyradené ako neúplné)
    $all = array_merge(array_values($items), $incomplete);
    $ys = array_count_values(array_map(static fn ($i) => $i['_year_source'] ?? '', $all));
    $stats['Rok – zo Shoptetu'] = $ys['shoptet'] ?? 0;
    $stats['Rok – dohľadaný na webe výrobcu'] = $ys['web'] ?? 0;
    $stats['Rok – zadaný ručne'] = $ys['ručne'] ?? 0;
    $stats['Rok – chýba'] = $ys[''] ?? 0;
    $unusedManual = $years->unusedManual();
    $stats['manual_years.csv – nepoužité riadky (dajú sa zmazať)'] = count($unusedManual);
    $repo = getenv('GITHUB_REPOSITORY') ?: null;
    $repoUrl = $repo ? 'https://github.com/' . $repo : null;
    $report->writeMissingYears($paths['public'] . '/missing_years.csv', $all);
    $report->writeHtml($paths['public'] . '/index.html', $stats, $now, $repoUrl, $all, $unusedManual);

    // ---- diff + história + notifikácia
    $diff = new Diff($old, $new, $columns);
    echo "Zdroj: $sourceRows riadkov, feed: " . count($new) . ' riadkov, chyby: ' . $report->count('ERROR') . ", varovania: $withWarnings\n";

    if (count($old) === 0) {
        echo "Prvý beh – bez porovnania.\n";
    } elseif ($diff->isEmpty()) {
        echo "Žiadne zmeny oproti predchádzajúcemu feedu.\n";
    } else {
        echo 'Zmeny: ' . $diff->summary() . "\n";
        $head = "# Zmeny feedu $now\n\n"
              . 'Riadkov vo feede: ' . count($old) . ' → ' . count($new) . "  \n"
              . 'Chyby: ' . $report->count('ERROR') . ", varovania: $withWarnings\n\n";
        $md = $head . $diff->toMarkdown();
        file_put_contents($paths['changes'] . '/' . date('Y-m-d_Hi') . '.md', $md);

        $pages = $repo ? sprintf('https://%s.github.io/%s/', strtolower(explode('/', $repo)[0]), explode('/', $repo)[1]) : '';
        $notify = $head . ($pages ? "Report: $pages\n\n" : '') . $diff->toMarkdown();
        if (strlen($notify) > 60000) {
            $notify = substr($notify, 0, 60000) . "\n\n… skrátené, celé zmeny sú v priečinku changes/.\n";
        }
        file_put_contents($paths['tmp'] . '/notify.md', $notify);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'CHYBA: ' . $e->getMessage() . "\n");
    exit(1);
}
