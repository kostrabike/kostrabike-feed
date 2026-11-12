<?php
declare(strict_types=1);

namespace Feed;

/** Prevod jedného riadku Shoptet feedu na riadok Bikeroom feedu. */
final class Mapper
{
    private NameParser $parser;

    public function __construct(private array $cfg)
    {
        $this->parser = new NameParser($cfg['size_tokens']);
    }

    /**
     * Rozhodne, či riadok prejde základnými filtrami.
     * @return array{0:?string, 1:?string} [dôvod vyradenia | null, bikeroom kategória]
     *   dôvody: brand, visibility, price, not_bike, category_excluded, category_unmapped, stock
     */
    public function filter(array $r): array
    {
        $f = $this->cfg['filters'];
        if (!array_key_exists(trim($r['manufacturer'] ?? ''), $f['brands'])) {
            return ['brand', null];
        }
        if (!in_array(trim($r['productVisibility'] ?? ''), $f['visibility'], true)) {
            return ['visibility', null];
        }
        $price = self::number($r['price'] ?? '');
        if ($price === null || ($f['min_price_inclusive'] ? $price < $f['min_price'] : $price <= $f['min_price'])) {
            return ['price', null];
        }

        $cat = self::categoryPath($r['categoryText'] ?? '');
        $root = explode(' > ', $cat)[0];
        if (!in_array($root, $this->cfg['bike_roots'], true)) {
            return ['not_bike', null];
        }
        $mapped = false;
        $category = null;
        foreach ($this->cfg['categories'] as $prefix => $value) {
            if (str_starts_with($cat, $prefix)) {
                $mapped = true;
                $category = $value;
                break;
            }
        }
        if (!$mapped) {
            return ['category_unmapped', null];
        }
        if ($category === null) {
            return ['category_excluded', null];
        }

        if ($f['min_stock'] !== null && (self::number($r['stock'] ?? '') ?? 0) < $f['min_stock']) {
            return ['stock', $category];
        }
        return [null, $category];
    }

    /** @return array<string, string> riadok výstupu (stĺpce podľa config columns) */
    public function map(array $r, string $category): array
    {
        $manufacturer = trim($r['manufacturer']);
        $props = self::props($r);
        $sources = array_merge($this->cfg['sources'], $this->cfg['brand_overrides'][$manufacturer] ?? []);

        // veľkosť zo štruktúrovaných zdrojov (bez 'name') – pomôže parseru nájsť ju v názve
        $structSize = $this->pick(array_diff($sources['size'], ['name']), $r, $props, [], 'size');
        $parsed = $this->parser->parse($r['name'] ?? '', $this->cfg['name_prefixes'][$manufacturer] ?? [$manufacturer], $structSize,
            $this->cfg['name_styles'][$manufacturer] ?? '');
        $fromName = ['size' => $parsed['size'], 'color' => $parsed['color'], 'model_year' => $parsed['year'], 'model' => $parsed['model']];

        $preowned = (bool) preg_match($this->cfg['preowned']['pattern'], ($r['name'] ?? '') . ' ' . ($r['shortDescription'] ?? ''));
        $price = self::number($r['price']);
        $code = trim($r['code'] ?? '');

        return [
            'product_id'      => $code,
            'variant_id'      => $code,
            'brand'           => $this->cfg['filters']['brands'][$manufacturer],
            'model'           => $this->pick($sources['model'], $r, $props, $fromName, 'model'),
            'model_year'      => $this->pick($sources['model_year'], $r, $props, $fromName, 'model_year'),
            'product_type'    => 'BICYCLE',
            'category'        => $category,
            'condition'       => $preowned ? 'PREOWNED' : 'NEW',
            'condition_grade' => $preowned ? (string) ($this->cfg['preowned']['grade'] ?? '') : '',
            'size'            => $this->pick($sources['size'], $r, $props, $fromName, 'size'),
            'color'           => $this->pick($sources['color'], $r, $props, $fromName, 'color'),
            'quantity'        => (string) (int) (self::number($r['stock'] ?? '') ?? 0),
            'price'           => $price === null ? '' : number_format($price, 2, '.', ''),
            'image_url'       => $this->pick($sources['image_url'], $r, $props, [], 'image_url'),
            // pomocné (nejdú do feedu, iba do reportu)
            '_name'           => $r['name'] ?? '',
            '_category_src'   => self::categoryPath($r['categoryText'] ?? ''),
        ];
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public function validate(array $item): array
    {
        $e = [];
        $w = [];
        if ($item['product_id'] === '' || $item['variant_id'] === '') $e[] = 'chýba product_id/variant_id (code)';
        if ($item['brand'] === '' || $item['model'] === '')           $e[] = 'chýba brand alebo model';
        if ((float) $item['price'] <= 0)                              $e[] = 'neplatná cena';
        if ((int) $item['quantity'] <= 0)                             $e[] = 'quantity <= 0';
        if (!str_starts_with($item['image_url'], 'https://'))         $e[] = 'chýba image_url (https)';
        if ($item['condition'] === 'PREOWNED' && $item['condition_grade'] === '') $e[] = 'použitý tovar – chýba condition_grade';

        if ($item['model_year'] === '')                               $w[] = 'chýba model_year';
        if ($item['size'] === '' || $item['size'] === 'NONE')        $w[] = 'chýba size';
        if ($item['color'] === '' || $item['color'] === 'PHOTO')     $w[] = 'chýba color';
        return ['errors' => $e, 'warnings' => $w];
    }

    private function pick(array $sources, array $r, array $props, array $fromName, string $field): string
    {
        foreach ($sources as $src) {
            if ($src === 'name') {
                $v = $fromName[$field] ?? '';
            } elseif (str_starts_with($src, 'prop:')) {
                $v = $props[substr($src, 5)] ?? '';
            } else {
                $v = $r[$src] ?? '';
            }
            $v = $this->clean($field, (string) $v);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private function clean(string $field, string $v): string
    {
        $v = preg_replace('/<br\s*\/?>.*$/isu', '', $v);   // "Carbon<br>RÁM" -> "Carbon"
        $v = NameParser::squash(strip_tags($v));
        switch ($field) {
            case 'size':
                $v = preg_replace('/^(veľkosť|velikost|velkost)\s+/iu', '', $v);
                $v = preg_replace('/\s+-\s+.*$/u', '', $v);        // "M/L - 175-185cm" -> "M/L"
                break;
            case 'model_year':
                $v = preg_match('/\b(20[1-3]\d)\b/', $v, $m) ? $m[1] : '';
                break;
            case 'image_url':
                $v = preg_replace('/^http:\/\//i', 'https://', $v);
                break;
        }
        return trim($v, " ,;");
    }

    /** textProperty* stĺpce "Názov;Hodnota;" -> [Názov => Hodnota] (prvý výskyt vyhráva) */
    public static function props(array $r): array
    {
        $out = [];
        foreach ($r as $col => $val) {
            if (!str_starts_with($col, 'textProperty') || $val === '' || !str_contains($val, ';')) {
                continue;
            }
            [$key, $rest] = explode(';', $val, 2);
            $key = trim(explode("\t", $key)[0]);
            $value = trim(preg_replace('/;\s*$/', '', $rest));
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function categoryPath(string $c): string
    {
        return implode(' > ', array_map('trim', explode('>', NameParser::squash($c))));
    }

    public static function number(string $v): ?float
    {
        $v = str_replace([' ', "\u{00A0}"], '', trim($v));
        if ($v === '') return null;
        if (str_contains($v, ',') && !str_contains($v, '.')) $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    }
}
