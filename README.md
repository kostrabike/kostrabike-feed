# KostraBike → Bikeroom feed

Každých 12 hodín stiahne Shoptet export KostraBike, vyfiltruje bicykle a e-bicykle,
prevedie ich do formátu Bikeroom Product Feed (guide v1.0, nie je v repozitári) a publikuje
ako CSV na GitHub Pages. Zmeny oproti predchádzajúcemu behu ukladá do histórie a posiela ako notifikáciu.

## Ako to funguje

```
Shoptet XLSX ──► build.php ──► docs/feed.csv     (feed pre Bikeroom, iba validné riadky)
                           ├─► docs/report.csv   (problémové riadky)
                           ├─► docs/index.html   (prehľad + report)
                           └─► changes/*.md      (história zmien) + GitHub Issue (notifikácia)
```

- **Plánovač:** GitHub Actions (`.github/workflows/feed.yml`), 2× denne + ručné spustenie.
- **Hosting:** GitHub Pages – `https://<user>.github.io/<repo>/feed.csv`.
- **História:** každý beh commitne `docs/` a `changes/` → celá história je v gite.
- **Notifikácia:** pri zmene sa založí GitHub Issue → GitHub pošle e-mail.
- **Poistka:** ak nový feed klesne o viac ako 50 % riadkov, nič sa nepublikuje a beh skončí chybou
  (GitHub pošle e-mail o zlyhaní). Bikeroom by inak chýbajúce varianty stiahol z predaja.

## Konfigurácia

Všetko sa nastavuje v [`config.php`](config.php): značky, viditeľnosť, cenový limit, sklad,
mapovanie kategórií, poradie zdrojov pre veľkosť/farbu/rok, výnimky podľa značky, stĺpce výstupu.

### Modelový rok

Rok sa berie v tomto poradí (prvý nájdený vyhráva):

1. **`data/manual_years.csv`** – ručne zadané roky, majú vždy prednosť.
2. **Shoptet** – vlastnosť „Modelový rok“/„Rok“, rok v názve, Giant skratka „M24“.
3. **Web výrobcu (Giant, Liv)** – model + farba sa porovnajú s oficiálnymi produktovými stránkami
   (rok z titulku stránky, farby zo špecifikácie). Výsledky sa ukladajú do `data/model_years.csv`
   aj so zdrojovou URL; hľadajú sa iba nové kombinácie, nenájdené sa skúšajú znova po 30 dňoch.

Ak sa rovnaká farba predávala vo viacerých ročníkoch, rok sa neodhaduje – ostane na ručné doplnenie.

**Ako doplniť rok ručne:**
1. Otvor report (GitHub Pages) → sekcia *Chýbajúci rok* alebo súbor `missing_years.csv`.
2. Na GitHube otvor `data/manual_years.csv` → ikona ceruzky (Edit).
3. Vlož riadky z `missing_years.csv`, doplň stĺpec `year` (4 číslice) a ulož (*Commit changes*).
   Stĺpec `note` je len pre teba. Rok platí pre všetky veľkosti danej kombinácie značka + model + farba.
4. Prejaví sa pri najbližšom behu (alebo spusti *Actions → Bikeroom feed → Run workflow*).

Ručný záznam prepíše aj rok zo Shoptetu či z webu – takto sa dá opraviť aj nesprávne dohľadaný rok.

### Report

| Úroveň    | Význam |
|-----------|--------|
| `ERROR`   | Riadok nie je vo feede (chýba obrázok, model, cena, rok, veľkosť, farba, nenamapovaná kategória…). |
| `WARNING` | Iba ak `exclude_rows_with_warnings = false`: riadok je vo feede, ale chýba rok / veľkosť / farba → Bikeroom ho nechá offline. |
| `INFO`    | Riadok vyradený kvôli kategórii (napr. detské, krosové) – na kontrolu. |

## Nastavenie GitHubu (raz)

1. Vytvor **verejný** repozitár (GitHub Pages je zadarmo len pre verejné) a pushni tento projekt.
2. **Settings → Secrets and variables → Actions → New repository secret**
   `FEED_SOURCE_URL` = URL Shoptet exportu (s hashom).
3. **Settings → Pages → Build and deployment → Source: GitHub Actions**.
4. **Settings → Actions → General → Workflow permissions: Read and write permissions**.
5. **Actions → Bikeroom feed → Run workflow** (prvý beh).
6. Notifikácie: v repozitári **Watch → All Activity** (alebo Custom → Issues), aby chodili e-maily.

## Lokálne spustenie

```bash
composer install
FEED_SOURCE_URL='https://…' php build.php
# alebo s lokálnym súborom:
php build.php --source=tmp/test-source.xlsx
```

Potrebné: PHP 8.2+ s rozšíreniami `curl`, `zip`, `xmlreader`.
