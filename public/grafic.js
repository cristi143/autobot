/* Grafic de lumânări 1h, în stilul TradingView.
   Datele vin din /data/<SIMBOL>-1h.json, generat de tools/agrega_1h.py. */

(function () {
  "use strict";

  var SIMBOL = "ZECUSDC";

  var elGrafic  = document.getElementById("grafic");
  var elStare   = document.getElementById("stare");
  var elOhlc    = document.getElementById("ohlc");
  var elRezumat = document.getElementById("rezumat");

  function tema() {
    var intunecat = window.matchMedia("(prefers-color-scheme: dark)").matches;
    return intunecat
      ? { fundal: "#161b22", text: "#8b949e", linii: "#262c36", cruce: "#4b5563" }
      : { fundal: "#ffffff", text: "#6b7280", linii: "#eceff3", cruce: "#9ca3af" };
  }

  function zecimale(pret) {
    if (pret >= 1000) return 2;
    if (pret >= 1)    return 2;
    if (pret >= 0.01) return 4;
    return 8;
  }

  function eroare(mesaj) {
    elStare.textContent = mesaj;
    elStare.classList.add("eroare");
    elStare.hidden = false;
  }

  if (typeof LightweightCharts === "undefined") {
    eroare("Nu s-a putut încărca biblioteca de grafice. Verifică conexiunea.");
    return;
  }

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

  var lumanari = chart.addCandlestickSeries({
    upColor: "#26a69a", downColor: "#ef5350",
    borderUpColor: "#26a69a", borderDownColor: "#ef5350",
    wickUpColor: "#26a69a", wickDownColor: "#ef5350"
  });

  var volum = chart.addHistogramSeries({
    priceFormat: { type: "volume" },
    priceScaleId: "volum",
    lastValueVisible: false,   // altfel ultima valoare a volumului
    priceLineVisible: false    // aterizează peste scala de preț
  });
  chart.priceScale("volum").applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });

  fetch("/data/" + SIMBOL + "-1h.json", { cache: "no-cache" })
    .then(function (r) {
      if (!r.ok) throw new Error("HTTP " + r.status);
      return r.json();
    })
    .then(function (d) {
      var c = d.candles || [];
      if (!c.length) throw new Error("fără lumânări");

      lumanari.setData(c);
      volum.setData(c.map(function (x) {
        return {
          time: x.time,
          value: x.volume,
          color: x.close >= x.open ? "rgba(38,166,154,.35)" : "rgba(239,83,80,.35)"
        };
      }));

      var z = zecimale(c[c.length - 1].close);
      lumanari.applyOptions({ priceFormat: { type: "price", precision: z, minMove: Math.pow(10, -z) } });

      // ultimele ~30 de zile la deschidere; restul rămâne accesibil prin scroll
      var de_la = Math.max(0, c.length - 24 * 30);
      chart.timeScale().setVisibleLogicalRange({ from: de_la, to: c.length });

      elStare.hidden = true;
      document.getElementById("simbol").textContent = d.symbol || SIMBOL;

      var prima = new Date(c[0].time * 1000), ultima = new Date(c[c.length - 1].time * 1000);
      var f = function (x) { return x.toISOString().slice(0, 10); };
      elRezumat.textContent = c.length.toLocaleString("ro-RO") + " lumânări · " +
                              f(prima) + " → " + f(ultima);

      function scrieOhlc(x) {
        if (!x) { elOhlc.textContent = ""; return; }
        var urcat = x.close >= x.open;
        var dif = x.open ? ((x.close - x.open) / x.open) * 100 : 0;
        elOhlc.innerHTML =
          ["O", x.open, "H", x.high, "L", x.low, "C", x.close]
            .map(function (v, i) {
              return i % 2 === 0
                ? '<span class="et">' + v + '</span>'
                : '<span class="vl">' + v.toFixed(z) + '</span>';
            }).join(" ") +
          ' <span class="dif ' + (urcat ? "sus" : "jos") + '">' +
          (urcat ? "+" : "") + dif.toFixed(2) + "%</span>";
      }

      scrieOhlc(c[c.length - 1]);
      chart.subscribeCrosshairMove(function (p) {
        var x = p.seriesData && p.seriesData.get(lumanari);
        scrieOhlc(x || c[c.length - 1]);
      });
    })
    .catch(function (e) {
      eroare("Nu s-au putut încărca datele (" + e.message + ").");
    });

  // urmează tema sistemului fără reîncărcare
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
