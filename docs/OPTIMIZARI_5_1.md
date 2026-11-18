# Optimizări aplicate după audit

## Importuri

- Un fișier deja importat nu mai declanșează inutil motorul complet de asociere.
- Un import cu înregistrări neschimbate nu mai reface automat toate propunerile din baza de date.
- Asocierea automată rulează numai dacă importul a introdus, actualizat sau reconciliat o dovadă nouă.
- Deciziile existente, istoricul surselor și facturile confirmate nu sunt modificate de această optimizare.

## Raport

- Clientul este afișat fără prefixul `PERSOANA FIZICA`, exclusiv în interfață; datele originale rămân intacte.
- Numărul și data facturii sunt detalii sub client.
- FAN apare înaintea AWB-ului.
- Lipsa unui virament este afișată o singură dată ca `Neconfirmat`, nu ca două valori goale.

## Verificare continuă

- `tests/romanian-names.php` verifică normalizarea diacriticelor și a ordinii numelor.
- `tests/matching-guardrails.php` verifică reguli de protecție pentru nume apropiate și nume incomplete.
- `tests/one-step-import.cjs` verifică importurile dedicate, atomicitatea, duplicatele și reconcilierea FAN.

## Următorul prag tehnic

Pentru volume mult mai mari, următoarea etapă este asocierea incrementală pe documentele modificate și măsurarea interogărilor SQL în mediul de verificare. Aceasta trebuie făcută după ce există o copie de test a bazei, deoarece schimbă planul de execuție, nu regulile financiare.
