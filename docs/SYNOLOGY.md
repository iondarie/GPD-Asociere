# Instalare și recuperare pe Synology

## Instalare în Web Station

1. Creează un director web separat pentru test. Copiază **conținutul folderului `app/`** la rădăcina lui. Alege un profil Apache cu rescriere activă și PHP 8.2 sau mai nou. Activează `pdo_mysql`, `mbstring`, `zip`, `dom` și `zlib`. Instalatorul verifică MariaDB ≥10.3 sau MySQL ≥8.0.16.
2. Creează un director de date în afara `web` și `web_packages`, de exemplu `/volume1/Stuff/Plati_Date`. Creează și `uploads`, `backups` și `restore`. PHP trebuie să poată traversa directorul părinte și să scrie în aceste directoare. Include calea de date și backup în `open_basedir` când profilul folosește această setare.
3. În rădăcina web creează un fișier local `storage.php` cu `<?php return '/volume1/Stuff/Plati_Date';`. Pentru procesul local poate fi folosită variabila `PLATI_DATA_DIR`. Directorul configurat trebuie să existe.
4. Generează local un cod aleatoriu de instalare de minimum 32 de caractere și salvează doar SHA-256 hex al codului în `setup-token.sha256` din directorul privat. Un exemplu PHP CLI este mai jos. Codul original se introduce în formularul web.
5. Deschide `/setup.php`. Completează datele administratorului SQL în formular. Instalatorul creează o bază nouă `pg_plati_<8 hex>`, un cont SQL dedicat și primul administrator al aplicației. Alege restaurare sau instalare goală. Producția cere HTTPS. După instalare, configurarea este salvată în directorul privat și instalatorul se blochează.
6. Pentru preluarea SITE, salvează URL-ul feedului autorizat în `site-feed-url.txt`, tot în directorul privat. Verifică încărcarea paginii, autentificarea, exportul și faptul că o cerere anonimă către API răspunde cu 401. Căile către `src/`, `storage.php` și fișierele private trebuie să răspundă cu 403/404.

Din directorul privat, dacă ai un PHP CLI disponibil local, generează codul astfel. Copiază în NAS numai fișierul hash, iar codul imprimat folosește-l în formular.

```sh
php -r '$token=bin2hex(random_bytes(32)); file_put_contents("setup-token.sha256", hash("sha256", $token)); echo $token, PHP_EOL;'
```

Pentru fișiere mari, verifică `upload_max_filesize`, `post_max_size`, `max_file_uploads`, memoria și timpul maxim de execuție. `post_max_size` trebuie să acopere întregul lot multipart. Limita de fișier a cititorului este 40 MiB, cu maximum 20.000 rânduri pe CSV sau foaie XLSX. Măsoară timpii pe fișiere reprezentative în staging înainte de modificarea profilului live.

## Backup și revenire

- Din **Setări → Backup local**, creează o copie verificabilă. Păstrează setul complet `.json.gz`, `.meta.json` și directorul `.files`. Acesta conține date, conturi cu parole hash, decizii, audit și fișierele originale. Protejează accesul și verifică disponibilitatea unei copii independente de folderul web.
- Pentru restaurare, așază setul complet în `restore/` dintr-un **director privat separat** și lansează instalatorul într-o instalare nouă. Instalatorul acceptă și formatul de migrare vechi `.jsonl.gz` cu manifest. Verifică numărul înregistrărilor, legăturile, retururile, conturile și rapoartele înainte de mutarea portalului.
- Înainte de o actualizare, salvează codul live și un backup curent din aplicație. În cazul unei regresii de cod fără modificări incompatibile de schemă, restaurează copia codului. Dacă a fost necesară schimbarea bazei, păstrează la dispoziție instalarea anterioară și verifică operațiunile înregistrate după backup înainte de comutare.
- Documentația, credențialele, backupurile și arhivele de diagnostic aparțin spațiului privat. Pentru o actualizare de cod, publică fișierele din `app/` fără a înlocui configurația locală a portalului.
