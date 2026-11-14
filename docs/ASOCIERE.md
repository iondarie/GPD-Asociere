# Reguli de asociere

## Dovezi și ordine de evaluare

1. Importul normalizează datele fără a schimba valorile documentelor originale. Banii folosiți la comparație sunt în bani întregi (1 RON = 100). Numele se compară fără prefixul „PERSOANA FIZICĂ”, majuscule, diacritice românești și ordinea cuvintelor. Identificatorii își păstrează cifrele și sufixele.
2. Motorul caută mai întâi legăturile comandă–factură. Compară persoana și compania, suma și data. Referința din `obs_txt` restrânge candidații. Un singur cuvânt cu 1–2 editări poate fi acceptat pentru identitatea SITE cu sumă exactă și încadrare în trei zile. O rută până la șapte zile există pentru FAN livrat cu AWB unic și cu dovezi concordante.
3. Pentru FAN și NETOPIA motorul adună candidații după nume normalizat și referințele disponibile. Ruta uzuală folosește ±3 zile și prioritate pentru suma exactă, apoi pentru aceeași zi în cadrul aceleiași clase de dovezi. Ruta ancorată explicit poate avea alt rang sau altă verificare de dată. Diferența FAN față de factură poate fi ±0,01 RON. Pentru NETOPIA, plata egală sau cu 0,01 RON mai mare poate fi eligibilă. NETOPIA se compară la brut, înainte de comision.
4. Perechea trebuie să fie unică în ambele sensuri la rangul eligibil. Respingerea anterioară, rezervările și legăturile deja salvate contează. Un AWB sau o plată nouă plauzibilă nu înlocuiește automat legătura existentă. Conflictul între rambursul pozitiv și NETOPIA cere verificare.

Pentru FAN cu ramburs pozitiv, metoda declarată este FAN. Pentru serviciul Standard cu ramburs zero sau lipsă, codul indică NETOPIA ca metodă declarată. Confirmarea plății reale cere dovada NETOPIA. Livrarea, metoda, plata și viramentul sunt câmpuri distincte. O dată de virament din raportul sursă poate confirma transferul.

## Reconciliere financiară FAN

Raportul financiar și cel operațional pot fi importate în orice ordine. Suma financiară trebuie să fie **exact egală** cu rambursul declarat pe același AWB. Toleranța de un ban față de valoarea facturii se aplică la asocierea facturii și este distinctă de această verificare.

Când suma se confirmă, data financiară are prioritate. O dată financiară contradictorie lasă în vigoare data deja confirmată până la alegerea operatorului. Decizia păstrează proveniența și datele deja revizuite. Reimportarea acelorași dovezi nu redeschide conflictul. O dovadă nouă contradictorie poate redeschide cazul. Retururile cu dovezi financiare rămân la verificare.

Motorul poate asocia un AWB FAN livrat chiar cu nume diferit când comanda SITE legată facturii este unică, AWB-ul și ID-ul comenzii coincid în surse, rambursul intră în toleranță și nu există conflicte. Pentru facturile cu sold zero, această rută cere o dată validă de virament. O factură istorică cu sold zero rămâne în istoric. Soldul negativ exclude asocierea automată.

## Intervenții și audit

Alege o asociere manuală atunci când dovezile obținute în afara aplicației confirmă perechea. Înregistrează diferența și motivul. Când o asociere este greșită, folosește corecția sau revocarea din istoric. Revocarea rezervă resursa până la reconsiderare, astfel încât următorul import să nu reproducă eroarea. Deciziile și versiunile sursei rămân auditate.

Plățile suplimentare, repartizările și restituirile au dovezi și decizii distincte. O repartizare confirmată poate explica sume diferite fără rescrierea valorii facturii sau a plății NETOPIA. Circuitul intern GPD pentru refacturare după încasare numerar se tratează separat de NETOPIA.
