# Preînregistrare — după o mișcare de 1 ATR: continuare sau întoarcere, pe regimuri

Scrisă pe 16.11.2026, **înainte de orice rulare**. Pornește de la un text adus de
Cristi: în orele volatile o spargere de 1 ATR ar continua (intrare cu două ordine,
unul peste preț și unul sub el), iar în orele liniștite mișcările s-ar întoarce.

Ce știm dinainte: direcția n-a putut fi prezisă în niciun test de până acum
(spargeri, structură, linii de trend, suport, RSI). Condiționarea pe regimul de
volatilitate, cu ținte în ATR, nu a fost încercată. Mă aștept să pice la cost.

## Date

- `historical_data/ZECUSDT/candles_1m` (07.2019 – 09.11.2026) și
  `BTCUSDT/candles_1m` (07.2019 – 14.08.2026). Lumânările se aleg după timestamp.
  Minutele lipsă se completează cu ultima închidere.
- **Antrenare:** 01.08.2019 – 31.12.2022. **Test:** 01.01.2023 – sfârșitul datelor.

## Definiții

- Ore construite din minute. **ATR** = media intervalului real (true range) pe
  ultimele 14 ore închise.
- **Regim**, ca semnalul botului: abaterea std a 24 de randamente log orare, rang
  față de cele 720 de valori dinainte. **Volatil:** rang ≥ 0,8. **Liniștit:**
  rang < 0,5. Mijlocul se raportează, dar nu se judecă.
- **Evenimentul.** La închiderea fiecărei ore (preț c, ATR a) se pun două niveluri:
  c + a și c − a, valabile doar în ora următoare. Primul atins, pe minute, dă
  intrarea E și direcția. Dacă amândouă sunt atinse în același minut, ora se aruncă.
- **Urmarea.** Din minutul de după intrare, cel mult 24 h: bariera de continuare
  E + m·a (în direcția mișcării) și cea de întoarcere E − m·a, cu **m = 1 și m = 2**.
  Dacă amândouă sunt atinse în același minut, rezultatul se socotește împotriva
  strategiei judecate. La 24 h fără barieră, ieșire la închiderea minutului.

## Strategiile

- **Continuare (momentum):** intră în direcția mișcării, TP la bariera de
  continuare, SL la cea de întoarcere. Doar long și short simetric — e un studiu
  de eveniment, nu un bot.
- **Întoarcere:** poziția inversă, cu barierele schimbate între ele.
- **Cost:** 0,125% pe parte, 0,25% dus-întors, la amândouă.

## Măsuri

- frecvența cu care bariera de continuare e atinsă prima (din cele rezolvate la
  barieră), față de 50%;
- randamentul mediu pe tranzacție, brut și net, în %; t pe medii zilnice (ziua
  intrării), ca evenimentele din ore vecine să nu fie numărate ca independente;
- pragul de rentabilitate al frecvenței, la ATR-ul mediu al perioadei.

## Criteriul

**Ipoteza C (continuare în regim volatil)** trece dacă randamentul net mediu al
continuării e > 0 cu t ≥ 2, pe **antrenare și pe test**, la același m, pe ZEC.
m se alege pe antrenare (cel cu netul mai mare).

**Ipoteza I (întoarcere în regim liniștit)** — la fel, cu strategia de întoarcere.

BTC e replicare: o ipoteză care trece pe ZEC dar are semn opus pe BTC se
raportează ca nesigură.

Separat de cost, se spune și dacă există o abatere brută de la 50% (|t| ≥ 2 în
ambele perioade, același semn) — adică „efect real, dar sub cost”.

## Ce nu se face

- Nu se adaugă după rezultat alte praguri (alt multiplu de ATR la intrare, altă
  valabilitate, alt regim, ore din zi). Ce pare bun e ipoteză pentru altă dată.
- Rezultatul nu atinge băncile A–F. Dacă ceva trece, urmează întâi verificarea
  pe ticks (stopurile se execută prost tocmai în minutele volatile), apoi o bancă nouă.
