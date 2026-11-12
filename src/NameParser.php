<?php
declare(strict_types=1);

namespace Feed;

/**
 * Heuristické rozloženie názvu produktu na model / veľkosť / farbu / rok.
 *
 * Podporované tvary (po odrezaní značky):
 *   Giant/Liv/Cube:  "TCR Advanced 2-KOM M Stealth Chrome [M24]"   -> model, veľkosť, farba
 *                    "Explore-E+ 3 GTS-M21-S Black"                   -> veľkosť oddelená pomlčkami
 *   Mondraker:       "Arid Carbon S, superblack, 2026"               -> model, farba, rok (veľkosť je vo variante)
 *   CTM:             "AREON GX Xpert - čierna / matná šedá M (17)"   -> model - farba veľkosť
 */
final class NameParser
{
    /** @var array<string, true> normalizované veľkostné tokeny */
    private array $sizes = [];

    /** @param list<string> $sizeTokens */
    public function __construct(array $sizeTokens)
    {
        foreach ($sizeTokens as $t) {
            $this->sizes[self::norm($t)] = true;
        }
    }

    /**
     * @param list<string> $prefixes   prefixy značky na odrezanie
     * @param string       $knownSize  veľkosť zo štruktúrovaného zdroja (pomôže nájsť správne miesto v názve)
     * @return array{model:string, size:string, color:string, year:string}
     */
    public function parse(string $name, array $prefixes, string $knownSize = '', string $style = ''): array
    {
        if ($style === 'kellys') {
            return $this->parseKellys($name, $prefixes, $knownSize);
        }
        $s = self::squash($name);

        foreach ($prefixes as $p) {
            if (stripos($s, $p . ' ') === 0) {
                $s = substr($s, strlen($p) + 1);
                break;
            }
        }

        // rok: "2026" alebo Giant skratka "M24" (= 2024)
        $year = '';
        if (preg_match('/(?<![\w])(20[1-3]\d)(?![\w])/u', $s, $m)) {
            $year = $m[1];
        } elseif (preg_match('/(?<![A-Za-z0-9])M(2\d)(?![0-9A-Za-z])/', $s, $m)) {
            $year = '20' . $m[1];
        }
        $s = preg_replace('/(?<![\w])20[1-3]\d(?![\w])/u', '', $s);
        $s = preg_replace('/(?<![A-Za-z0-9])M2\d(?![0-9A-Za-z])/', '', $s);
        $s = preg_replace('/-{2,}/', '-', $s);
        $s = preg_replace('/(^|\s)-|-(\s|$)/', '$1$2', $s); // osamotené pomlčky po odstránení roku
        $s = self::squash($s);

        $model = $s;
        $size = '';
        $color = '';

        if (str_contains($s, ',')) {
            // Mondraker: "Model, farba[, rok]"
            $parts = array_values(array_filter(array_map('trim', explode(',', $s)), 'strlen'));
            $model = $parts[0] ?? '';
            $color = implode(', ', array_slice($parts, 1));
        } elseif (preg_match('/^(.*?)\s+-\s+(.*)$/u', $s, $m)) {
            // CTM: "Model - farba VEĽKOSŤ (17)"
            $model = $m[1];
            $rest = $m[2];
            if (preg_match('/^(.*?)\s+(\S+)(?:\s*\([^)]*\))?$/u', $rest, $mm)
                && (isset($this->sizes[self::norm($mm[2])]) || preg_match('/^\d{3}$/', $mm[2]))) {
                $size = $mm[2];
                $rest = $mm[1];
            }
            $color = $rest;
        } else {
            [$model, $size, $color] = $this->splitTokens($s, $knownSize);
        }

        return [
            'model' => self::cleanPart($model),
            'size'  => $size,
            'color' => self::cleanPart($color),
            'year'  => $year,
        ];
    }

