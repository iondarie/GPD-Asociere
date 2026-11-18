# Dezvoltare și verificare

## Repornire din repository

Folosește PHP 8.2+, extensiile din [ghidul Synology](SYNOLOGY.md) și o instanță MariaDB/MySQL de test. Pentru pornire locală, creează o bază goală cu nume de forma `pg_rebuild_<8 hex>`, importă `app/schema.sql` și configurează un cont SQL pentru acea bază. Alege un director privat de test în afara rădăcinii web.

Directorul privat trebuie să conțină un `config.php` local cu cheile următoare. Completează numai datele mediului de test.

```php
<?php
return [
    'environment' => 'rebuild-preview',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'pg_rebuild_00000000',
    'user' => 'plati_test',
    'password' => getenv('PLATI_TEST_DB_PASSWORD'),
];
```

Creează un utilizator de test cu `password_hash(..., PASSWORD_DEFAULT)` în `users`, completând `username`, `password_hash`, `display_name` și `role`. Serverul PHP local se pornește din rădăcina proiectului astfel, după configurarea variabilelor de mediu pentru test.

```powershell
$env:PLATI_DATA_DIR = (Resolve-Path '<directorul-privat-de-test>').Path
php -S 127.0.0.1:18778 -t app app/router.php
```

## Verificări automate

- Sintaxă PHP cu `php -l` pentru toate fișierele PHP. Sintaxă JS cu `node --check app/public/assets/app.js`.
- `php tests/romanian-names.php` verifică diacriticele, formele vechi cu sedilă, prefixul și ordinea numelui.
- `tests/one-step-import.cjs` cere Playwright și o bază **goală** de test, cu contul administrator sintetic `import_test` și parola fixă din test. Rulează `npm install --no-save --no-package-lock playwright`, `npx playwright install chromium` și `node tests/one-step-import.cjs`. Testul verifică cele patru butoane de import, validarea atomică, fișierele duplicate și fluxul FAN.

## Matrice de regresie

| Caz | Rezultat așteptat |
| --- | --- |
| Același client și aceeași sumă pe două facturi | Perechi din aceeași zi dacă sunt reciproc unice, altfel verificare |
| Nume cu/fără diacritice și prefix | Aceeași cheie de comparație |
| Nume incomplet și aceeași sumă NETOPIA | Candidat de verificat cu sursa reală |
| AWB pierdut prin formatare științifică | Eroare de import, se cere AWB complet |
| Financiar FAN înainte de operațional | Dovadă în așteptare, reconciliată după AWB la importul operațional |
| Ramburs 123,45 RON și încasare 123,44 RON | Diferență la verificare, celelalte AWB-uri pot fi prelucrate |
| Două date financiare pentru același AWB | Păstrează data confirmată și înregistrează alegerea operatorului |
| AWB livrat, comandă și factură unice, nume FAN diferit | Asociere pe baza dovezilor concordante când respectă regula FAN |
| Factură cu sold zero și FAN fără dată de virament | Nicio asociere istorică automată pe ruta financiară FAN |
| Import nou cu o alternativă pentru o legătură confirmată | Legătura confirmată se păstrează |
| Raport Excel filtrat FAN sau NETOPIA | O singură foaie și numai rânduri cu virament confirmat |
| Restaurare | Compară documentele, deciziile, utilizatorii, arhivele SHA-256 și integritatea referințelor |

Pentru performanță măsoară timpul complet al importului și al raportului cu aceeași bază de test. Verifică în special extinderea candidaților după sumă, reimporturile suprapuse și disputele de dată. Confirmă că arhivele sunt accesibile utilizatorului PHP și că un import respins nu salvează un lot parțial.

## Ce se transferă între medii

Codul reutilizabil se actualizează prin Git. Datele, istoricul deciziilor și fișierele importate se transferă prin backup. Scripturile de corecție pentru cazuri istorice și testele cu fișiere private aparțin workspace-ului de operare. Pentru o regresie nouă reprodu cazurile cu facturi, clienți și tranzacții fictive.
