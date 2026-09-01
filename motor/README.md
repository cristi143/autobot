# Motorul

Rulează din cron, o dată pe oră. **Nu e accesibil prin web** — stă în
`/home/marcelpa/autobot-motor/`, în afara oricărui document root.

## Cron job-ul

cPanel → **Cron Jobs** → *Add New Cron Job*:

- **Minute:** `1`
- **Hour:** `*`  (sau, în interfața cPanel, „Once Per Hour (0 * * * *)" apoi
  schimbi minutul în 1)
- **Command:**

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/marcelpa/autobot-motor/motor.php >/dev/null 2>&1
```

Minutul 1, nu 0: lumânarea de 1h se închide fix la minutul 0, iar Binance are
nevoie de o clipă ca s-o publice ca încheiată.

Dacă `ea-php83` nu există, încearcă `ea-php81`. Verifici din File Manager dacă
există calea, sau lași cPanel să aleagă cu simplul `php`.

## Ce face, în ordine

1. Ia de la Binance ultima lumânare **închisă** și pe cea în formare.
   Deschiderea celei în formare e prețul de execuție: exact acolo s-ar executa
   un ordin la piață dat acum.
2. Salvează lumânarea închisă.
3. **TP** — dacă maximul orei (minimul, la short) a atins pragul, poziția se
   închide acolo. Un TP e un ordin limită la un preț cunoscut: dacă prețul l-a
   atins, s-ar fi executat, și fix la acel preț.
4. **SL** — dacă poziția a supraviețuit, se compară închiderea cu linia de
   intrare, evaluată la ora acelei lumânări.
5. **Semnale** — doar dacă nu există poziție deschisă.

### De ce TP-ul se verifică înaintea SL-ului

Nu e o preferință, e o consecință. SL-ul se judecă pe **închidere**, adică în
ultima clipă a orei. TP-ul se poate atinge **oricând** în timpul ei. Dacă
amândouă s-ar potrivi pentru aceeași oră, TP-ul a fost primul.

## Rularea de două ori nu strică nimic

Fiecare rulare reușită se scrie în `jurnal_cron` cu ora lumânării procesate.
Dacă cronul pornește din nou pentru aceeași lumânare, iese imediat.

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
