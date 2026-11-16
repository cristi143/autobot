# autobot.dunitru.ro — context de lucru

**Bot automat pe bani fictivi**, coș BTC + ETH + ZEC (perechi USDC), lumânări de
1 oră. Reguli fixe, testate pe date istorice; motorul rulează singur, din cron.
**Unealta manuală a fost scoasă pe 12.11.2026** — utilizatorul va avea mai târziu
o unealtă nouă, separată. Doar long.

## La începutul fiecărei sesiuni

Citește **`docs/plan-bot.md`** — sursa de adevăr pentru reguli, de unde vin și cum
se citesc rezultatele — și **`docs/plan-bot-zec.md`** pentru băncile D, E, F. Apoi **`DEPLOY.md`** (hosting, cronurile, capcanele cPanel).

## Stare la 12 noiembrie 2026: CONSTRUIT, DE PORNIT

| Piesă | Unde |
|---|---|
| Pagina (doar citire) | `public/index.html` · `bot.js` · `bot.css` |
| API (doar GET) | `public/api/bot.php` · `_comun.php` |
| Motor | `motor/bot.php`, cron **orar, la minutul 2** |
| **Reguli pure** | `motor/bot-reguli.php` |
| Probe | `motor/probe/bot-matematica.php` (50) · `bot-echivalenta.php` (față de simulare) |
| Bază de date | MySQL `marcelpa_autobot`, 8 tabele `b_*`; tabelele `u_*` ale uneltei vechi rămân, neatinse |
| Bănci | A fix 50/50 · B fix + excepție · C pasivă — câte **1000 USDC** |
| Migrație de rulat | `baza-de-date/migratii/2026-11-12-bot.sql` |

**Din 15.11.2026: încă trei bănci, doar pe ZECUSDC** (`docs/plan-bot-zec.md`) —
D 50/50 · E 30/70 · F 30/70 + excepție la 55%. Reguli în `z_pasOra` (același
fișier, funcție separată), probe `motor/probe/zec-matematica.php` (33) și
`zec-echivalenta.php`, migrație `2026-11-15-banci-zec.sql` (coloana `banca` în
`b_exceptii`). Pornesc singure la prima oră de după migrație + deploy.
Testate și picate pe ZEC și BTC, de nereluat: banda dinamică, semnalul altei
monede, filtrul de volum, Fear & Greed. Excepția trece doar pe ZEC.
Tot de nereluat: execuția cu ordine limită pentru F (16.11.2026) — economie
+0,06% ± 0,06% pe parte, t ≈ 1; vezi `cercetare/README.md`.

Configurarea (parola bazei): `/home/marcelpa/autobot-config.php`, în afara zonei
publice, niciodată în git.

## ⛔ Ce NU se face aici

- **Nu se schimbă regulile pe parcurs.** Botul e un test înainte, preînregistrat
  (plan §9): o regulă schimbată la 4 luni repornește numărătoarea de la zero.
  Dacă utilizatorul vrea altă regulă, e o bancă nouă sau un bot nou, nu o
  modificare.
- **Orice abatere de la simulare e o greșeală, nu o îmbunătățire.** Singura
  abatere acceptată, documentată în plan §4: fără levier.
- **Ținta de volatilitate nu intră** — testată, strică (memorie `trei-straturi-cos`).
- **Botul cu triunghiuri a fost șters pe 20.09.2026** (avantaj real +0,062% brut,
  sub comisionul de 0,150%). Nu-l reînvia și nu propune tipare geometrice pe grafic.
  Codul e în istoricul git, până la `948e204`.

## Reguli de lucru

- Tot ce ajunge pe web stă în **`public/`**. Deploy-ul nu copiază altceva.
- Se lucrează pe `main`: commit + push. Înainte de push, **arată-i utilizatorului
  ce s-a modificat** ca să confirme.
- Deploy-ul îl face utilizatorul, din cPanel. **„Update from Remote" e stricat
  din 18.09.2026** — există un cron care aduce codul; vezi `DEPLOY.md` §2.
- Mesajele de commit au diacritice → fișier cu `-F`, nu `-m`.
- Identitate explicită la commit:
  `git -c user.name="Cristi Iorga" -c user.email="cristi.s.iorga@gmail.com"`
- În commit nu se pune identificatorul de model.
- **`VERSIUNE` din `public/bot.js` se schimbă la fiecare modificare din
  `public/`.** API-ul o citește de pe disc, iar pagina compară.
- **După orice atingere a regulilor:** ambele probe. Echivalența cere datele de
  referință (generate de `gen_echiv.py` din simulare; vezi capul fișierului).

## Regulile, pe scurt

Detaliile în `docs/plan-bot.md` — **nu le reinventa.**

- Semnal de panică pe o monedă: volatilitatea pe 24 h (abaterea std a 24 de
  randamente log orare) are rang ≥ 0,8 față de cele 720 de ore dinainte, ȘI prețul
  e sub cel de acum 24 h.
- A și B țin 1/6 din capital pe monedă; B ține 1/3 timp de 24 h după semnal.
  Un semnal în chiar ora expirării e ignorat (așa a rulat simularea).
- Reechilibrare la abatere > 5% din capital; la pornirea/expirarea unei excepții,
  oricât de mică.
- Ordinea într-o oră: întâi vânzările, apoi cumpărările, fără levier.
- Cumpără la ask, vinde la bid, comision 0,075% pe parte. Se scrie și închiderea
  orei (`pret_simulare`), ca să se vadă costul real al execuției.
- Ore pierdute (cron oprit): se judecă și se scriu, **fără tranzacții**.

## Capcane deja plătite

- **Simularea băncii B avea levier fără să știm** — USDC negativ în 4% din ore,
  până la −6% din capital. Prins abia la proba de echivalență. De aici regula
  „vânzările întâi, cumpărări doar din USDC existent”.
- **Datele istorice nu sunt la zi uniform**: BTC și ETH se termină pe 15.08.2026,
  ZEC în noiembrie. Verifică data de final înainte să scrii o perioadă.
- **Timpul e BIGINT în milisecunde UTC peste tot.** `ora` = deschiderea lumânării
  de 1h; decizia se ia la închiderea ei (pagina afișează ora închiderii).
- **`serialize_precision` e mare pe server** — `_comun.php` îl fixează la -1.
- **Atributul `hidden` nu ascunde** elementele cu `display` din clasă; de aceea
  `[hidden] { display: none !important; }` în `bot.css`.
- **Migrațiile sunt MANUALE și nu rulează la deploy.** Din phpMyAdmin, **înaintea**
  deploy-ului codului care le cere.
- **Lista de Cron Jobs e comună pe tot contul cPanel.** Citește comanda întreagă
  înainte de Delete (s-a șters odată motorul greșit).

## Parola — amânată deliberat

Site-ul e deschis, intenționat: date fictive. Utilizatorul a cerut să fie întrebat
din nou înainte de bani reali, chei API pe server sau date care nu se pot reface.
**Până atunci nu insista.** Capcana de la deploy: `DEPLOY.md` §2.1.

## Context vecin

Același cont cPanel (`marcelpa`) găzduiește și `marcel-parcel.ro`, `dunitru.ro`,
`thriftshop.dunitru.ro`, `valentina.dunitru.ro`. Proiecte complet separate — nu se
modifică nimic acolo din sesiunile de aici. Deploy-ul lui dunitru.ro golește
`/home/marcelpa/dunitru.ro/public_html`, de aceea autobot are arbore separat.
