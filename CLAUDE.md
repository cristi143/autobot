# autobot.dunitru.ro — context de lucru

**Unealtă de tranzacționare manuală** pe Binance, ZECUSDC, pe lumânări de 15
minute. Utilizatorul pune nivelurile, sistemul doar execută. Bani fictivi.

## La începutul fiecărei sesiuni

Citește **`docs/plan-unealta.md`** — sursa de adevăr pentru reguli. Apoi
**`DEPLOY.md`** (hosting, cele două cronuri, capcanele cPanel).

## Stare la 20 septembrie 2026: RULEAZĂ

| Piesă | Unde |
|---|---|
| Pagina (fără grafice) | `public/index.html` · `unealta.js` · `unealta.css` |
| API | `public/api/unealta.php` · `_comun.php` |
| Motor | `motor/unealta.php`, cron la un minut |
| **Reguli pure** | `motor/unealta-reguli.php` |
| Probe | `motor/probe/unealta-matematica.php` — **88 de probe** |
| Bază de date | MySQL `marcelpa_autobot`, 6 tabele cu prefix `u_` |
| Bănci | **1000 USDC** (long) și **1 ZEC** (short) |

Configurarea (parola bazei, cheia de scriere): `/home/marcelpa/autobot-config.php`,
în afara zonei publice, niciodată în git.

## ⛔ Ce NU se mai face aici

**Până pe 20.09.2026, domeniul găzduia și un bot automat cu triunghiuri.** A fost
șters, cu tot cu cercetare, la cererea utilizatorului. **Nu-l reînvia și nu
propune tipare geometrice pe grafic** — linii de trend, triunghiuri, spargeri de
canal.

Motivul e măsurat, nu presupus: pe **40.832 de triunghiuri**, 26 de simboluri și
o perioadă ținută deoparte, tiparul avea un avantaj real de **+0,062% brut** pe
tranzacție, confirmat statistic. **Comisionul e 0,150%.** Informație există, dar
e 41% din cât ar trebui.

Dacă subiectul revine, dă cifra și lasă decizia la el. Codul, protocolul probei
și triunghiurile desenate sunt în istoricul git, până la `948e204`.

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
- **`VERSIUNE` din `public/unealta.js` se schimbă la fiecare modificare din
  `public/`.** API-ul o citește de pe disc, iar pagina compară: diferite =
  browserul rulează cod vechi din cache, și banda de sus o spune.

## Regulile, pe scurt

Detaliile și motivele sunt în `docs/plan-unealta.md` — **nu le reinventa.**

- **Nu se intră la atingere, ci la revenire.** Prețul trebuie să treacă dincolo
  de nivel cu `depasire_minima`, apoi să se întoarcă.
- **Pragul de intrare urmărește extremul** (`extrem ± revenire`), plafonat la
  nivelul ales: doar se depărtează de el, niciodată nu se apropie.
- **Ținta de ieșire e absolută** — nu se mută cu prețul real de intrare. Intrat
  mai jos → câștig mai mare. E intenția, nu o scăpare.
- **După atingerea țintei, pragul de ieșire urmărește maximul** (`maxim −
  urmarire`), plafonat la țintă. **Doar urcă.**
- **Totul se declanșează pe ÎNCHIDEREA unei lumânări de 15m**, deci prețul de
  execuție e acea închidere, nu pragul. **Excepție: stopul**, pe atingere.
- **Extremele se iau din wick-uri**, declanșările din închideri. Wick-urile spun
  unde a fost prețul; închiderile spun dacă s-a rupt ceva.
- **Stop și ieșire în aceeași lumânare → se ia stopul.** Convenție pesimistă.
- **O setare activă pe bancă**, dar băncile merg în paralel.
- Comision **0,075% pe parte**.

## Capcane deja plătite

- **Stopul atins ÎNAINTE de intrare omoară setarea** (starea `expirat`). Fără
  regula asta, pragul de intrare cobora cu minimul până sub stop, iar „stopul" se
  declanșa imediat, pe profit: nivel 1450, stop 1380, preț căzut la 1200, intrare
  la 1227, „stop" la 1380 cu **+12,23%**. Găsit rulând regulile pe date reale.
  Consecința: **stopul mărginește cât de jos poate coborî pragul de intrare.**
- **Stopul trebuie să fie dincolo de pragul de armare**, nu doar dincolo de
  nivel. `nivel 800, coborâre 20, stop 780` e imposibil din construcție: exact
  atingerea care armează setarea o și omoară.
- **Regulile stau într-un singur fișier** (`motor/unealta-reguli.php`), cerut de
  motor, de API și de probe. Rescrise în fiecare, simetria long/short s-ar
  desincroniza tăcut. API-ul îl cere pe cale absolută din
  `/home/marcelpa/autobot-motor/`, la fel cum cere configurarea.
- **API-ul nu atinge banii.** Scrie doar intenții (o setare, un steag de
  închidere); soldurile le mișcă exclusiv motorul. De asta „ieși acum" se execută
  la următoarea rulare — un singur scriitor face mai mult decât un minut câștigat.
- **Timpul e BIGINT în milisecunde UTC peste tot.** Serverul are fusul
  Europe/Bucharest. Orice formatare fără UTC explicit aliniază lumânările greșit
  cu 2–3 ore, tăcut.
- **`serialize_precision` e mare pe server** — fără `ini_set('serialize_precision','-1')`
  din `_comun.php`, json_encode scrie 0.075 ca 0.07499999999999999722…
- **Atributul `hidden` nu ascunde** elementele stilate cu `display:flex`; de aceea
  există `[hidden] { display: none !important; }` în `unealta.css`.
- **Migrațiile sunt MANUALE și nu rulează la deploy.** Se scriu în
  `baza-de-date/migratii/`, se rulează din phpMyAdmin, **înaintea** deploy-ului
  codului care le cere.

## Parola — amânată deliberat

Site-ul e deschis, intenționat: *„deocamdată sunt doar date fictive"*.
Utilizatorul a cerut să fie întrebat din nou înainte de bani reali, chei API pe
server sau date care nu se pot reface. **Până atunci nu insista.** Capcana de la
deploy, pentru când se va face: `DEPLOY.md` §2.1.

## Context vecin

Același cont cPanel (`marcelpa`) găzduiește și `marcel-parcel.ro`, `dunitru.ro`,
`thriftshop.dunitru.ro`, `valentina.dunitru.ro`. Proiecte complet separate, cu
repo-uri separate — nu se modifică nimic acolo din sesiunile de aici.
**Atenție:** deploy-ul lui dunitru.ro golește `/home/marcelpa/dunitru.ro/public_html`,
de aceea autobot are arbore separat.
