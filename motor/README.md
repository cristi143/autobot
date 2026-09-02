# Motorul

Rulează din cron, o dată pe oră. **Nu e accesibil prin web** — stă în
`/home/marcelpa/autobot-motor/`, în afara oricărui document root.

## Cron job-ul

cPanel → **Cron Jobs** → *Add New Cron Job* → **Once Per Five Minutes**:

```
*/5 * * * *
```

Comanda:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/marcelpa/autobot-motor/motor.php >/dev/null 2>&1
```

**De ce la 5 minute și nu la o oră.** Motorul are două ritmuri. TP-ul se
verifică la fiecare rulare, inclusiv pe lumânarea în formare: e un ordin limită
la un preț cunoscut, deci dacă maximul l-a atins, s-ar fi executat deja — n-are
rost să așteptăm închiderea orei ca s-o recunoaștem. Cu cronul la 5 minute,
poziția se închide în cel mult atâta de la atingerea pragului.

SL-ul și semnalele noi se judecă tot pe închiderea lumânării de 1h, o singură
dată per lumânare. Rulările dintre ore nu le ating.

Prima rulare de după minutul 0 prinde lumânarea proaspăt închisă. Nu contează
că e la :00 sau :05 — Binance are oricum nevoie de o clipă ca s-o publice.

Dacă `ea-php83` nu există, încearcă `ea-php81`. Verifici din File Manager dacă
există calea, sau lași cPanel să aleagă cu simplul `php`.

## Ce face, în ordine

1. Ia de la Binance ultima lumânare **închisă** și pe cea **în formare**.
   Deschiderea celei în formare e prețul de execuție; maximul ei spune dacă
   TP-ul a fost deja atins în ora curentă.
2. Salvează lumânarea închisă.
3. **TP — la fiecare rulare.** Dacă maximul (minimul, la short) a atins pragul,
   poziția se închide acolo, exact la prețul TP-ului. Lumânarea închisă intră în
   socoteală doar dacă n-a fost încă judecată, ca să nu reevaluăm o oră veche.
4. **SL — o singură dată per lumânare închisă.** Se compară închiderea cu linia
   de intrare, evaluată la ora acelei lumânări.
5. **Semnale** — tot o singură dată per lumânare, și doar dacă nu e poziție
   deschisă.

### De ce TP-ul se verifică înaintea SL-ului

Nu e o preferință, e o consecință. SL-ul se judecă pe **închidere**, adică în
ultima clipă a orei. TP-ul se poate atinge **oricând** în timpul ei. Dacă
amândouă s-ar potrivi pentru aceeași oră, TP-ul a fost primul.

## Rularea deasă nu strică nimic

Rulările care au făcut și munca „pe închidere" se scriu în `jurnal_cron` cu ora
lumânării. Cele dintre ore se scriu cu `ora_lumanare` gol — ele doar au verificat
TP-ul. Așa, o lumânare nu poate fi judecată de două ori pentru SL sau semnale,
oricât de des ar porni cronul.

## Cum vezi ce a făcut

Tabelul `jurnal_cron` păstrează fiecare rulare, cu tot ce a povestit pe drum.
Fără el, un cron mort ar arăta identic cu unul care n-a avut ce face.

Rulat manual, din linia de comandă, scrie totul și pe ecran.

## Probe

`probe/matematica.php` verifică, fără bază de date, partea care nu are voie să
greșească: prelungirea liniilor, randamentul net cu comisioane, condițiile de
semnal și precedența TP–SL. Se rulează cu `php probe/matematica.php`.

## Presupuneri

- **Triunghiurile nu se consumă cât timp există o poziție deschisă.** Motorul
  nici nu caută semnale atunci. Un triunghi care s-ar rupe în acel timp rămâne
  armat pentru mai târziu.
- **Banca de short se inițializează la prima rulare**, convertind cei 500 USDC
  în ZEC la prețul de atunci, fără comision — e finanțare, nu tranzacție.
