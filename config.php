<?php
/**
 * Konfigurácia generátora Bikeroom feedu.
 * Všetko, čo sa môže v budúcnosti meniť (filtre, mapovania, zdroje polí), je tu.
 */
return [
    // URL pôvodného Shoptet feedu. V GitHub Actions sa berie zo secretu FEED_SOURCE_URL.
    'source_url' => getenv('FEED_SOURCE_URL') ?: '',

    'paths' => [
        'tmp'     => __DIR__ . '/tmp',      // stiahnutý zdroj, notifikácia (negituje sa)
        'public'  => __DIR__ . '/docs',     // publikované na GitHub Pages (feed.csv, report)
        'changes' => __DIR__ . '/changes',  // história zmien (markdown, 1 súbor na beh so zmenami)
        'data'    => __DIR__ . '/data',     // manual_years.csv (ručne) + model_years.csv (cache dohľadania)
    ],

    'timezone' => 'Europe/Bratislava',

    // ------------------------------------------------------------------ FILTRE
    'filters' => [
        // manufacturer v zdroji => brand vo výstupe. Iné značky sa ignorujú.
        'brands' => [
            'Giant'        => 'Giant',
            'Liv by Giant' => 'Liv',
            'CTM'          => 'CTM',
            'Mondraker'    => 'Mondraker',
            'Cube'         => 'Cube',
        ],
        // povolené hodnoty stĺpca productVisibility
        'visibility' => ['visible'],
        // stĺpec price (s DPH)
        'min_price'           => 1500,
        'min_price_inclusive' => false,   // false = price > 1500, true = price >= 1500
        // minimálny sklad (stĺpec stock). null = nefiltrovať.
        // Pozor: Bikeroom riadky s quantity <= 0 odmietne.
        'min_stock' => 1,
    ],

    // ------------------------------------------------------------------ KATEGÓRIE
    // Koreňové kategórie (prvá časť categoryText), ktoré považujeme za bicykle.
    'bike_roots' => [
        'Bicykle',
        'Elektrobicykle - Elektrický bicykel',
    ],
    // Prefix categoryText => Bikeroom category. null = vylúčiť (zobrazí sa v reporte ako INFO).
    // Vyhodnocuje sa zhora nadol, prvá zhoda vyhráva. Bicykel bez zhody = chyba v reporte.
    'categories' => [
        'Elektrobicykle - Elektrický bicykel > Detské' => null,
        'Elektrobicykle - Elektrický bicykel'          => 'EBIKE',
        'Bicykle > Detské bicykle'                     => null,
        'Bicykle > Krosové bicykle'                    => null,
        'Bicykle > Horské bicykle'                     => 'MTB',
        'Bicykle > Dirt bicykle'                       => 'MTB',
        'Bicykle > Cestné bicykle'                     => 'ROAD',
        'Bicykle > Gravel bicykle'                     => 'GRAVEL',
    ],

    // ------------------------------------------------------------------ MAPOVANIE
    // Prefixy, ktoré sa odrežú zo začiatku názvu (name) pri odvodení modelu. Bez ohľadu na veľkosť písmen.
    'name_prefixes' => [
        'Giant'        => ['Giant'],
        'Liv by Giant' => ['Liv by Giant', 'Liv Giant', 'Liv', 'Giant'],
        'CTM'          => ['CTM'],
        'Mondraker'    => ['Mondraker'],
        'Cube'         => ['Cube'],
    ],

    // Tokeny, ktoré v názve považujeme za veľkosť rámu.
    'size_tokens' => ['XXS', 'XS', 'S', 'S/M', 'SM', 'M', 'M/L', 'ML', 'L', 'L/XL', 'XL', 'XXL'],

    // Odkiaľ brať hodnoty, v poradí priority (prvá neprázdna vyhráva).
    //   'stĺpec'      = stĺpec zo zdroja (napr. 'variant:Farba')
    //   'prop:Názov'  = hodnota z textProperty* ("Názov;Hodnota;")
    //   'name'        = odvodené z názvu produktu
    'sources' => [
        'size'       => ['variant:Veľkosť rámu', 'variant:Velikost', 'variant:Varianta', 'filteringProperty:Veľkosť', 'prop:Veľkosť rámu', 'prop:Veľkosť', 'name'],
        'color'      => ['variant:Farba', 'variant:Barva', 'name', 'prop:Farba', 'prop:Barva'],
        'model_year' => ['prop:Modelový rok', 'prop:Rok', 'name'],
        'model'      => ['name', 'prop:Model'],
        'image_url'  => ['defaultImage', 'image'],
    ],
    // Výnimky zdrojov pre konkrétnu značku (kľúč = manufacturer v zdroji).
    'brand_overrides' => [
        'CTM' => [
            'size' => ['prop:Veľkosť rámu', 'name', 'filteringProperty:Veľkosť'],
        ],
    ],

    // Dohľadanie modelového roku na webe výrobcu podľa model + farba (src/YearLookup.php).
    // Ručné roky sú v data/manual_years.csv a majú vždy prednosť.
    'year_lookup' => [
        'enabled'             => true,
        'delay_ms'            => 1500,   // pauza medzi požiadavkami na web výrobcu
        'retry_days'          => 30,     // nenájdené kombinácie skúsiť znova po X dňoch
        'max_lookups_per_run' => 400,    // limit kombinácií na jeden beh
        'user_agent'          => 'kostrabike-bikeroom-feed/1.0 (model year lookup)',
        // kľúč = brand vo výstupe
        'sitemaps' => [
            'Giant' => [
                'https://static.giant-bicycles.com/sitemaps/sitemap-giant-sk-sk.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-giant-cs-cz.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-giant-de-de.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-giant-nl-nl.xml',
            ],
            'Liv' => [
                'https://static.giant-bicycles.com/sitemaps/sitemap-liv-sk-sk.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-liv-cs-cz.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-liv-de-de.xml',
                'https://static.giant-bicycles.com/sitemaps/sitemap-liv-nl-nl.xml',
            ],
        ],
        // názov riadku so zoznamom farieb v špecifikácii (podľa jazyka stránky)
        'color_labels' => ['Farby', 'Barvy', 'Farben', 'Kleuren', 'Colors', 'Colours', 'Farba', 'Barva', 'Farbe', 'Kleur', 'Color', 'Colour'],
    ],

    // Použitý tovar: ak názov zodpovedá vzoru, condition = PREOWNED.
    // condition_grade je pre PREOWNED povinné – ak je grade null, riadok sa vylúči a zobrazí v reporte.
    'preowned' => [
        'pattern' => '/použ|požičov|bazár|bazar|\bdemo\b|testovac/iu',
        'grade'   => null,   // EXCELLENT | VERY_GOOD | WELL_LOVED | null
    ],

    // ------------------------------------------------------------------ VÝSTUP
    'columns' => [
        'product_id', 'variant_id', 'brand', 'model', 'model_year', 'product_type', 'category',
        'condition', 'condition_grade', 'size', 'color', 'quantity', 'price', 'image_url',
    ],

    // Riadky s varovaním (chýba rok/veľkosť/farba) Bikeroom importuje, ale nechá offline.
    // true = takéto riadky do feedu nedávať (zostanú v reporte a v missing_years.csv na doplnenie).
    'exclude_rows_with_warnings' => true,

    // Poistka: ak by nový feed mal výrazne menej riadkov ako predchádzajúci (napr. chyba zdroja),
    // nič sa nepublikuje a beh skončí chybou. Bikeroom by inak chýbajúce varianty stiahol z predaja.
    'safety' => [
        'min_rows'       => 1,
        'max_drop_ratio' => 0.5,   // 0.5 = max. pokles o 50 % oproti predchádzajúcemu behu
    ],
];
