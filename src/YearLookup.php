<?php
declare(strict_types=1);

namespace Feed;

/**
 * Dohľadanie modelového roku na oficiálnom webe výrobcu (Giant / Liv).
 *
 * Princíp: výrobca mení farby každý ročník, takže model + názov farby určuje rok.
 * Zo sitemapy sa vyberú produktové stránky, ktorých adresa zodpovedá modelu
 * (napr. /sk/revolt-advanced-2, /sk/revolt-advanced-2-2025). Z každej sa vytiahne
 * rok (titulok "Revolt Advanced 2 (2025)") a zoznam farieb (špecifikácia "Farby").
 *
 * Číta iba verejné stránky povolené v robots.txt (/archive sa nepoužíva), s pauzou medzi požiadavkami.
 */
final class YearLookup
{
    /** @var array<string, list<string>> brand => URL zo sitemáp */
    private array $urls = [];
    /** @var array<string, array{year:string, colors:list<string>}> */
    private array $pages = [];
    private int $fetched = 0;

    public function __construct(private array $cfg)
    {
    }

    public function supports(string $brand): bool
    {
        return isset($this->cfg['sitemaps'][$brand]);
    }

    public function fetchedCount(): int
    {
        return $this->fetched;
    }

    /**
     * @return array{status:string, year:string, candidates:string, source:string}
     *   status: match | ambiguous | nomatch | nopage | error
     */
    public function resolve(string $brand, string $model, string $color): array
    {
        $result = ['status' => 'nopage', 'year' => '', 'candidates' => '', 'source' => ''];
        $hits = $this->candidates($brand, $model);
        if (!$hits) {
            return $result;
        }

        $want = self::normColor($color);
        $years = [];
        $sources = [];
        $all = [];
        foreach ($hits as $url) {
            $page = $this->page($url);
            if ($page === null) {
                $result['status'] = 'error';
                continue;
            }
            if ($page['year'] !== '') {
                $all[$page['year']] = true;
            }
            foreach ($page['colors'] as $c) {
                $nc = self::normColor($c);
                if ($want !== '' && $nc !== '' && $page['year'] !== ''
                    && ($nc === $want || str_contains($nc, $want) || str_contains($want, $nc))) {
                    $years[$page['year']] = true;
                    $sources[$page['year']] = $url;
                }
            }
        }
        if ($result['status'] === 'error' && !$years) {
            return $result;
        }

        ksort($years);
        ksort($all);
        if (count($years) === 1) {
            $y = array_key_first($years);
            return ['status' => 'match', 'year' => (string) $y, 'candidates' => (string) $y, 'source' => $sources[$y]];
        }
        if (count($years) > 1) {
            return ['status' => 'ambiguous', 'year' => '', 'candidates' => implode('/', array_keys($years)), 'source' => implode(' ', array_values($sources))];
        }
        return ['status' => 'nomatch', 'year' => '', 'candidates' => 'na webe: ' . implode('/', array_keys($all)), 'source' => ''];
    }

    /** @return list<string> */
    private function candidates(string $brand, string $model): array
    {
        $variants = array_unique([
            self::slug($model),
            self::slug(preg_replace('/(\d)er\b/', '$1', $model)),   // "29er" -> "29"
            self::slug(preg_replace('/\s+Disc\b/i', '', $model)),
        ]);
        $hits = [];
        foreach ($this->sitemapUrls($brand) as $url) {
            $last = basename(rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/'));
            $base = trim(preg_replace('/-+/', '-', $last), '-');
            $base = preg_replace('/-20[12]\d$/', '', $base);
            if (in_array($base, $variants, true)) {
                $hits[] = $url;
            }
        }
        return $hits;
    }

    /** @return list<string> */
    private function sitemapUrls(string $brand): array
    {
        if (!isset($this->urls[$brand])) {
            $this->urls[$brand] = [];
            foreach ($this->cfg['sitemaps'][$brand] ?? [] as $sitemap) {
                $xml = $this->http($sitemap);
                if ($xml !== null && preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m)) {
                    array_push($this->urls[$brand], ...$m[1]);
                }
            }
        }
        return $this->urls[$brand];
    }

    /** @return array{year:string, colors:list<string>}|null */
    private function page(string $url): ?array
    {
        if (array_key_exists($url, $this->pages)) {
            return $this->pages[$url];
        }
        $body = $this->http($url);
        if ($body === null) {
            return $this->pages[$url] = null;
        }
        $year = '';
        if (preg_match('#<title>[^<]*\((20[12]\d)\)#', $body, $m)) {
            $year = $m[1];
        } elseif (preg_match('/MY(2\d)[A-Z_]/', $body, $m)) {
            $year = '20' . $m[1];
        }
        $colors = [];
        $labels = implode('|', array_map('preg_quote', $this->cfg['color_labels']));
        if (preg_match('#<div class=["\']?label["\']?>(?:' . $labels . ')</div>\s*<div class=["\']?value["\']?>([^<]*)</div>#u', $body, $m)) {
            $colors = array_values(array_filter(array_map('trim', explode(',', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5))), 'strlen'));
        }
        return $this->pages[$url] = ['year' => $year, 'colors' => $colors];
    }

    private function http(string $url): ?string
    {
        usleep((int) ($this->cfg['delay_ms'] * 1000));
        $this->fetched++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => $this->cfg['user_agent'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }

    public static function slug(string $s): string
    {
        $s = strtolower(str_replace(['+', '/'], ['plus', '-'], $s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim(preg_replace('/-+/', '-', $s), '-');
    }

    public static function normColor(string $s): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($s));
    }
}
