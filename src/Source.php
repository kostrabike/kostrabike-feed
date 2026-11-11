<?php
declare(strict_types=1);

namespace Feed;

use OpenSpout\Reader\XLSX\Reader;

/** Stiahnutie a čítanie pôvodného Shoptet XLSX feedu. */
final class Source
{
    public static function download(string $url, string $dir, int $attempts = 3): string
    {
        if ($url === '') {
            throw new \RuntimeException('Chýba URL zdroja (env FEED_SOURCE_URL).');
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // .xlsx prípona je dôležitá – zdroj má v URL .xls, ale obsah je XLSX
        $path = $dir . '/source.xlsx';

        $lastError = '';
        for ($i = 1; $i <= $attempts; $i++) {
            $fh = fopen($path, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_USERAGENT      => 'kostrabike-bikeroom-feed/1.0',
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = $ok ? "HTTP $code" : curl_error($ch);
            fclose($fh);

            if ($ok && $code === 200 && file_get_contents($path, false, null, 0, 2) === 'PK') {
                return $path;
            }
            if ($ok && $code === 200) {
                $lastError = 'odpoveď nie je XLSX súbor';
            }
            if ($i < $attempts) {
                sleep(30);
            }
        }
        throw new \RuntimeException("Stiahnutie zdroja zlyhalo ($lastError).");
    }

    /**
     * Riadky prvého hárku ako asociatívne pole [hlavička => hodnota (string)].
     * @return \Generator<int, array<string, string>>
     */
    public static function rows(string $path): \Generator
    {
        $reader = new Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map([self::class, 'toString'], $row->toArray());
                    if ($header === null) {
                        $header = $cells;
                        continue;
                    }
                    $assoc = [];
                    foreach ($header as $i => $name) {
                        if ($name !== '') {
                            $assoc[$name] = $cells[$i] ?? '';
                        }
                    }
                    yield $assoc;
                }
                break; // iba prvý hárok
            }
        } finally {
            $reader->close();
        }
    }

    private static function toString(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return sprintf('%.0f', $v);
        }
        return trim((string) $v);
    }
}
