/* Panoul lateral: poziție, triunghi activ, bănci, istoric.
 *
 * Datele vin de la `api/stare.php`. Banda de sus spune și când a rulat ultima
 * dată motorul: fără asta, un cron mort ar arăta exact ca unul liniștit, care
 * n-a avut ce face.
 *
 * Prețul curent nu vine de la server, ci din grafic, prin evenimentul
 * `autobot:pret` — ca „Preț acum" și câștigul curent să se miște în timp real
 * între două citiri ale stării.
 */

(function () {
  "use strict";

  var REIMPROSPATARE_MS = 60000;

  var S = {
    pozitie:  { deschisa: false },
    triunghi: { exista: false },
    banci:    {},
    istoric:  [],
    motor:    { implementat: false }
  };

  var pretCurent = null;

  /* ---------- ajutoare ---------- */

  var $ = function (id) { return document.getElementById(id); };

  function pret(v) { return (v == null) ? "—" : Number(v).toFixed(2); }

  function proc(v, cuSemn) {
    if (v == null) return "—";
    return (cuSemn && v > 0 ? "+" : "") + Number(v).toFixed(2) + "%";
  }

  /** Momentele vin ca milisecunde UTC. Le arătăm tot în UTC, ca graficul. */
  function candUTC(ms, scurt) {
    if (!ms) return "—";
    var t = new Date(ms).toISOString();
    return scurt ? t.slice(5, 16).replace("T", " ") : t.slice(0, 16).replace("T", " ");
  }

  function semn(el, v) {
    el.classList.remove("sus", "jos");
    if (v > 0) el.classList.add("sus");
    else if (v < 0) el.classList.add("jos");
  }

  function gol(gazda, text) {
    gazda.textContent = "";
    var d = document.createElement("div");
    d.className = "gol";
    d.textContent = text;
    gazda.appendChild(d);
  }

  /* ---------- poziția ---------- */

  function redaPozitie() {
    var p = S.pozitie || { deschisa: false };
    var tip = $("poz-tip"), varsta = $("poz-varsta");

    if (!p.deschisa) {
      tip.textContent = "fără poziție";
      tip.className = "pastila goala";
      varsta.textContent = "aștept o spargere";
      ["poz-intrare", "poz-acum", "poz-tp", "poz-sl", "poz-pl"].forEach(function (id) {
        $(id).textContent = "—";
        $(id).classList.remove("sus", "jos");
      });
      $("poz-acum").textContent = pret(pretCurent);
      $("masura").hidden = true;
      return;
    }

    tip.textContent = p.tip.toUpperCase() + " deschis";
    tip.className = "pastila " + p.tip;
    varsta.textContent = "din " + candUTC(p.intrare_ms);

    $("poz-intrare").textContent = pret(p.intrare) + "  ·  " + Number(p.cantitate).toFixed(4) + " ZEC";
    $("poz-acum").textContent    = pret(pretCurent);

    var baza = (pretCurent == null) ? p.intrare : pretCurent;

    $("poz-tp").textContent = pret(p.tp) + "   " + proc(((p.tp - baza) / baza) * 100, true) + " de aici";

    var sageata = p.sl_acum > p.sl_ora_trecuta ? " ↑" : (p.sl_acum < p.sl_ora_trecuta ? " ↓" : "");
    var distSL = ((baza - p.sl_acum) / baza) * 100;
    $("poz-sl").textContent = pret(p.sl_acum) + sageata + "   −" + Math.abs(distSL).toFixed(2) + "% de aici";

    if (pretCurent != null) {
      var plProc = ((pretCurent - p.intrare) / p.intrare) * 100;
      if (p.tip === "short") plProc = -plProc;
      var plUsdc = (pretCurent - p.intrare) * p.cantitate * (p.tip === "short" ? -1 : 1);
      var el = $("poz-pl");
      el.textContent = proc(plProc, true) + "   " + (plUsdc >= 0 ? "+" : "") + plUsdc.toFixed(2) + " USDC";
      semn(el, plProc);

      var interval = p.tp - p.sl_acum;
      var poz = interval !== 0 ? ((pretCurent - p.sl_acum) / interval) * 100 : 50;
      $("masura-ac").style.left = Math.max(0, Math.min(100, poz)) + "%";
      $("masura-jos").textContent = "SL " + pret(p.sl_acum);
      $("masura-sus").textContent = "TP " + pret(p.tp);
      $("masura").hidden = false;
    }
  }

  /* ---------- triunghiul ---------- */

  function redaTriunghi() {
    var t = S.triunghi || { exista: false };
    $("triunghi-gol").hidden  = !!t.exista;
    $("triunghi-date").hidden = !t.exista;
    if (!t.exista) return;
    $("tri-sus").textContent  = pret(t.sus);
    $("tri-jos").textContent  = pret(t.jos);
    $("tri-cand").textContent = candUTC(t.desenat);
  }

  /* ---------- băncile ---------- */

  function redaBanci() {
    [["long", "bl"], ["short", "bs"]].forEach(function (par) {
      var b = S.banci[par[0]], pre = par[1];
      if (!b) {
        $(pre + "-stare").textContent = "—";
        $(pre + "-sold").textContent  = "—";
        $(pre + "-rand").textContent  = "—";
        $(pre + "-vs").textContent    = "—";
        return;
      }
      $(pre + "-stare").textContent = "în " + b.moneda;
      $(pre + "-sold").textContent  = (b.moneda === "ZEC")
        ? Number(b.sold_zec).toFixed(4) + " ZEC"
        : Number(b.sold_usdc).toFixed(2) + " USDC";
      // Randamentul și comparația cu „nu fac nimic" au nevoie de tranzacții
      // închise ca să însemne ceva. Până atunci, nu inventăm cifre.
      $(pre + "-rand").textContent = S.istoric.length ? proc(b.randament, true) : "—";
      $(pre + "-vs").textContent   = S.istoric.length ? proc(b.vs_hold, true)  : "—";
      semn($(pre + "-rand"), b.randament);
      semn($(pre + "-vs"),   b.vs_hold);
    });
  }

  /* ---------- istoricul ---------- */

  function redaIstoric() {
    var gazda = $("istoric");
    if (!S.istoric.length) {
      gol(gazda, "Nicio tranzacție încă.");
      return;
    }
    gazda.textContent = "";
    S.istoric.forEach(function (t) {
      var r = document.createElement("div");
      r.className = "tranz";

      var c = document.createElement("span");
      c.className = "cand"; c.textContent = candUTC(t.cand, true);

      var m = document.createElement("span");
      m.className = "motiv"; m.textContent = t.motiv + " · " + t.banca;

      var v = document.createElement("span");
      v.className = "rez";
      if (t.rezultat == null) { v.textContent = "—"; }
      else { v.textContent = proc(t.rezultat, true); semn(v, t.rezultat); }

      r.appendChild(c); r.appendChild(m); r.appendChild(v);
      gazda.appendChild(r);
    });
  }

  /* ---------- citirea stării ---------- */

  function redaTot() { redaPozitie(); redaTriunghi(); redaBanci(); redaIstoric(); }

  function citesteStarea() {
    return fetch("api/stare.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.eroare || "răspuns nevalid");
        S = {
          pozitie:  d.pozitie  || { deschisa: false },
          triunghi: d.triunghi || { exista: false },
          banci:    d.banci    || {},
          istoric:  d.istoric  || [],
          motor:    d.motor    || { implementat: false }
        };
        var av = document.querySelector(".avertisment");
        if (av) {
          var u = S.motor.ultima_rulare;
          if (S.motor.intarziat) {
            // Fără asta, un motor mort ar arăta ca unul liniștit.
            av.textContent = u
              ? "Motorul n-a mai rulat din " + candUTC(u.pornit_la) + " UTC — verifică cronul"
              : "Motorul n-a rulat niciodată — verifică cronul";
            av.classList.add("rau");
          } else {
            av.textContent = "Simulare cu bani fictivi · ultima verificare " +
                             candUTC(u.pornit_la) + " UTC";
            av.classList.remove("rau");
          }
        }
        redaTot();

        // Graficul desenează linia de SL și pragul de TP; triunghiul consumat
        // nu se mai afișează singur, iar linia lui rămâne pragul de ieșire.
        document.dispatchEvent(new CustomEvent("autobot:pozitie", {
          detail: S.pozitie && S.pozitie.deschisa ? {
            tip: S.pozitie.tip, linie: S.pozitie.sl_linie, tp: S.pozitie.tp
          } : null
        }));
      })
      .catch(function (e) {
        var av = document.querySelector(".avertisment");
        if (av) av.textContent = "Nu pot citi starea de la server: " + e.message;
        redaTot();
      });
  }

  /* ---------- legături ---------- */

  document.addEventListener("autobot:pret", function (ev) {
    pretCurent = ev.detail;
    redaPozitie();
  });

  // după ce se salvează sau se șterge un triunghi, starea s-a schimbat
  document.addEventListener("autobot:triunghiuri-schimbate", citesteStarea);

  redaTot();
  citesteStarea();
  setInterval(citesteStarea, REIMPROSPATARE_MS);
})();
