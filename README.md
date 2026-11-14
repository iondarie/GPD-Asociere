# PLĂȚI GOLDENDRINKS.ro

Aplicație internă de reconciliere a facturilor OMC, comenzilor SITE, expedierilor FAN și plăților NETOPIA. Rulează cu PHP și MariaDB/MySQL pe Synology Web Station. Interfața folosește HTML, CSS și JavaScript, fără un build frontend obligatoriu.

Raportul reunește factura, livrarea, metoda de plată și viramentul. Operatorii verifică și corectează asocierile, urmăresc plățile NETOPIA neasociate și retururile, înregistrează livrările GPD și exportă selecția în Excel sau text.

## Documentație

| Ghid | Conținut |
| --- | --- |
| [Operare](docs/OPERARE.md) | Importurile, controalele zilnice, excepțiile și exportul |
| [Regulile de asociere](docs/ASOCIERE.md) | Candidați, toleranțe, sold zero, conflicte și istoric |
| [Arhitectură și model de date](docs/ARHITECTURA.md) | Componente, tabele, API și tipuri de dovezi |
| [Instalare și recuperare](docs/SYNOLOGY.md) | Web Station, date private, backup, restaurare, revenire |
| [Dezvoltare și verificare](docs/DEZVOLTARE.md) | Mediu de test, comenzi, scenarii și verificări de regresie |

`app/` conține aplicația. `tests/` conține verificări reutilizabile cu date sintetice. Schema este disponibilă în `app/schema.sql` și în forma încărcată de instalator din `app/src/schema.php`.

Această versiune pornește de la sursele reconstrucției 4.0 din workspace și include reconcilierea financiară FAN. Datele operaționale și deciziile salvate se recuperează din backupul autorizat al aplicației. Repository-ul este pentru cod și documentație.
