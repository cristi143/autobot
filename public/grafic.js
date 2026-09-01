/* Grafic de lumânări 1h în stilul TradingView, cu trei straturi de date:
 *
 *   1. ISTORIC  — JSON commitat (public/data/<SIMBOL>-1h.json), generat de
 *                 tools/agrega_1h.py din lumânările de 1 minut. Se încarcă
 *                 instant și funcționează chiar dacă Binance e inaccesibil.
 *   2. PUNTE    — REST la Binance pentru intervalul dintre ultima lumânare din
 *                 JSON și momentul curent. Acoperă golul, oricât ar fi de mare.
 *   3. LIVE     — WebSocket, lumânarea în formare se actualizează în timp real.
 *
 * Binance permite CORS pe datele publice de piață (access-control-allow-origin: *)
 * și nu cere cheie API pentru ele, deci totul se face din browser. Site-ul rămâne
 * static — nimic de rulat pe server.
 */

(function () {
  "use strict";

  var SIMBOL   = "ZECUSDC";
  var INTERVAL = "1h";
  var ORA_MS   = 3600000;

  var REST = "https://api.binance.com/api/v3/klines";
  var WS   = "wss://stream.binance.com:9443/ws/" +
             SIMBOL.toLowerCase() + "@kline_" + INTERVAL;

  var elGrafic  = document.getElementById("grafic");
  var elStare   = document.getElementById("stare");
  var elOhlc    = document.getElementById("ohlc");
  var elRezumat = document.getElementById("rezumat");
  var elLive    = document.getElementById("live");

  /* ---------- temă ---------- */

  function tema() {
    var intunecat = window.matchMedia("(prefers-color-scheme: dark)").matches;
    return intunecat
      ? { fundal: "#161b22", text: "#8b949e", linii: "#262c36", cruce: "#4b5563" }
      : { fundal: "#ffffff", text: "#6b7280", linii: "#eceff3", cruce: "#9ca3af" };
  }

  function zecimale(pret) {
    if (pret >= 1)    return 2;
    if (pret >= 0.01) return 4;
    return 8;
  }

  function stare(text, clasa) {
    if (!elLive) return;
    elLive.textContent = text;
    elLive.className = "live " + (clasa || "");
  }

  if (typeof LightweightCharts === "undefined") {
    elStare.textContent = "Nu s-a putut încărca biblioteca de grafice.";
    elStare.classList.add("eroare");
    return;
  }

  /* ---------- graficul ---------- */

  var t = tema();

  var chart = LightweightCharts.createChart(elGrafic, {
    layout:     { background: { color: t.fundal }, textColor: t.text, fontSize: 12 },
    grid:       { vertLines: { color: t.linii }, horzLines: { color: t.linii } },
    rightPriceScale: { borderColor: t.linii, scaleMargins: { top: 0.08, bottom: 0.26 } },
    timeScale:  { borderColor: t.linii, timeVisible: true, secondsVisible: false },
    crosshair:  {
      mode: LightweightCharts.CrosshairMode.Normal,
      vertLine: { color: t.cruce, width: 1, style: 3, labelBackgroundColor: t.cruce },
      horzLine: { color: t.cruce, width: 1, style: 3, labelBackgroundColor: t.cruce }
    },
    localization: { locale: "ro-RO" },
    autoSize: true
  });

  var serieLum = chart.addCandlestickSeries({
    upColor: "#26a69a", downColor: "#ef5350",
    borderUpColor: "#26a69a", borderDownColor: "#ef5350",
    wickUpColor: "#26a69a", wickDownColor: "#ef5350"
  });

  var serieVol = chart.addHistogramSeries({
    priceFormat: { type: "volume" },
    priceScaleId: "volum",
    lastValueVisible: false,
    priceLineVisible: false
  });
  chart.priceScale("volum").applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });

  /* ---------- starea datelor ---------- */

  var lumanari = [];            // sortate crescător după time (secunde)
  var indice   = {};            // time -> poziție în lumanari
  var z        = 2;             // zecimale de preț
  var fixat    = false;         // utilizatorul a mutat/zoom-at graficul?

  // Se marchează doar la interacțiune reală. Nu folosim
  // subscribeVisibleLogicalRangeChange: acela se declanșează și când mutăm noi
  // graficul din cod, și atunci n-am mai încadra niciodată datele nou-sosite.
  ["wheel", "mousedown", "touchstart"].forEach(function (ev) {
    elGrafic.addEventListener(ev, function () { fixat = true; }, { passive: true });
  });

  function culoareVol(c) {
    return c.close >= c.open ? "rgba(38,166,154,.35)" : "rgba(239,83,80,.35)";
  }

  function reindexeaza() {
    indice = {};
    for (var i = 0; i < lumanari.length; i++) indice[lumanari[i].time] = i;
  }

  /** Adaugă sau înlocuiește lumânări, păstrând ordinea. Întoarce true dacă
      setul s-a schimbat structural (lumânări noi), nu doar ultima actualizată. */
  function imbina(noi) {
    var adaugate = 0;
    for (var i = 0; i < noi.length; i++) {
      var c = noi[i];
      if (indice[c.time] !== undefined) {
        lumanari[indice[c.time]] = c;
      } else {
        lumanari.push(c);
        adaugate++;
      }
    }
    if (adaugate) {
      lumanari.sort(function (a, b) { return a.time - b.time; });
      reindexeaza();
    }
    return adaugate > 0;
  }

  function deseneazaTot() {
    serieLum.setData(lumanari);
    serieVol.setData(lumanari.map(function (c) {
      return { time: c.time, value: c.volume, color: culoareVol(c) };
    }));
  }

  function actualizeazaUltima(c) {
    serieLum.update(c);
    serieVol.update({ time: c.time, value: c.volume, color: culoareVol(c) });
  }

  function aplicaPrecizia() {
    if (!lumanari.length) return;
    z = zecimale(lumanari[lumanari.length - 1].close);
    serieLum.applyOptions({
      priceFormat: { type: "price", precision: z, minMove: Math.pow(10, -z) }
    });
  }

  var ZILE_LA_DESCHIDERE = 30;

  function incadreaza() {
    if (fixat || !lumanari.length) return;
    var de_la = Math.max(0, lumanari.length - 24 * ZILE_LA_DESCHIDERE);
    chart.timeScale().setVisibleLogicalRange({ from: de_la, to: lumanari.length + 2 });
  }

  function scrieRezumat() {
    if (!lumanari.length) return;
    var f = function (s) { return new Date(s * 1000).toISOString().slice(0, 10); };
    elRezumat.textContent =
      lumanari.length.toLocaleString("ro-RO") + " lumânări · " +
      f(lumanari[0].time) + " → " + f(lumanari[lumanari.length - 1].time);
  }

  function scrieOhlc(c) {
    if (!c) { elOhlc.textContent = ""; return; }
    var urcat = c.close >= c.open;
    var dif = c.open ? ((c.close - c.open) / c.open) * 100 : 0;
    elOhlc.innerHTML =
      ["O", c.open, "H", c.high, "L", c.low, "C", c.close]
        .map(function (v, i) {
          return i % 2 === 0
            ? '<span class="et">' + v + "</span>"
            : '<span class="vl">' + v.toFixed(z) + "</span>";
        }).join(" ") +
      ' <span class="dif ' + (urcat ? "sus" : "jos") + '">' +
      (urcat ? "+" : "") + dif.toFixed(2) + "%</span>";
  }

  /* Antetul arată lumânarea de sub cursor; fără cursor, ultima. */
  var subCursor = null;
  chart.subscribeCrosshairMove(function (p) {
    subCursor = (p.seriesData && p.seriesData.get(serieLum)) || null;
    scrieOhlc(subCursor || lumanari[lumanari.length - 1]);
  });
  function improspateazaAntet() {
    if (!subCursor) scrieOhlc(lumanari[lumanari.length - 1]);
    anuntaPretul();
  }

  /* Panoul lateral are nevoie de prețul curent ca să calculeze câștigul și
     poziția acului. Îl trimitem ca eveniment, ca cele două fișiere să rămână
     independente. */
  function anuntaPretul() {
    if (!lumanari.length) return;
    document.dispatchEvent(new CustomEvent("autobot:pret", {
      detail: lumanari[lumanari.length - 1].close
    }));
  }

  /* ---------- 1. istoricul commitat ---------- */

  function incarcaIstoric() {
    return fetch("data/" + SIMBOL + "-1h.json", { cache: "no-cache" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function (d) {
        var c = (d && d.candles) || [];
        if (!c.length) throw new Error("JSON fără lumânări");
        imbina(c);
        return c.length;
      });
  }

  /* ---------- 2. puntea prin REST ---------- */

  function kline(k) {
    return {
      time:   Math.floor(k[0] / 1000),
      open:   +k[1], high: +k[2], low: +k[3], close: +k[4],
      volume: +k[5]
    };
  }

  /** Cere klines de la `deLa` (ms) încoace, în pagini de câte 1000. */
  function aduRest(deLa) {
    var adunate = [];

    function pagina(start) {
      var u = REST + "?symbol=" + SIMBOL + "&interval=" + INTERVAL +
              "&limit=1000" + (start ? "&startTime=" + start : "");
      return fetch(u)
        .then(function (r) {
          if (!r.ok) throw new Error("Binance HTTP " + r.status);
          return r.json();
        })
        .then(function (ks) {
          if (!ks.length) return adunate;
          adunate = adunate.concat(ks.map(kline));
          var ultima = ks[ks.length - 1][0];
          // mai sunt pagini doar dacă am umplut limita și nu am ajuns în prezent
          if (ks.length === 1000 && ultima + ORA_MS < Date.now()) {
            return pagina(ultima + 1);
          }
          return adunate;
        });
    }

    return pagina(deLa);
  }

  /* ---------- 3. live prin WebSocket ---------- */

  var ws = null, incercare = 0, timerReconectare = null;

  function conecteaza() {
    clearTimeout(timerReconectare);
    stare("se conectează…", "asteapta");

    try { ws = new WebSocket(WS); }
    catch (e) { programeazaReconectare(); return; }

    ws.onopen = function () {
      incercare = 0;
      stare("live", "activ");
    };

    ws.onmessage = function (ev) {
      var m;
      try { m = JSON.parse(ev.data); } catch (e) { return; }
      var k = m.k;
      if (!k) return;

      var c = {
        time:   Math.floor(k.t / 1000),
        open:   +k.o, high: +k.h, low: +k.l, close: +k.c,
        volume: +k.v
      };

      var eNoua = indice[c.time] === undefined;
      imbina([c]);
      if (eNoua) {
        deseneazaTot();     // lumânare nouă: seria s-a lungit
        scrieRezumat();
      } else {
        actualizeazaUltima(c);
      }
      aplicaPrecizia();
      improspateazaAntet();
    };

    ws.onclose = function () { programeazaReconectare(); };
    ws.onerror = function () { try { ws.close(); } catch (e) {} };
  }

  function programeazaReconectare() {
    stare("reconectare…", "asteapta");
    var asteptare = Math.min(30000, 1000 * Math.pow(2, incercare++));
    timerReconectare = setTimeout(function () {
      // la revenire, recuperăm întâi ce s-a pierdut cât am fost deconectați
      resincronizeaza().then(conecteaza, conecteaza);
    }, asteptare);
  }

  /** Reia de la ultima lumânare cunoscută — folosit la reconectare și la
      revenirea în tab, când WebSocket-ul poate fi suspendat de browser. */
  function resincronizeaza() {
    if (!lumanari.length) return Promise.resolve();
    var deLa = lumanari[lumanari.length - 1].time * 1000;
    return aduRest(deLa)
      .then(function (noi) {
        if (!noi.length) return;
        if (imbina(noi)) { deseneazaTot(); scrieRezumat(); }
        else actualizeazaUltima(lumanari[lumanari.length - 1]);
        aplicaPrecizia();
        improspateazaAntet();
      })
      .catch(function () { /* rămânem pe ce avem */ });
  }

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) resincronizeaza();
  });

  /* ---------- pornire ---------- */

  incarcaIstoric()
    .then(function () {
      deseneazaTot();
      aplicaPrecizia();
      scrieRezumat();
      improspateazaAntet();
      elStare.hidden = true;

      incadreaza();   // ultimele ~30 de zile

      // puntea: de la ultima lumânare din JSON până în prezent
      stare("se aduc datele lipsă…", "asteapta");
      var deLa = lumanari[lumanari.length - 1].time * 1000;
      return aduRest(deLa).then(function (noi) {
        if (noi.length) {
          imbina(noi);
          deseneazaTot();
          aplicaPrecizia();
          scrieRezumat();
          improspateazaAntet();
          incadreaza();   // reîncadrăm după ce a sosit puntea
        }
        conecteaza();
      });
    })
    .catch(function (e) {
      // Fără istoric local nu putem desena nimic; cu el, dar fără Binance,
      // rămânem pe datele istorice și spunem asta clar.
      if (!lumanari.length) {
        elStare.textContent = "Nu s-au putut încărca datele (" + e.message + ").";
        elStare.classList.add("eroare");
        elStare.hidden = false;
      } else {
        stare("doar istoric", "inactiv");
      }
    });

  /* ---------- tema sistemului ---------- */

  window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function () {
    var n = tema();
    chart.applyOptions({
      layout: { background: { color: n.fundal }, textColor: n.text },
      grid:   { vertLines: { color: n.linii }, horzLines: { color: n.linii } },
      rightPriceScale: { borderColor: n.linii },
      timeScale: { borderColor: n.linii },
      crosshair: { vertLine: { color: n.cruce, labelBackgroundColor: n.cruce },
                   horzLine: { color: n.cruce, labelBackgroundColor: n.cruce } }
    });
  });
})();
