<?php
declare(strict_types=1);

namespace Feed;

/** CSV podľa Bikeroom: UTF-8 bez BOM, čiarka, úvodzovky ("" escape), \n. */
final class Csv
{
    /** @param list<string> $columns @param iterable<array<string,string>> $rows */
    public static function write(string $path, array $columns, iterable $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $tmp = $path . '.tmp';
        $fh = fopen($tmp, 'wb');
        fputcsv($fh, $columns, ',', '"', '', "\n");
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($r[$c] ?? ''), $columns), ',', '"', '', "\n");
        }
        fclose($fh);
        rename($tmp, $path);
    }

    /** @return array<string, array<string,string>> riadky indexované podľa $key */
    public static function readIndexed(string $path, string $key): array
    {
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        $header = fgetcsv($fh, null, ',', '"', '');
        $out = [];
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) continue;
            $assoc = array_combine($header, array_pad($row, count($header), ''));
            $out[$assoc[$key]] = $assoc;
        }
        fclose($fh);
        return $out;
    }
}
