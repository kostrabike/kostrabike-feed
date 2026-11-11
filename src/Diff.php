<?php
declare(strict_types=1);

namespace Feed;

/** Porovnanie predchádzajúceho a nového feedu podľa variant_id. */
final class Diff
{
    /** @var list<array<string,string>> */
    public array $added = [];
    /** @var list<array<string,string>> */
    public array $removed = [];
    /** @var list<array{row: array<string,string>, field: string, old: string, new: string}> */
    public array $changed = [];

    /**
     * @param array<string, array<string,string>> $old
     * @param array<string, array<string,string>> $new
     * @param list<string> $columns
     */
    public function __construct(array $old, array $new, array $columns)
    {
        foreach ($new as $id => $row) {
            if (!isset($old[$id])) {
                $this->added[] = $row;
                continue;
            }
            foreach ($columns as $c) {
                $o = (string) ($old[$id][$c] ?? '');
                $n = (string) ($row[$c] ?? '');
                if ($o !== $n) {
                    $this->changed[] = ['row' => $row, 'field' => $c, 'old' => $o, 'new' => $n];
                }
            }
        }
        foreach ($old as $id => $row) {
            if (!isset($new[$id])) {
                $this->removed[] = $row;
            }
        }
    }

    public function isEmpty(): bool
    {
        return !$this->added && !$this->removed && !$this->changed;
    }

    public function summary(): string
    {
        return sprintf('pridané %d, odstránené %d, zmenené polia %d', count($this->added), count($this->removed), count($this->changed));
    }

    public function toMarkdown(): string
    {
        $label = static fn (array $r) => trim(($r['brand'] ?? '') . ' ' . ($r['model'] ?? '') . ' ' . ($r['size'] ?? '') . ' ' . ($r['color'] ?? ''));
        $esc = static fn (string $s) => str_replace(['|', "\n"], ['\|', ' '], $s);
        $md = '';

        $md .= '## Pridané (' . count($this->added) . ")\n\n";
        if ($this->added) {
            $md .= "| variant_id | produkt | cena | ks |\n|---|---|---:|---:|\n";
            foreach ($this->added as $r) {
                $md .= "| {$esc($r['variant_id'])} | {$esc($label($r))} | {$r['price']} | {$r['quantity']} |\n";
            }
        }
        $md .= "\n## Odstránené (" . count($this->removed) . ")\n\n";
        if ($this->removed) {
            $md .= "| variant_id | produkt | posledná cena |\n|---|---|---:|\n";
            foreach ($this->removed as $r) {
                $md .= "| {$esc($r['variant_id'])} | {$esc($label($r))} | {$r['price']} |\n";
            }
        }
        $md .= "\n## Zmenené (" . count($this->changed) . ")\n\n";
        if ($this->changed) {
            $md .= "| variant_id | produkt | pole | pred | po |\n|---|---|---|---|---|\n";
            foreach ($this->changed as $c) {
                $md .= "| {$esc($c['row']['variant_id'])} | {$esc($label($c['row']))} | {$c['field']} | {$esc($c['old'])} | {$esc($c['new'])} |\n";
            }
        }
        return $md;
    }
}