    /**
     * Kellys: "KELLYS Tygon RS50 P Pine Forest L 29" 820Wh"  -> model "Tygon RS50 P", farba "Pine Forest", veľkosť "L"
     *         "KELLYS e-Soot 70 L Ivory White"                -> model "e-Soot 70",    farba "Ivory White"
     *         "KELLYS Theos F100 SH M 29"/27.5" 825Wh"        -> model "Theos F100 SH", farba "" (doplní sa z variant:Farba)
     * Model = slová po posledné "typové" slovo (obsahuje číslicu alebo je to krátka skratka veľkými písmenami: P, SH, COMP…),
     * farba = zvyšné slová pred veľkosťou + slová za veľkosťou. Rozmer kolies (29", 27.5") a batéria (820Wh) sa zahodia.
     * @return array{model:string, size:string, color:string, year:string}
     */
    private function parseKellys(string $name, array $prefixes, string $knownSize): array
    {
        $s = self::squash($name);
        foreach ($prefixes as $p) {
            if (stripos($s, $p . ' ') === 0) {
                $s = substr($s, strlen($p) + 1);
                break;
            }
        }
        $year = preg_match('/(?<![\w])(20[1-3]\d)(?![\w])/u', $s, $m) ? $m[1] : '';
        $s = preg_replace('/(?<![\w])20[1-3]\d(?![\w])/u', '', $s);
        $s = preg_replace('/(?<!\S)\d+(?:[.,]\d+)?"(?:\/\d+(?:[.,]\d+)?")*(?!\S)/u', '', $s);   // 29"  29"/27.5"
        $s = preg_replace('/(?<!\S)\d+\s?Wh(?!\S)/iu', '', $s);                               // 820Wh
        $t = preg_split('/\s+/u', self::squash($s), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($t);

        $known = self::norm($knownSize);
        $idx = null;
        for ($i = $n - 1; $i >= 1 && $known !== ''; $i--) {
            if (self::norm($t[$i]) === $known) { $idx = $i; break; }
        }
        for ($i = $n - 1; $i >= 1 && $idx === null; $i--) {
            if (isset($this->sizes[self::norm($t[$i])])) { $idx = $i; break; }
        }
        $before = $idx === null ? $t : array_slice($t, 0, $idx);
        $after  = $idx === null ? [] : array_slice($t, $idx + 1);

        $last = 0;   // prvé slovo je vždy model
        foreach ($before as $i => $w) {
            if (preg_match('/\d/', $w) || preg_match('/^[A-Z]{1,4}$/', $w)) {
                $last = $i;
            }
        }
        $model = implode(' ', array_slice($before, 0, $last + 1));
        $color = implode(' ', array_merge(array_slice($before, $last + 1), $after));

        return [
            'model' => self::cleanPart($model),
            'size'  => $idx === null ? '' : $t[$idx],
            'color' => self::cleanPart($color),
            'year'  => $year,
        ];
    }

    /** @return array{0:string,1:string,2:string} model, size, color */
    private function splitTokens(string $s, string $knownSize): array
    {
        $t = preg_split('/\s+/u', $s);
        $n = count($t);
        $known = self::norm($knownSize);

        // "One Size"
        for ($i = 1; $i < $n - 1; $i++) {
            if (strcasecmp($t[$i], 'one') === 0 && strcasecmp($t[$i + 1], 'size') === 0) {
                return [implode(' ', array_slice($t, 0, $i)), 'NONE', implode(' ', array_slice($t, $i + 2))];
            }
        }

        // 1) presne známa veľkosť, 2) prvý veľkostný token (nie na prvej pozícii – to je model)
        $idx = null;
        if ($known !== '') {
            for ($i = 1; $i < $n; $i++) {
                if (self::norm($t[$i]) === $known) { $idx = $i; break; }
            }
        }
        if ($idx === null) {
            for ($i = 1; $i < $n; $i++) {
                if (isset($this->sizes[self::norm($t[$i])])) { $idx = $i; break; }
            }
        }
        if ($idx !== null) {
            return [implode(' ', array_slice($t, 0, $idx)), $t[$idx], implode(' ', array_slice($t, $idx + 1))];
        }

        // 3) veľkosť schovaná v tokene s pomlčkami: "XR-L-rosewood", "GTS-S"
        for ($i = 0; $i < $n; $i++) {
            $seg = explode('-', $t[$i]);
            for ($j = 1; $j < count($seg); $j++) {
                if (isset($this->sizes[self::norm($seg[$j])])) {
                    $model = trim(implode(' ', array_slice($t, 0, $i)) . ' ' . implode('-', array_slice($seg, 0, $j)));
                    $color = trim(implode('-', array_slice($seg, $j + 1)) . ' ' . implode(' ', array_slice($t, $i + 1)));
                    return [$model, $seg[$j], $color];
                }
            }
        }

        return [$s, '', ''];
    }

    public static function norm(string $v): string
    {
        return strtoupper(str_replace(['/', ' '], '', trim($v)));
    }

    public static function squash(string $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    private static function cleanPart(string $v): string
    {
        return trim(self::squash($v), " ,-/");
    }
}
