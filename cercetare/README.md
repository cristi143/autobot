# Cercetarea din spatele botului (12.11.2026)

Codul care a produs rezultatele din `docs/plan-bot.md`, păstrat ca regulile
botului să poată fi verificate oricând față de el. **Fără date**: totul se
generează din `../../historical_data/` (7.5 GB, în afara git-ului).

| Fișier | Ce e |
|---|---|
| `PREINREGISTRARE_vol_cadere.md` | regulile testului „vol mare după cădere”, scrise înainte de rulare |
| `volcad.py` | testul de mai sus pe 20 de perechi nevăzute (confirmat: +0,54%/tranz., t = 4,5) |
| `PREINREGISTRARE_trei_straturi.md` | regulile testului de portofoliu, scrise înainte de rulare |
| `straturi.py` | coșul ZEC+BTC+ETH: deținere, 50/50, ținta de volatilitate, excepția |
| `s5.py` | 50/50 + excepția (banca B a botului) |
| `gen_echiv.py` | datele de referință pentru `motor/probe/bot-echivalenta.php` |

Rulare (pypy3, din acest dosar):

```bash
pypy3 gen_echiv.py          # scrie echiv_inchideri.csv, echiv_semnale.csv, echiv_referinta.json
php ../motor/probe/bot-echivalenta.php .
```

Prima rulare agregă lumânările de 1m pe ore în `hourly/` (câteva minute).

## Botul doar pe ZEC (15.11.2026)

Testele din spatele `docs/plan-bot-zec.md`. Datele vin direct de la Binance (ore,
cu volum) și de la alternative.me (Fear & Greed), nu din `historical_data/`.

| Fișier | Ce e |
|---|---|
| `PREINREGISTRARE_zec_singur.md` | regulile celor cinci teste, scrise înainte de rulare; adaosul pentru BTC |
| `zec_singur_date.py` | descarcă orele ZEC, BTC, ETH din 07.2019 într-un JSON |
| `zec_singur.py` | baza 50/50 și 30/70, banda, excepția, semnalul extern, volumul, Fear & Greed |
| `zec_singur_ZEC.out` · `zec_singur_BTC.out` | rezultatele rulării din 15.11.2026 |

```bash
python3 zec_singur_date.py ore.json
curl -s 'https://api.alternative.me/fng/?limit=0&format=json' -o fng.json
pypy3 zec_singur.py ore.json fng.json ZECUSDT BTCUSDT   # moneda testată, semnalul extern
pypy3 zec_singur.py ore.json fng.json BTCUSDT ETHUSDT
```

Etichetele din ieșire spun „ZEC” pentru moneda testată și „BTC” pentru semnalul
extern, oricare ar fi ele — în `zec_singur_BTC.out` se citesc BTC și ETH.

## Execuția cu ordine limită pentru banca F (16.11.2026)

Se schimbă doar execuția, nu regulile: ordin limită care așteaptă 5/15/60 de
minute, față de cumpărat la ask și vândut la bid. Ticks ZECUSDC, 01.01–09.11.2026.

| Fișier | Ce e |
|---|---|
| `PREINREGISTRARE_executie_limita.md` | variantele, măsurile și criteriul, scrise înainte de rulare |
| `executie_limita.py` | reconstruiește bid/ask din ticks și compară execuțiile |
| `executie_limita.out` | rezultatul rulării din 16.11.2026 |

```bash
pypy3 executie_limita.py ../../historical_data/ZECUSDC
```

**Rezultat: pică.** Varianta reținută pe ianuarie–mai (ordin la închiderea orei,
15 minute) economisește pe iunie–noiembrie +0,059% ± 0,061% pe parte, t ≈ 1 —
criteriul cerea t ≥ 2. Pe toate orele economia e de ~0,01% pe parte. La pornirea
excepției (cumpărarea din panică) ordinul limită iese chiar puțin mai scump decât
piața. Costul la piață reconstruit (0,046%) se potrivește cu cel măsurat live
(0,048%). Execuția rămâne la piață.
