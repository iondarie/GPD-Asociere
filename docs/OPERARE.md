# Operare

## Importul zilnic

În **IMPORTURI**, fiecare sursă are propriul buton **Importă**. Poți încărca mai multe fișiere pentru aceeași sursă. **Preia comenzile din site** descarcă feedul configurat pe server. Importul verifică formatul, păstrează originalele în stocarea privată și încearcă automat asocierile eligibile. Verifică rezumatul și deschide cazurile marcate pentru control.

| Sursă | Cheie | Coloane și reguli principale |
| --- | --- | --- |
| OMC | `nr_doc` | `data_doc, nr_doc, partener, val_mon, rest_de_incasat, comisionar, eu_punct_lucru, obs_txt, magazie_sursa`. Un marcaj SITE valid în oricare din cele două coloane poate include factura. `magazie_sursa` este cerută de antet. |
| SITE | `ID comanda` | `ID comanda, Data, Data platii, Valoare comanda, AWB, Tip client, Nume client, Nume companie, Metoda de plata, ID Tranzactie`. `Status plata` și `Status livrare` sunt utilizate când apar și sunt obligatorii pentru feed. |
| FAN operațional | `AWB` | `AWB, Data AWB, Client Dest., Continut, Status Livrare, Ramburs, Data Virament, Tip Serviciu`. Din `COMANDA <ID>` se extrage ID-ul SITE. `Livrat` și `Retur` au semnificații distincte. |
| FAN financiar | AWB + dovada din fișier | Coloanele citite sunt `AWB, Suma incasata, Data virament`. Coloanele suplimentare din exportul financiar pot rămâne în fișier. |
| NETOPIA | `Id` și componentele plății | `Procesat` este suma brută, `Comision` și `TVA` formează comisionul afișat. `Data operatiei` este data folosită la căutare, `Data platii` este data viramentului, iar `#` este OP-ul raportat. Antetul complet este în `Sources::HEADERS`. |

Păstrează cifrele complete ale AWB-ului. Un CSV deja salvat cu AWB-uri prescurtate trebuie regenerat din sursă. Formatul standard este CSV sau XLSX. Limitele PHP de upload se aplică înainte de validarea aplicației.

SITE poate păstra comenzi în așteptare. O comandă anulată este eligibilă dacă plata NETOPIA este finalizată. Alte anulări sau plăți NETOPIA cu stări de eșec explicite sunt excluse din fluxul obișnuit. Lipsa comenzii dintr-un export parțial nu dovedește anularea.

## Viramente FAN și retururi

În **IMPORTURI → FAN → Verifică viramentele** apar dovezile financiare fără AWB operațional, diferențele de sumă, retururile cu dovadă financiară și conflictele de dată. Dacă suma diferă, importă documentul corectat. Pentru două date financiare eligibile, alege data susținută de raportul corect.

**RETURURI** permite separarea FAN și NETOPIA și consultarea cazurilor deschise sau închise. La returul cu plată online se verifică restituirea, un eventual retur parțial sau dovada de reexpediere. La returul ramburs cu încasare absentă nu se deduce o sumă de restituit. Înregistrează data rambursării doar pe baza dovezii.

## Verificare, raport și export

- Folosește **Detalii** sau **Verifică** pentru sursele facturii, istoricul și candidații. Compară numele, documentele, datele și sumele originale. La o diferență acceptată păstrează motivul.
- În **NETOPIA NEASOCIATE**, caută și în facturile istorice când plata corespunde unei facturi cu sold zero. Cazurile ambigue pot fi amânate. Un lot de verificare grupează deciziile manuale.
- În **ȘOFERI GPD**, gestionează șoferii și înregistrează livrarea din fișa documentului. Livrarea GPD și plata NETOPIA pot apărea împreună.
- **RAPORT** pornește cu facturile active. Folosește filtrul istoric pentru documentele acceptate cu sold închis. Excel exportă selecția pe toate paginile, în 11 coloane. Cu filtrul FAN sau NETOPIA, Excel păstrează doar rândurile cu virament confirmat. Numele include data locală și metoda.
- Coloanele Excel sunt DATA FACTURA, NR. FACTURA, CLIENT, VALOARE, REST DE INCASAT, AWB, SUMA VIRATA, DATA VIRAMENT, NR. OP., PLATA PRIN și COMISION. Textul are cinci coloane și grupează viramentele pe dată cu totaluri.

Administratorul gestionează conturile și backupurile. Operatorul importă și operează decizii. Contul de citire poate consulta interfața și exporta.
