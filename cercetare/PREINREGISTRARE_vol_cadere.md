# Preînregistrare — „volatilitate mare după cădere"

Scrisă pe 12.11.2026, ÎNAINTE de orice rulare a testului de mai jos. Explorarea care a
generat ipoteza s-a uitat doar la ZECUSDT, BTCUSDT, ETHUSDT.

## Ipoteza
După o zi agitată în care prețul a căzut, următoarele 24h au randament peste medie.

## Date
- Lumânări 1m din `historical_data/`, agregate pe ore (close = ultimul close din oră,
  după `open_time`). 2019-07 → 2026-11.
- **Test principal: perechile pe care nu m-am uitat** — toate perechile crypto *USDT,
  fără ZEC, BTC, ETH. PAXG și XAUT (aur) = control separat, nu intră în rezultat.
- ZEC/BTC/ETH raportate separat (nu sunt dovadă, ipoteza vine din ele).

## Semnal (fixat, identic cu explorarea)
La închiderea fiecărei ore t:
- `vol` = abaterea standard a celor 24 de randamente log orare până la t
- rangul lui `vol` printre valorile din cele 720 de ore dinainte (fără privire în viitor)
- **semnal** dacă rang ≥ 0,8 (cincimea de sus) ȘI `ln(p_t / p_{t-24}) < 0`

## Tranzacție
- Cumpărare la close-ul orei t, vânzare la close-ul orei t+24. Fără stop.
- Comision 0,075% pe parte (0,15% dus-întors).
- O singură poziție pe pereche; după vânzare, semnalul se poate repeta imediat.

## Măsura
- `net` = randamentul tranzacției după comision.
- `exces` = net − media randamentului de 24h al aceleiași perechi în același an
  calendaristic (scoate deriva: an de creștere / scădere).
- Crypto se mișcă împreună, deci tranzacțiile din aceeași zi nu sunt independente:
  **se face media pe zi calendaristică (după ziua intrării), apoi t = medie / eroare pe
  seria zilelor.**

## Criteriul de succes (fixat acum)
Pe perechile nevăzute, TOATE trei:
1. `exces` mediu > 0 cu t > 2
2. `net` mediu > 0
3. `exces` > 0 în cel puțin 5 din cei 8 ani calendaristici (2019 parțial … 2026 parțial)

Dacă oricare pică → ipoteza e respinsă, nu se mai caută variante.

## Grilă de robustețe (raportată, NU folosită pentru a salva ipoteza)
Ține H ∈ {6, 24, 72} h; prag rang ∈ {0,8; 0,9}; cădere ∈ {orice, < −1 abatere de 24h
(= −vol·√24)}. Pe aceste 12 celule: alegere pe 2019–2022, verificare pe 2023–2026,
corelația de rang între cele două perioade.

## Comparații
Aceeași mecanică pentru „vol mare după CREȘTERE" și „vol mică" — dacă și ele dau
`exces` > 0, efectul nu e al căderii.
