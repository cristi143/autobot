# autobot.dunitru.ro — context de lucru

Proiect nou: platformă de tranzacționare automată pe Binance.
Stadiu actual: **grafic de lumânări 1h pentru ZECUSDC, live** (stil TradingView),
plus lanțul de deploy. Motorul botului nu există încă.

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
