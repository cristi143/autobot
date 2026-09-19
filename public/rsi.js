/* RSI(14) — panou propriu, sub graficul de lumânări.
 *
 * lightweight-charts 4.x nu are panouri (au apărut în v5), deci panoul e un al
 * doilea grafic, lipit dedesubt și ținut în pas cu primul: aceeași fereastră de
 * timp, aceeași cruce, aceeași lățime de scală. Graficul de sus își ascunde axa
 * de timp; ora se citește o singură dată, aici jos.
 *
 * Alinierea se face pe indici LOGICI, nu pe timp — de aceea seria de RSI are
 * câte un punct pentru FIECARE lumânare, chiar și pentru primele 14, unde RSI-ul
 * încă nu există: acolo se trimit puncte goale (doar `time`). Fără ele, indicele
 * 0 al panoului ar cădea peste lumânarea 14 și cele două grafice ar fi decalate.
 *
 * Formula e cea a lui Wilder (netezire exponențială cu 1/14), ca la TradingView.
 */

(function () {
  "use strict";

  var PERIOADA = 14;
  var SUS = 70, JOS = 30;

  var A = window.Autobot;
  if (!A || typeof LightweightCharts === "undefined") return;

  var gazda  = document.getElementById("rsi");
  var elVal  = document.getElementById("rsi-valoare");
  if (!gazda) return;

  var t = A.tema();

  /* ---------- graficul ---------- */

  var chart = LightweightCharts.createChart(gazda, {
    layout:     { background: { color: t.fundal }, textColor: t.text, fontSize: 11 },
    grid:       { vertLines: { color: t.linii }, horzLines: { visible: false } },
    rightPriceScale: { borderColor: t.linii, scaleMargins: { top: 0.12, bottom: 0.12 } },
    timeScale:  { borderColor: t.linii, timeVisible: true, secondsVisible: false },
    crosshair:  {
      mode: LightweightCharts.CrosshairMode.Normal,
      vertLine: { color: t.cruce, width: 1, style: 3, labelBackgroundColor: t.cruce },
      horzLine: { color: t.cruce, width: 1, style: 3, labelBackgroundColor: t.cruce }
    },
    localization: { locale: "ro-RO" },
    autoSize: true
  });

  var CULOARE = "#7e57c2";

  var serie = chart.addLineSeries({
    color: CULOARE,
    lineWidth: 2,
    priceLineVisible: false,
    /* Scala rămâne fixă 0–100. Altfel s-ar auto-încadra pe valorile vizibile, iar
       liniile de 70 și 30 ar sări de la o fereastră la alta — exact reperele care
       trebuie să stea pe loc. */
    autoscaleInfoProvider: function () {
      return { priceRange: { minValue: 0, maxValue: 100 } };
    },
    priceFormat: { type: "price", precision: 0, minMove: 1 }
  });

  /* ---------- reperele: 70, 50, 30 ---------- */

  function reper(pret, culoare, stil) {
    serie.createPriceLine({
      price: pret,
      color: culoare,
      lineWidth: 1,
      lineStyle: stil,
      axisLabelVisible: true,
      title: ""
    });
  }

  reper(SUS, "#ef5350", LightweightCharts.LineStyle.Dashed);   // supracumpărat
  reper(50,  t.linii,   LightweightCharts.LineStyle.Dotted);   // mijlocul
  reper(JOS, "#26a69a", LightweightCharts.LineStyle.Dashed);   // supravândut

  /* Banda dintre 70 și 30 — zona în care RSI-ul nu spune nimic. Se desenează pe
     o pânză proprie, peste grafic: biblioteca știe să tragă linii, nu să umple
     între ele. Transparența e mică tocmai ca linia să rămână curată dedesubt. */

  var panza = document.createElement("canvas");
  panza.className = "rsi-banda";
  gazda.appendChild(panza);
  var ctx = panza.getContext("2d");

  function latimeUtila() {
    var scala = 0;
    try { scala = chart.priceScale("right").width() || 0; } catch (e) {}
    return Math.max(0, gazda.clientWidth - scala);
  }

  /* Banda depinde doar de mărimea panoului (scala e fixă 0–100), nu de fereastra
     de timp. Redesenăm doar când chiar s-a schimbat ceva: altfel am reface pânza
     la fiecare cadru de derulare, degeaba. */
  var ultima = "";

  function deseneazaBanda() {
    var w = gazda.clientWidth, h = gazda.clientHeight;
    if (!w || !h) return;

    var lat = latimeUtila();
    var semnatura = w + "x" + h + "x" + lat;
    if (semnatura === ultima) return;
    ultima = semnatura;

    var r = Math.max(1, window.devicePixelRatio || 1);
    panza.width  = Math.round(w * r);
    panza.height = Math.round(h * r);
    panza.style.width  = w + "px";
    panza.style.height = h + "px";
    ctx.setTransform(r, 0, 0, r, 0, 0);
    ctx.clearRect(0, 0, w, h);

    var ySus = serie.priceToCoordinate(SUS);
    var yJos = serie.priceToCoordinate(JOS);
    if (ySus == null || yJos == null) { ultima = ""; return; }

    ctx.fillStyle = "rgba(126,87,194,.07)";
    ctx.fillRect(0, ySus, lat, yJos - ySus);
  }

  /* ---------- calculul ---------- */

  var val       = [];   // punctele seriei (cu goluri pe primele PERIOADA)
  var mediiC    = [];   // media câștigurilor, per indice de lumânare
  var mediiP    = [];   // media pierderilor
  var laTimp    = {};   // time -> RSI
  var inchideri = {};   // time -> close (pentru crucea trimisă înapoi în sus)
  var nLum      = 0;    // câte lumânări aveam la ultimul calcul complet

  /** Un RSI plat (nici câștig, nici pierdere) e 50 prin convenție: nici într-o
      parte, nici în alta. Fără pierderi deloc înseamnă 100. */
  function formula(mc, mp) {
    if (mp === 0) return mc === 0 ? 50 : 100;
    return 100 - 100 / (1 + mc / mp);
  }

  function calculTot(lum) {
    val = []; mediiC = []; mediiP = []; laTimp = {}; inchideri = {};
    var sumaC = 0, sumaP = 0;

    for (var i = 0; i < lum.length; i++) {
      inchideri[lum[i].time] = lum[i].close;
      mediiC[i] = 0; mediiP[i] = 0;

      if (i === 0) { val.push({ time: lum[0].time }); continue; }

      var d = lum[i].close - lum[i - 1].close;
      var c = d > 0 ?  d : 0;
      var p = d < 0 ? -d : 0;

      if (i < PERIOADA) {
        sumaC += c; sumaP += p;
        val.push({ time: lum[i].time });      // punct gol: ține indicele logic
        continue;
      }
      if (i === PERIOADA) {
        sumaC += c; sumaP += p;
        mediiC[i] = sumaC / PERIOADA;         // prima medie e una simplă
        mediiP[i] = sumaP / PERIOADA;
      } else {
        mediiC[i] = (mediiC[i - 1] * (PERIOADA - 1) + c) / PERIOADA;
        mediiP[i] = (mediiP[i - 1] * (PERIOADA - 1) + p) / PERIOADA;
      }

      var v = formula(mediiC[i], mediiP[i]);
      val.push({ time: lum[i].time, value: v });
      laTimp[lum[i].time] = v;
    }
    nLum = lum.length;
  }

  /** Recalculează doar lumânarea în formare, plecând de la mediile celei
      dinaintea ei. Vine un mesaj de WebSocket la câteva secunde — n-are rost
      refăcut tot șirul de ~7000 de puncte de fiecare dată. */
  function doarUltima(lum) {
    var i = lum.length - 1;
    var d = lum[i].close - lum[i - 1].close;
    var c = d > 0 ?  d : 0;
    var p = d < 0 ? -d : 0;

    mediiC[i] = (mediiC[i - 1] * (PERIOADA - 1) + c) / PERIOADA;
    mediiP[i] = (mediiP[i - 1] * (PERIOADA - 1) + p) / PERIOADA;

    var v = formula(mediiC[i], mediiP[i]);
    laTimp[lum[i].time] = v;
    inchideri[lum[i].time] = lum[i].close;
    val[val.length - 1] = { time: lum[i].time, value: v };
    return val[val.length - 1];
  }

  function reia() {
    var lum = A.lumanari();
    if (lum.length < PERIOADA + 2) return;

    // Recalcul complet la orice schimbare de lungime; incremental cât timp se
    // mișcă doar ultima lumânare. Sub PERIOADA+1 formula recursivă n-are de unde
    // porni, deci acolo tot complet.
    if (lum.length !== nLum) {
      calculTot(lum);
      serie.setData(val);
    } else {
      serie.update(doarUltima(lum));
    }

    aliniazaScale();
    deseneazaBanda();
    requestAnimationFrame(deseneazaBanda);   // scala poate fi abia acum lățită
    if (!subCursor) scrieValoarea(laTimp[lum[lum.length - 1].time]);
  }

  /* ---------- legenda ---------- */

  function scrieValoarea(v) {
    if (!elVal) return;
    if (v == null) { elVal.textContent = "—"; elVal.className = "vl"; return; }
    elVal.textContent = v.toFixed(1);
    elVal.className = "vl" + (v >= SUS ? " sus" : v <= JOS ? " jos" : "");
  }

  /* ---------- sincronizarea cu graficul de sus ---------- */

  /* Cele două grafice se ascultă reciproc, deci fiecare mutare ar putea porni
     una înapoi, la nesfârșit. Steagul taie întoarcerea. */
  var sincronizare = false;

  function leaga(de_la, la) {
    de_la.timeScale().subscribeVisibleLogicalRangeChange(function (r) {
      if (!r || sincronizare) return;
      sincronizare = true;
      try { la.timeScale().setVisibleLogicalRange(r); } catch (e) {}
      sincronizare = false;
    });
  }

  leaga(A.chart, chart);
  leaga(chart, A.chart);

  /* Crucea: aceeași grijă, plus valoarea afișată în legendă. */
  var subCursor = false;
  var cruce = false;

  A.chart.subscribeCrosshairMove(function (p) {
    if (cruce) return;
    cruce = true;
    if (!p || p.time === undefined) {
      subCursor = false;
      try { chart.clearCrosshairPosition(); } catch (e) {}
      var lum = A.lumanari();
      scrieValoarea(lum.length ? laTimp[lum[lum.length - 1].time] : null);
    } else {
      subCursor = true;
      var v = laTimp[p.time];
      scrieValoarea(v);
      try {
        if (v == null) chart.clearCrosshairPosition();
        else chart.setCrosshairPosition(v, p.time, serie);
      } catch (e) {}
    }
    cruce = false;
  });

  chart.subscribeCrosshairMove(function (p) {
    if (cruce) return;
    cruce = true;
    if (!p || p.time === undefined) {
      try { A.chart.clearCrosshairPosition(); } catch (e) {}
    } else {
      scrieValoarea(laTimp[p.time]);
      var inch = inchideri[p.time];
      try {
        if (inch == null) A.chart.clearCrosshairPosition();
        else A.chart.setCrosshairPosition(inch, p.time, A.serie);
      } catch (e) {}
    }
    cruce = false;
  });

  /* Mutările din panou opresc reîncadrarea automată, ca cele de pe lumânări. */
  ["wheel", "mousedown", "touchstart"].forEach(function (ev) {
    gazda.addEventListener(ev, function () {
      if (A.interactiuneUmana) A.interactiuneUmana();
    }, { passive: true });
  });

  /* Scalele de preț au lățimi diferite ("345.67" față de "70"), iar din ele iese
     lățimea zonei de desen. Diferite, cele două grafice ar fi decalate pe
     orizontală cu câțiva pixeli. Le ducem pe amândouă la cea mai lată; maximul
     nu scade, deci nu oscilează. */
  function aliniazaScale() {
    try {
      var a = A.chart.priceScale("right").width();
      var b = chart.priceScale("right").width();
      var m = Math.max(a || 0, b || 0);
      if (!m) return;
      A.chart.applyOptions({ rightPriceScale: { minimumWidth: m } });
      chart.applyOptions({ rightPriceScale: { minimumWidth: m } });
    } catch (e) { /* versiune fără minimumWidth: rămân cum sunt */ }
  }

  /* ---------- temă ---------- */

  document.addEventListener("autobot:tema", function (ev) {
    var n = ev.detail;
    chart.applyOptions({
      layout: { background: { color: n.fundal }, textColor: n.text },
      grid:   { vertLines: { color: n.linii } },
      rightPriceScale: { borderColor: n.linii },
      timeScale: { borderColor: n.linii },
      crosshair: { vertLine: { color: n.cruce, labelBackgroundColor: n.cruce },
                   horzLine: { color: n.cruce, labelBackgroundColor: n.cruce } }
    });
    ultima = "";
    deseneazaBanda();
  });

  /* ---------- pornire ---------- */

  document.addEventListener("autobot:pret", reia);
  window.addEventListener("resize", deseneazaBanda);
  if (window.ResizeObserver) new ResizeObserver(deseneazaBanda).observe(gazda);
  chart.timeScale().subscribeVisibleLogicalRangeChange(deseneazaBanda);

  reia();
})();
