# autobot.dunitru.ro — context de lucru

Platformă de tranzacționare automată pe Binance, ZECUSDC pe 1h.

## Stare la 2 septembrie 2026: SISTEMUL RULEAZĂ

Tot lanțul e viu, în simulare cu bani fictivi:

| Piesă | Unde | Ce face |
|---|---|---|
| Grafic live | `public/grafic.js` | lumânări 1h, istoric + punte REST + WebSocket |
| Desen | `public/desen.js` | utilizatorul trage triunghiuri, se salvează |
| API | `public/api/` | `stare.php`, `triunghiuri.php`, `_comun.php` |
| Panou | `public/panou.js` | poziție, triunghi, bănci, istoric |
| **Motor** | `motor/motor.php` | **cron orar, ia deciziile** |
| Bază de date | MySQL `marcelpa_autobot` | 8 tabele, vezi `baza-de-date/schema.sql` |

- **Cron activ:** la fiecare 5 minute (`*/5 * * * *`),
  `/opt/cpanel/ea-php83/root/usr/bin/php /home/marcelpa/autobot-motor/motor.php`
- **Configurarea** (parole, cheie API): `/home/marcelpa/autobot-config.php`,
  în afara zonei publice, niciodată în git.
- **Băncile pornesc** cu 500 USDC (long) și echivalentul în ZEC (short).

**Etapele 0, 1 și 2 sunt gata.** Rămân: parola pe site (3), analiza liniilor
pentru un model matematic (4), bani reali (5) — și o pagină de statistici,
cerută separat.

## La începutul fiecărei sesiuni
Citește **`docs/plan-tranzactionare.md`** — regulile de tranzacționare exacte,
sursa de adevăr. Apoi **`DEPLOY.md`** (hosting, deploy, cum e construit
graficul) și **`motor/README.md`** (cum funcționează motorul și cronul).

## Reguli de lucru
- Tot ce ajunge pe web stă în **`public/`**. Deploy-ul nu copiază nimic altceva.
- Se lucrează pe `main`: commit + push. Înainte de push, **arată-i utilizatorului
  ce s-a modificat** ca să confirme.
- Deploy-ul îl face utilizatorul manual din cPanel (Update from Remote →
  Deploy HEAD Commit).
- Mesajele de commit au diacritice → fișier cu `-F`, nu `-m`.
- Se comite cu identitate explicită dacă repo-ul n-o are:
  `git -c user.name="Cristi Iorga" -c user.email="cristi.s.iorga@gmail.com"`
- În commit nu se pune identificatorul de model.

## Reguli de tranzacționare, pe scurt
Detaliile și motivele sunt în `docs/plan-tranzactionare.md` — **nu le reinventa**.

- **Triunghi** = două linii convergente. Trage **o singură dată**, apoi ambele
  linii trec în istoric. Fără triunghi activ, motorul nu face nimic.
- **LONG**: lumânare verde închide peste linia de sus. **SHORT**: roșie sub cea de jos.
- **TP**: +1%, urmărit în timp real (pe maximul/minimul orei — un TP e un ordin
  limită la un preț cunoscut). **SL**: lumânarea închide înapoi peste linia de intrare.
- **Două ritmuri:** TP-ul se verifică la FIECARE rulare a cronului, inclusiv pe
  lumânarea în formare (e un ordin limită — dacă maximul l-a atins, s-a executat).
  SL-ul și semnalele se judecă o singură dată per lumânare închisă.
- **TP se verifică înaintea SL**, și nu din preferință: SL-ul se judecă pe
  închidere, TP-ul oricând în timpul orei, deci TP-ul e primul prin construcție.
- **Triunghiul expiră la vârf** dacă n-a fost spart: după intersecție, „sus"
  ajunge sub „jos" și orice lumânare verde ar da un long fals.
- Comision 0,075% pe parte. O poziție odată; cât e deschisă, nu se caută semnale.

## Capcane deja plătite
- **Timpul e BIGINT în milisecunde UTC peste tot.** Serverul are fusul
  Europe/Bucharest. Orice `DATETIME` sau formatare fără UTC explicit aliniază
  lumânările greșit cu 2–3 ore, tăcut.
- **`serialize_precision` e mare pe server** — fără `ini_set('serialize_precision','-1')`
  din `_comun.php`, json_encode scrie 0.075 ca 0.07499999999999999722…
- **Atributul `hidden` nu ascunde** elementele stilate cu `display:flex`; de aceea
  există `[hidden] { display: none !important; }` în style.css.
- Verificarea matematicii: `php motor/probe/matematica.php` — 26 de probe, fără
  bază de date. Rulează-le după orice atingere a formulelor.

## Graficul
- Pagina principală = grafic de lumânări 1h **live**, cu `lightweight-charts` de la
  TradingView, luat de pe jsDelivr (versiune fixată: 4.2.3).
- **Trei straturi de date:** JSON commitat (istoric) + Binance REST (puntea până în
  prezent) + WebSocket (lumânarea curentă). Detalii în DEPLOY.md §5.
- **Browserul vorbește direct cu Binance** — datele publice de piață permit CORS și
  nu cer cheie API. Site-ul rămâne static; nu e nevoie de nimic pe server. Nu
  propune un backend pentru asta.
- **Cheile API rămân interzise aici oricum.** Datele publice n-au nevoie de ele; iar
  orice endpoint care cere semnătură (cont, ordine) NU are ce căuta într-o pagină
  din browser — cheia ar fi vizibilă oricui.
- JSON-ul se regenerează doar când apar date noi de 1 minut:
  `python3 tools/agrega_1h.py ZECUSDC`, commit, deploy. Graficul rămâne la zi singur.
- Simbolul afișat se schimbă din `var SIMBOL` în `public/grafic.js`.
- Fișierele 1m rămân în afara git-ului; doar JSON-ul agregat intră.

## Mediul serverului (verificat 2 sept. 2026)
PHP 8.1.34 litespeed · `curl`, `openssl`, `json`, `pdo_mysql`, `hash` active ·
serverul ajunge la Binance · IP de ieșire `86.107.43.56`.

- **Fusul serverului e Europe/Bucharest, Binance e în UTC.** Orice formatare sau
  comparare de timpi forțează explicit UTC (`gmdate`, `DateTimeZone('UTC')`).
  Altfel lumânările se aliniază greșit cu 2–3 ore. Cea mai probabilă sursă de bug
  tăcut din proiect.
- Detalii și celelalte consecințe: `docs/plan-tranzactionare.md`.

## Reguli specifice botului
- **Cheile API Binance nu ajung NICIODATĂ în git și nici pe ClausWeb.** Stau în
  `.env` pe mașina care rulează motorul. `.gitignore` le blochează — nu-l slăbi.
- **Datele istorice nu intră în git** (`../historical_data/`, ~7.5 GB).
- Când se scrie cod care trimite ordine reale, se cere confirmare explicită și se
  implementează întâi pe **testnet Binance** / mod paper-trading.
- Nu se propune rularea motorului pe shared hosting — nu funcționează, vezi DEPLOY.md §3.

## Context vecin
Același cont cPanel (`marcelpa`) găzduiește și `marcel-parcel.ro` și `dunitru.ro`.
Sunt proiecte complet separate, cu repo-uri separate — nu se modifică nimic acolo
din sesiunile de autobot. **Atenție:** deploy-ul lui dunitru.ro golește
`/home/marcelpa/dunitru.ro/public_html`, de aceea autobot are arbore separat.
