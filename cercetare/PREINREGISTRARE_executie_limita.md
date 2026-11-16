# Preînregistrare — execuția cu ordine limită pentru banca F

Scrisă pe 15.11.2026, **înainte de orice rulare**. Cerută de Cristi după ce regulile
băncilor D, E, F au fost fixate. Înainte de a scrie am văzut doar: formatul
fișierelor de ticks, zilele acoperite și costul de execuție măsurat live
(0,048% pe parte, 20 de tranzacții pe cele trei monede).

## Întrebarea

Banca F face cam 150 de tranzacții pe an, cele mai multe de ~25% din capital.
La 0,125% pe parte, costul e de ordinul a 4–5% din capital pe an. Dacă ordinele
ar sta în carte (limită) în loc să ia prețul pieței, cât din costul ăsta se
recuperează — după ce se scade ce se pierde când ordinul nu se execută?

**Nu se schimbă nicio regulă de decizie.** Semnalul, țintele, banda și ora deciziei
rămân cele din `docs/plan-bot-zec.md`. Se schimbă doar cum se execută o tranzacție
deja hotărâtă.

## Date

- `historical_data/ZECUSDC/trades/`: ticks (`timestamp_ms, price, quantity`),
  01.01.2026 – 09.11.2026 (ziua de 10.11 e parțială și se aruncă).
- `historical_data/ZECUSDC/candles_1m/` din 05.11.2025: pentru închiderile orare
  și încălzirea semnalului (745 de ore). Lumânările se aleg după timestamp, nu
  după numele fișierului (fișierul zilei D începe la 23:59 din D−1).
- Deciziile: banca F rulată cu `z_pasOra`-ul simulării pe închiderile orare
  ZECUSDC, pornită la 01.01.2026 00:00 UTC cu 1000 USDC.

## Limitele datelor, spuse dinainte

- **Nu avem cartea de ordine și nici partea agresoare a tranzacțiilor.** Bid și
  ask se reconstruiesc din ticks: o tranzacție la preț mai mare decât prețul
  distinct anterior e socotită la ask, una la preț mai mic, la bid. Ask-ul curent
  = ultima tranzacție „la ask”, bid-ul curent = ultima „la bid”. Dacă
  reconstrucția dă ask ≤ bid, ambele se înlocuiesc cu ultimul preț.
- **Nu știm locul nostru în coadă.** De aceea un ordin limită se socotește executat
  doar când piața trece **strict prin** prețul lui (o tranzacție sub prețul de
  cumpărare, respectiv peste cel de vânzare). O tranzacție exact la prețul
  ordinului nu contează. E regula prudentă; realitatea e între ea și „atinge = executat”.
- Ordinele sunt mici (sute de USDC); nu se modelează efectul lor asupra pieței.
- Un singur an, pe o singură pereche. 2026 nu e un an reprezentativ pentru ZEC.
- Botul fictiv nu poate verifica înainte execuția unui ordin limită: nu trimite
  ordine. Vezi „Ce urmează, oricum ar ieși”.

## Execuțiile comparate

Decizia se ia la închiderea orei H. Toate variantele pornesc la **H + 2 minute**,
ca motorul live.

- **Referința (piață):** cumpără la ask-ul reconstruit de la H + 2 min, vinde la
  bid. Comision 0,075%.
- **Limită:** ordinul se pune la H + 2 min și așteaptă cel mult W minute. Dacă nu
  s-a executat, restul se face la piață, la ask-ul/bid-ul din acel moment.
  Cantitatea e cea hotărâtă la închiderea orei, neschimbată.
  - prețul ordinului: **P1** = bid-ul reconstruit (la cumpărare; ask la vânzare);
    **P2** = închiderea orei H, dar nu mai agresiv decât P1
  - așteptarea: **W** = 5, 15 sau 60 de minute
  - în total 6 variante

**Comisionul ordinului limită:** 0,075%, același ca la piață — varianta
principală, pe care se judecă. Confirmat de Cristi pe 16.11.2026, înainte de
rulare: 0,075% pe parte, 0,15% dus-întors. Separat, doar ca sensibilitate, se
raportează și cu 0%.

## Măsuri

1. **Costul pe parte** al fiecărei tranzacții a lui F: prețul de execuție, cu
   comision, față de închiderea orei, în % din valoare, cu semn (plus = cost).
   Aceeași măsură ca `cost_executie` de pe pagină.
2. **Economia**: costul la piață minus costul cu limită, pereche cu pereche, pe
   aceleași tranzacții. Medie, eroare standard, t.
3. **Capitalul final al lui F** cu fiecare execuție. Aici intră și ce nu se vede
   în (1): la cumpărările de panică, ordinul care nu se execută e tocmai cel după
   care prețul a sărit.
4. Descriptiv: cât la sută din ordine se execută ca limită, separat pe
   pornire de excepție / expirare / reechilibrare.
5. Secundar, pentru mărimea eșantionului: măsura (2) pe **toate orele** din
   perioadă, câte o cumpărare și o vânzare închipuite pe oră, separat pe ore cu
   semnal și fără. Nu poate face singură testul să treacă.

Verificarea modelului: costul de piață reconstruit se compară cu cel măsurat
live (0,048% fără comision). Dacă iese de peste trei ori mai mare sau mai mic,
reconstrucția bid/ask e declarată nesigură și rezultatul se raportează ca atare.

## Împărțirea și criteriul

- **Alegere:** 01.01 – 31.05.2026. Dintre cele 6 variante se reține una singură,
  cea cu economia medie cea mai mare pe tranzacțiile lui F.
- **Judecată:** 01.06 – 09.11.2026, doar pe varianta reținută.

**Trece** dacă, pe perioada de judecată, toate trei:
1. economia medie pe parte ≥ **0,03%** din valoarea tranzacției și t ≥ 2;
2. capitalul final al lui F e mai mare cu limită decât cu piață;
3. economia are același semn și pe perioada de alegere.

Sub 0,03% pe parte nu merită: la 150 de tranzacții de 25% înseamnă sub ~1% din
capital pe an, contra unui motor care trebuie să pună, să urmărească și să
anuleze ordine reale.

Mă aștept la ~125 de tranzacții ale lui F în total, deci ~60 pe fiecare jumătate.
Dacă pe perioada de judecată sunt **sub 40**, rezultatul se declară neconcludent,
oricum ar arăta.

## Ce nu se face

- Nu se adaugă variante după ce se văd rezultatele (alt preț, altă așteptare,
  ordine în trepte). Dacă una pare bună, e ipoteză pentru altă dată.
- Nu se judecă pe varianta cu comision 0%; aceea e doar sensibilitate.
- Rezultatul nu atinge băncile D, E, F: ele execută mai departe la piață, cum
  sunt preînregistrate.

## Ce urmează, oricum ar ieși

- **Pică:** execuția rămâne la piață; subiectul se închide.
- **Trece:** nu înseamnă că funcționează, ci că merită verificat. Verificarea
  reală cere fie ordine adevărate, cu sume mici (deci chei API — proiect separat,
  cu confirmare explicită, care redeschide și discuția despre parolă), fie măcar
  înregistrarea bid/ask-ului real la fiecare oră, ca să se vadă cât de departe e
  reconstrucția din ticks de carte.
