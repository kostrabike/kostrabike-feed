<?php
declare(strict_types=1);

namespace Feed;

/**
 * Doplnenie model_year. Priorita:
 *   1. data/manual_years.csv  – ručne zadané (vždy vyhráva, aj nad Shoptetom)
 *   2. Shoptet                – rok zo zdrojového feedu (vlastnosti / názov)
 *   3. data/model_years.csv   – cache automatického dohľadania na webe výrobcu
 *   4. prázdne
 * Kľúč je značka + model + farba (platí pre všetky veľkosti).
 */
final class YearResolver
{
    public const MANUAL_COLUMNS = ['brand', 'model', 'color', 'year', 'note'];
    public const CACHE_COLUMNS  = ['key', 'brand', 'model', 'color', 'year', 'status', 'candidates', 'source', 'checked'];

    /** @var array<string, string> key => year */
    private array $manual = [];
    /** @var array<string, array<string,string>> key => cache row */
    private array $cache = [];
    /** @var list<string> */
    public array $notes = [];

    public function __construct(private array $cfg, private string $manualPath, private string $cachePath)
    {
        foreach (self::readCsv($manualPath) as $i => $r) {
            $year = trim($r['year'] ?? '');
            if ($year === '') {
                continue;   // riadok čaká na doplnenie
            }
            if (!preg_match('/^20[0-3]\d$/', $year)) {
                $this->notes[] = sprintf('manual_years.csv riadok %d: neplatný rok "%s" – ignorujem', $i + 2, $year);
                continue;
            }
            $this->manual[self::key($r['brand'] ?? '', $r['model'] ?? '', $r['color'] ?? '')] = $year;
        }
        foreach (self::readCsv($cachePath) as $r) {
            if (($r['key'] ?? '') !== '') {
                $this->cache[$r['key']] = $r;
            }
        }
    }

    public static function key(string $brand, string $model, string $color): string
    {
        $n = static fn (string $s) => strtolower(NameParser::squash($s));
        return $n($brand) . '|' . $n($model) . '|' . YearLookup::normColor($color);
    }

    /**
     * Doplní model_year a _year_source do položiek. Pre chýbajúce kombinácie spustí dohľadanie.
     * @param array<int, array<string,string>> $items
     */
    public function apply(array &$items): void
    {
        $lookup = new YearLookup($this->cfg);
        $enabled = $this->cfg['enabled'];
        $retryBefore = date('Y-m-d', strtotime('-' . (int) $this->cfg['retry_days'] . ' days'));
        $looked = 0;

        foreach ($items as &$item) {
            $key = self::key($item['brand'], $item['model'], $item['color']);
            $item['_year_key'] = $key;

            if (isset($this->manual[$key])) {
                $item['model_year'] = $this->manual[$key];
                $item['_year_source'] = 'ručne';
                continue;
            }
            if ($item['model_year'] !== '') {
                $item['_year_source'] = 'shoptet';
                continue;
            }
            if (!$lookup->supports($item['brand'])) {
                $item['_year_source'] = '';
                continue;
            }

            $cached = $this->cache[$key] ?? null;
            $stale = $cached === null
                || ($cached['status'] !== 'match' && ($cached['checked'] ?? '') < $retryBefore);
            if ($enabled && $stale && $looked < $this->cfg['max_lookups_per_run']) {
                $looked++;
                $res = $lookup->resolve($item['brand'], $item['model'], $item['color']);
                if ($res['status'] !== 'error') {
                    $cached = $this->cache[$key] = [
                        'key'        => $key,
                        'brand'      => $item['brand'],
                        'model'      => $item['model'],
                        'color'      => $item['color'],
                        'year'       => $res['year'],
                        'status'     => $res['status'],
                        'candidates' => $res['candidates'],
                        'source'     => $res['source'],
                        'checked'    => date('Y-m-d'),
                    ];
                }
            }

            if ($cached !== null && $cached['status'] === 'match' && $cached['year'] !== '') {
                $item['model_year'] = $cached['year'];
                $item['_year_source'] = 'web';
            } else {
                $item['_year_source'] = '';
                $item['_year_hint'] = $cached ? trim($cached['status'] . ' ' . $cached['candidates']) : '';
            }
        }
        unset($item);

        if ($looked > 0) {
            $this->notes[] = sprintf('Dohľadanie roku: %d kombinácií, %d HTTP požiadaviek', $looked, $lookup->fetchedCount());
        }
        $this->saveCache();
    }

    private function saveCache(): void
    {
        $rows = $this->cache;
        ksort($rows);
        Csv::write($this->cachePath, self::CACHE_COLUMNS, $rows);
    }

    /** Vytvorí manual_years.csv s hlavičkou, ak neexistuje. */
    public static function ensureManualFile(string $path): void
    {
        if (!is_file($path)) {
            Csv::write($path, self::MANUAL_COLUMNS, []);
        }
    }

    /** @return list<array<string,string>> */
    private static function readCsv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        // oddeľovač: čiarka, alebo bodkočiarka (ak bol súbor uložený zo slovenského Excelu)
        $first = (string) fgets($fh);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        $header = fgetcsv($fh, null, $sep, '"', '');
        if (!$header) {
            fclose($fh);
            return [];
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h, " \t\u{FEFF}")), $header);
        $out = [];
        while (($row = fgetcsv($fh, null, $sep, '"', '')) !== false) {
            if ($row === [null]) continue;
            $out[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }
        fclose($fh);
        return $out;
    }
}
