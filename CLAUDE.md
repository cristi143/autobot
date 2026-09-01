# autobot.dunitru.ro — context de lucru

Proiect nou: platformă de tranzacționare automată pe Binance.
Stadiu actual: **grafic de lumânări 1h pentru ZECUSDC** (stil TradingView), plus
lanțul de deploy. Motorul botului nu există încă.

## La începutul fiecărei sesiuni
Citește **`DEPLOY.md`** — hosting, deploy, și împărțirea arhitecturii
(ce rulează pe ClausWeb vs. ce trebuie să ruleze pe VPS).

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

## Graficul (starea actuală a site-ului)
- Pagina principală = grafic de lumânări 1h, cu `lightweight-charts` de la
  TradingView, luat de pe jsDelivr (versiune fixată: 4.2.3).
- **Datele sunt un JSON commitat**, `public/data/<SIMBOL>-1h.json`, generat de
  `tools/agrega_1h.py` din `../historical_data/`. Nu se citește live de la Binance —
  site-ul e static.
- După date noi: `python3 tools/agrega_1h.py ZECUSDC`, commit JSON-ul, deploy.
- Simbolul afișat se schimbă din `var SIMBOL` în `public/grafic.js`.
- Fișierele 1m rămân în afara git-ului; doar JSON-ul agregat intră.

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
