/**
 * Unealta manuală — pagina. Fără grafice, cerut explicit: doar cifre.
 *
 * Trei surse, fiecare cu ritmul ei:
 *   · `api/unealta.php`  — starea reală, scrisă de motor. La 5 secunde.
 *   · Binance REST       — lumânarea de 15m în formare, pentru prețul de acum.
 *                          Direct din browser: datele publice de piață permit
 *                          CORS și nu cer cheie API. Nimic de rulat pe server.
 *   · ce scrie omul      — trimis prin POST, cu cheia din localStorage.
 *
 * PRAGURILE NU SE CALCULEAZĂ AICI. Vin gata socotite din API, din aceleași
 * funcții pe care le folosește motorul (`unealta-reguli.php`). Refăcute în
 * JavaScript, pagina ar putea arăta un prag pe care motorul nu-l are — adică
 * ar minți fără să știe. Singurul calcul de aici e „cât ar ieși dacă ai ieși
 * acum”, care nu declanșează nimic.
 */
(function () {
  "use strict";

  /* Se schimbă LA FIECARE modificare din public/unealta/. `api/unealta.php` o
     citește de pe disc, iar pagina compară: diferite = browserul rulează cod
     vechi din cache, și banda de sus o spune. */
  var VERSIUNE = "2026-09-19-a";
  window.UNEALTA_VERSIUNE = VERSIUNE;

  var SIMBOL = "ZECUSDC";
  var CHEIE  = "autobot_cheie_api";   // aceeași cheie ca la botul vechi

  var date  = null;    // ultimul răspuns al API-ului
  var lum   = null;    // lumânarea de 15m în formare
  var editez = { long: false, short: false };

  function $(id) { return document.getElementById(id); }

  /* ---------------------------------------------------------- formatare */

  function nr(x, zecimale) {
    if (x === null || x === undefined || isNaN(x)) return "—";
    return Number(x).toLocaleString("ro-RO", {
      minimumFractionDigits: zecimale, maximumFractionDigits: zecimale
    });
  }
  function proc(x) {
    if (x === null || x === undefined || isNaN(x)) return "—";
    return (x >= 0 ? "+" : "") + nr(x, 2) + "%";
  }
  function clasaSemn(x) { return x > 0 ? "plus" : (x < 0 ? "minus" : ""); }

  function cand(ms) {
    if (!ms) return "—";
    var d = new Date(ms);
    return d.toLocaleString("ro-RO", {
      day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit"
    });
  }

  function deCand(ms) {
    if (!ms) return "niciodată";
    var s = Math.round((Date.now() - ms) / 1000);
    if (s < 90) return s + " secunde";
    var m = Math.round(s / 60);
    if (m < 90) return m + " minute";
    return Math.round(m / 60) + " ore";
  }

  /* ---------------------------------------------------------- Binance */

  function ceruPretul() {
    fetch("https://api.binance.com/api/v3/klines?symbol=" + SIMBOL + "&interval=15m&limit=1")
      .then(function (r) { return r.json(); })
      .then(function (k) {
        if (!k || !k.length) return;
        lum = {
          ora: k[0][0],
          deschidere: parseFloat(k[0][1]),
          maxim: parseFloat(k[0][2]),
          minim: parseFloat(k[0][3]),
          inchidere: parseFloat(k[0][4])
        };
        $("pret").textContent = nr(lum.inchidere, 2);
        $("pret-detaliu").textContent =
          "sfertul din " + cand(lum.ora) + " · max " + nr(lum.maxim, 2) + " · min " + nr(lum.minim, 2);
        deseneaza();
      })
      .catch(function () {
        $("pret-detaliu").textContent = "Binance nu răspunde";
      });
  }

  /* ---------------------------------------------------------- API-ul nostru */

  function ceruStarea() {
    fetch("/api/unealta.php", { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { throw new Error(d && d.eroare ? d.eroare : "răspuns neașteptat"); }
        date = d;
        deseneaza();
      })
      .catch(function (e) {
        arataBanda("Nu pot citi starea de pe server: " + e.message);
      });
  }

  function trimite(corp) {
    var cheie = ceruCheia();
    if (!cheie) return Promise.reject(new Error("Fără cheie, nu pot scrie nimic."));
    return fetch("/api/unealta.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Autobot-Cheie": cheie },
      body: JSON.stringify(corp)
    }).then(function (r) {
      return r.json().then(function (d) {
        // Cheia greșită nu se ține minte: altfel ar trebui golit localStorage-ul
        // de mână ca să mai poți încerca.
        if (r.status === 401) { try { localStorage.removeItem(CHEIE); } catch (e) {} }
        if (!r.ok || !d.ok) { throw new Error(d.eroare || ("HTTP " + r.status)); }
        return d;
      });
    });
  }

  function ceruCheia() {
    var c = null;
    try { c = localStorage.getItem(CHEIE); } catch (e) {}
    if (!c) {
      c = window.prompt("Cheia de scriere (aceeași ca la botul cu triunghiuri):");
      if (c) { try { localStorage.setItem(CHEIE, c.trim()); } catch (e) {} }
    }
    return c ? c.trim() : null;
  }

  /* ---------------------------------------------------------- banda de sus */

  function arataBanda(text) {
    var b = $("banda");
    b.textContent = text;
    b.hidden = !text;
  }

  function bandaDeStare() {
    if (!date) return;
    var probleme = [];

    if (date.versiune && date.versiune !== VERSIUNE) {
      probleme.push("Pagina din browser e mai veche decât cea de pe server ("
                  + VERSIUNE + " față de " + date.versiune + "). Reîncarcă forțat: Ctrl+F5.");
    }

    var ultima = date.motor && date.motor.ultima;
    if (!ultima) {
      probleme.push("Motorul n-a rulat niciodată — cronul e pornit?");
    } else if (Date.now() - ultima > 5 * 60 * 1000) {
      probleme.push("Motorul n-a mai rulat de " + deCand(ultima)
                  + ". Cât timp nu rulează, nimic nu se declanșează.");
    } else if (date.motor.rezultat === "eroare") {
      probleme.push("Ultima rulare a motorului s-a terminat cu eroare.");
    }

    arataBanda(probleme.join(" · "));
  }

  /* ---------------------------------------------------------- desenarea */

  function deseneaza() {
    if (!date) return;
    bandaDeStare();
    $("comision").textContent = nr(date.comision, 3);

    $("motor-linie").textContent = date.motor && date.motor.ultima
      ? "Motorul a rulat ultima dată acum " + deCand(date.motor.ultima)
        + " (" + cand(date.motor.ultima) + ")."
      : "Motorul n-a rulat încă.";

    deseneazaBanca("long", "l");
    deseneazaBanca("short", "s");
    deseneazaIstoric();
    deseneazaAnulate();
  }

  function deseneazaBanca(banca, pre) {
    var b = date.banci[banca] || { usdc: 0, zec: 0 };
    var s = date.setari[banca];
    var pornire = date.porniri[banca];

    /* ---- soldul, mereu în moneda de măsură ---- */
    var esteLong = banca === "long";
    var pret = lum ? lum.inchidere : null;
    // Banca de long se măsoară în USDC și când ține ZEC; cea de short în ZEC și
    // când stă pe USDC. Altfel cifra ar sări dintr-o monedă în alta la fiecare
    // intrare și n-ar mai exista niciun reper.
    var inMasura;
    if (esteLong) {
      inMasura = b.usdc > 0 ? b.usdc : (pret ? b.zec * pret : null);
    } else {
      inMasura = b.zec > 0 ? b.zec : (pret ? b.usdc / pret : null);
    }

    $(pre + "-sold").textContent = inMasura === null
      ? "—"
      : nr(inMasura, esteLong ? 2 : 4) + (esteLong ? " USDC" : " ZEC");

    var randEl = $(pre + "-randament");
    if (inMasura !== null && pornire) {
      var r = (inMasura / pornire - 1) * 100;
      randEl.textContent = proc(r) + " față de pornire";
      randEl.className = "sold-rand " + clasaSemn(r);
    } else {
      randEl.textContent = "";
      randEl.className = "sold-rand";
    }

    var tine = esteLong
      ? (b.usdc > 0 ? nr(b.usdc, 2) + " USDC" : nr(b.zec, 6) + " ZEC")
      : (b.zec > 0 ? nr(b.zec, 6) + " ZEC" : nr(b.usdc, 2) + " USDC");
    $(pre + "-sold-detaliu").textContent =
      "ține acum " + tine + " · a pornit cu "
      + (pornire ? nr(pornire, esteLong ? 2 : 4) + (esteLong ? " USDC" : " ZEC") : "—")
      + (inMasura !== null && (esteLong ? b.zec > 0 : b.usdc > 0)
         ? " · evaluat la prețul de acum" : "");

    /* ---- setarea, sau formularul ---- */
    var cutie = $(pre + "-stare");
    var form  = $(pre + "-form");

    if (!s || editez[banca]) {
      cutie.hidden = true;
      form.hidden  = false;
      form.querySelector('[data-act="renunta"]').hidden = !editez[banca];
      $(pre + "-trimite").textContent = editez[banca] ? "Salvează" : "Pornește setarea";

      // Cât ești în poziție, intrarea s-a întâmplat deja: câmpurile care o
      // descriu nu mai pot schimba nimic. Lăsate active, ar părea că da.
      var inPozitie = !!(s && s.pozitie);
      ["prag_intrare", "depasire_minima", "revenire"].forEach(function (c) {
        form.elements[c].disabled = editez[banca] && inPozitie;
      });
      form.querySelector(".formular-intro").textContent = (editez[banca] && inPozitie)
        ? "Ești în poziție: se mai pot muta doar ținta, urmărirea și stopul."
        : (esteLong
            ? "Prețul trebuie să coboare sub nivel și apoi să se întoarcă. Nu cumpărăm la atingere."
            : "Prețul trebuie să urce peste nivel și apoi să coboare înapoi. Atunci vindem ZEC-ul.");
      return;
    }

    form.hidden  = true;
    cutie.hidden = false;
    deseneazaSetare(s, pre, banca, pret);
  }

  /** Un rând de cifre: eticheta + valoarea, cu un detaliu mic opțional. */
  function rand(dl, eticheta, valoare, mic, clasa) {
    var dt = document.createElement("dt");
    dt.textContent = eticheta;
    var dd = document.createElement("dd");
    dd.textContent = valoare;
    if (clasa) dd.className = clasa;
    if (mic) {
      var sp = document.createElement("span");
      sp.className = "mic";
      sp.textContent = " " + mic;
      dd.appendChild(sp);
    }
    dl.appendChild(dt);
    dl.appendChild(dd);
  }

  function deseneazaSetare(s, pre, banca, pret) {
    var esteLong = banca === "long";
    var titlu = $(pre + "-stare-titlu");
    var dl = $(pre + "-cifre");
    dl.innerHTML = "";

    var poz = s.pozitie;
    var eInPozitie = s.stare === "in_pozitie" && !!poz;

    /* --- butoanele potrivite stării --- */
    var bAnul  = document.querySelector('[data-act="anuleaza"][data-banca="' + banca + '"]');
    var bIesi  = document.querySelector('[data-act="inchide"][data-banca="' + banca + '"]');
    bAnul.hidden = eInPozitie;
    bIesi.hidden = !eInPozitie;
    if (eInPozitie && poz.inchidere_ceruta) {
      bIesi.disabled = true;
      bIesi.textContent = "se închide la următoarea rulare…";
    } else {
      bIesi.disabled = false;
      bIesi.textContent = "Ieși acum";
    }

    /* --- nota utilizatorului: e a lui, se arată ca atare --- */
    var nota = $(pre + "-nota");
    nota.textContent = s.nota || "";
    nota.hidden = !s.nota;

    if (!eInPozitie) {
      /* ================= încă n-a intrat ================= */
      var armat = s.stare === "armat";
      titlu.innerHTML = '<span class="pastila' + (armat ? " viu" : "") + '">'
                      + (armat ? "armat" : "așteaptă") + "</span>"
                      + (armat
                         ? "A fost acolo. Aștept revenirea."
                         : "Aștept ca prețul să " + (esteLong ? "coboare" : "urce") + " destul.");

      rand(dl, "Nivelul tău", nr(s.prag_intrare, 2));

      if (!armat) {
        var dist = pret !== null ? (esteLong ? pret - s.prag_armare : s.prag_armare - pret) : null;
        // Prețul poate fi deja dincolo fără ca setarea să fie armată: armarea o
        // face motorul, pe lumânarea închisă. „De mers: −3” ar deruta.
        rand(dl, "Se armează la", nr(s.prag_armare, 2),
             dist === null ? null
               : (dist > 0 ? "(" + nr(dist, 2) + " de mers)"
                           : "(a ajuns — se armează la următoarea rulare)"));
      } else {
        rand(dl, "Extremul atins", nr(s.extrem, 2), "la " + cand(s.armat_la));
        var d2 = pret !== null ? (esteLong ? s.prag_efectiv - pret : pret - s.prag_efectiv) : null;
        rand(dl, "Intră la o închidere de 15m " + (esteLong ? "peste" : "sub"),
             nr(s.prag_efectiv, 2),
             d2 === null ? null : (d2 > 0 ? "(" + nr(d2, 2) + " de mers)" : "(prețul e deja dincolo)"));
      }

      // Câștigul brut al afacerii gândite, dacă intrarea s-ar face chiar la
      // nivel. Pentru short, raportul se inversează — se câștigă la scădere.
      var castig = esteLong
        ? (s.prag_iesire / s.prag_intrare - 1) * 100
        : (s.prag_intrare / s.prag_iesire - 1) * 100;
      rand(dl, "Ținta de ieșire", nr(s.prag_iesire, 2),
           "(" + proc(castig) + " brut, dacă ai intra fix la nivel)");
      rand(dl, "Urmărire", nr(s.urmarire, 2));
      rand(dl, "Stop", s.prag_stop === null ? "fără" : nr(s.prag_stop, 2));
      return;
    }

    /* ================= în poziție ================= */
    titlu.innerHTML = '<span class="pastila viu">în poziție</span>'
                    + (poz.tinta_atinsa
                       ? "Ținta a fost atinsă — urmărirea e pornită."
                       : "Aștept ținta.");

    rand(dl, "Intrat la", nr(poz.intrare, 2), cand(poz.intrare_ora));
    if (Math.abs(poz.intrare - s.prag_intrare) > 0.005) {
      rand(dl, "Nivelul gândit", nr(s.prag_intrare, 2),
           "(" + proc((poz.intrare / s.prag_intrare - 1) * 100) + " diferență)");
    }
    rand(dl, "Cantitate", nr(poz.cantitate, 6) + " ZEC");

    if (poz.tinta_atinsa) {
      rand(dl, esteLong ? "Maxim atins" : "Minim atins", nr(poz.extrem, 2));
      var d3 = pret !== null ? (esteLong ? pret - poz.prag_efectiv : poz.prag_efectiv - pret) : null;
      rand(dl, "Iese la o închidere " + (esteLong ? "sub" : "peste"), nr(poz.prag_efectiv, 2),
           d3 === null ? null : "(" + nr(Math.abs(d3), 2) + " distanță)");
    } else {
      var d4 = pret !== null ? (esteLong ? poz.prag_iesire - pret : pret - poz.prag_iesire) : null;
      rand(dl, "Ținta", nr(poz.prag_iesire, 2),
           d4 === null ? null : "(" + nr(Math.abs(d4), 2) + " de mers)");
      rand(dl, "Urmărire, după ce o atinge", nr(poz.urmarire, 2));
    }

    rand(dl, "Stop", poz.prag_stop === null ? "fără" : nr(poz.prag_stop, 2),
         poz.prag_stop === null ? "poziția așteaptă oricât" : "la atingere, nu pe închidere");

    /* Cât ar ieși dacă ai ieși chiar acum. Singurul calcul făcut în pagină, și
       nu declanșează nimic — e o întrebare, nu o regulă. */
    if (pret !== null) {
      var f = date.comision / 100;
      var raport = esteLong ? pret / poz.intrare : poz.intrare / pret;
      var net = (raport * (1 - f) * (1 - f) - 1) * 100;
      rand(dl, "Dacă ai ieși acum", proc(net), "net, după comisioane", clasaSemn(net));
    }
    rand(dl, "A mers până la", proc(poz.mfe) + " / " + proc(poz.mae),
         "în favoare / împotrivă");
  }

  function deseneazaIstoric() {
    var tb = $("istoric");
    tb.innerHTML = "";
    if (!date.istoric.length) {
      tb.innerHTML = '<tr><td colspan="7" class="gol">Încă nimic.</td></tr>';
      return;
    }
    date.istoric.forEach(function (p) {
      var tr = document.createElement("tr");
      function celula(text, clasa) {
        var td = document.createElement("td");
        td.textContent = text;
        if (clasa) td.className = clasa;
        tr.appendChild(td);
        return td;
      }
      celula(p.banca === "long" ? "Long" : "Short");
      celula(nr(p.intrare, 2) + " · " + cand(p.intrare_ora));
      celula(nr(p.iesire, 2) + " · " + cand(p.iesire_ora));
      celula({ urmarire: "urmărire", stop: "stop", manual: "manual" }[p.motiv] || p.motiv);
      celula(proc(p.rezultat), "nr " + clasaSemn(p.rezultat));
      celula(proc(p.mfe) + " / " + proc(p.mae), "nr");
      celula(p.nota || "", "nota-celula");
      tb.appendChild(tr);
    });
  }

  function deseneazaAnulate() {
    var ul = $("anulate");
    ul.innerHTML = "";
    $("anulate-cate").textContent = date.anulate.length ? "(" + date.anulate.length + ")" : "(niciuna)";
    date.anulate.forEach(function (a) {
      var li = document.createElement("li");
      var soarta = a.stare === "expirat"
        ? "stopul (" + nr(a.prag_stop, 2) + ") atins înainte de intrare — teza a picat"
        : "retrasă de tine";
      li.textContent = (a.banca === "long" ? "Long" : "Short") + " la " + nr(a.prag_intrare, 2)
                     + ", țintă " + nr(a.prag_iesire, 2)
                     + " — " + soarta + ", " + cand(a.incheiat_la)
                     + (a.nota ? " · " + a.nota : "");
      ul.appendChild(li);
    });
  }

  /* ---------------------------------------------------------- formularele */

  function citesteFormularul(form) {
    var d = {};
    ["prag_intrare", "depasire_minima", "revenire", "prag_iesire", "urmarire"].forEach(function (c) {
      d[c] = parseFloat(form.elements[c].value);
    });
    var stop = form.elements["prag_stop"].value.trim();
    d.prag_stop = stop === "" ? null : parseFloat(stop);
    d.nota = form.elements["nota"].value.trim();
    return d;
  }

  function umpleFormularul(form, s) {
    ["prag_intrare", "depasire_minima", "revenire", "prag_iesire", "urmarire"].forEach(function (c) {
      form.elements[c].value = s[c];
    });
    form.elements["prag_stop"].value = s.prag_stop === null ? "" : s.prag_stop;
    form.elements["nota"].value = s.nota || "";
  }

  function legaFormular(banca, pre) {
    var form = $(pre + "-form");
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var er = $(pre + "-eroare");
      er.hidden = true;

      var d = citesteFormularul(form);
      d.banca = banca;
      d.actiune = editez[banca] ? "modifica" : "creeaza";
      if (editez[banca]) { d.id = date.setari[banca].id; }

      var buton = $(pre + "-trimite");
      buton.disabled = true;

      trimite(d)
        .then(function (r) {
          editez[banca] = false;
          form.reset();
          if (r.atentie) { arataBanda(r.atentie); }
          ceruStarea();
        })
        .catch(function (ex) {
          er.textContent = ex.message;
          er.hidden = false;
        })
        .then(function () { buton.disabled = false; });
    });
  }

  /* ---------------------------------------------------------- butoanele */

  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-act]") : null;
    if (!b) return;
    var banca = b.getAttribute("data-banca");
    var pre   = banca === "long" ? "l" : "s";
    var act   = b.getAttribute("data-act");
    var s     = date && date.setari[banca];

    if (act === "editeaza" && s) {
      editez[banca] = true;
      umpleFormularul($(pre + "-form"), s.pozitie ? {
        // În poziție se pot schimba doar trei lucruri, și ele stau pe poziție,
        // nu pe setare: setarea e ce ai decis atunci, poziția e ce e valabil acum.
        prag_intrare: s.prag_intrare, depasire_minima: s.depasire_minima,
        revenire: s.revenire, prag_iesire: s.pozitie.prag_iesire,
        urmarire: s.pozitie.urmarire, prag_stop: s.pozitie.prag_stop, nota: s.nota
      } : s);
      deseneaza();
      return;
    }

    if (act === "renunta") {
      editez[banca] = false;
      $(pre + "-eroare").hidden = true;
      deseneaza();
      return;
    }

    if (act === "anuleaza" && s) {
      if (!window.confirm("Retragi setarea de " + banca + "? Rămâne în istoric ca decizie retrasă.")) return;
      trimite({ actiune: "anuleaza", id: s.id })
        .then(ceruStarea)
        .catch(function (ex) { arataBanda(ex.message); });
      return;
    }

    if (act === "inchide" && s && s.pozitie) {
      if (!window.confirm("Închizi poziția de " + banca + " la prețul de acum?")) return;
      trimite({ actiune: "inchide", id: s.pozitie.id })
        .then(function (r) { arataBanda(r.mesaj); ceruStarea(); })
        .catch(function (ex) { arataBanda(ex.message); });
    }
  });

  /* ---------------------------------------------------------- pornirea */

  legaFormular("long", "l");
  legaFormular("short", "s");

  ceruStarea();
  ceruPretul();
  setInterval(ceruStarea, 5000);
  setInterval(ceruPretul, 5000);

  // La revenirea în tab, cifrele pot fi vechi de minute bune: browserele
  // încetinesc temporizatoarele în taburile de fundal.
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) { ceruStarea(); ceruPretul(); }
  });
})();
