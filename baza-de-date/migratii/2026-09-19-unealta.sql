-- ============================================================================
-- Unealta de tranzacționare manuală — tabelele proprii.
--
-- SE RULEAZĂ O SINGURĂ DATĂ, din cPanel → phpMyAdmin → baza marcelpa_autobot →
-- tabul SQL, ÎNAINTE de deploy-ul codului. Nu există sistem de migrații; dacă
-- se ajunge invers, motorul uneltei pică la prima rulare, pe un tabel care nu
-- există.
--
-- E sigur de rulat cu botul vechi pornit: nu atinge niciunul dintre cele opt
-- tabele existente. Prefixul `u_` e tocmai ca să nu se confunde niciodată —
-- sunt două sisteme separate, cu bani separați, în aceeași bază.
--
-- Convențiile bazei rămân cele din schema.sql, din aceleași motive:
--   · TIMPUL e BIGINT, milisecunde UTC. Serverul e pe Europe/Bucharest, Binance
--     pe UTC, iar ora de vară mută diferența între 2 și 3 ore.
--   · PREȚURILE sunt DECIMAL, niciodată FLOAT. Aici se socotesc bani.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

USE marcelpa_autobot;


-- ---------------------------------------------------------------------------
-- Setarea — tot ce a decis utilizatorul, într-un singur rând.
--
-- CELE TREI DISTANȚE FAC LUCRURI DIFERITE, și e ușor de confundat:
--   depasire_minima — cât trebuie să treacă prețul DINCOLO de prag ca setarea
--                     să se armeze. Dovada că a fost acolo.
--   revenire        — cât trebuie să se întoarcă DE LA EXTREM ca să intrăm.
--                     Pragul efectiv de intrare = extrem ± revenire, plafonat
--                     la prag_intrare. Doar se depărtează de nivelul setat.
--   urmarire        — distanța sub (peste) maximul atins, la care stă pragul de
--                     ieșire după ce ținta a fost atinsă. Doar urcă (coboară).
--
-- STĂRILE:
--   asteapta   — prețul n-a ajuns încă destul de departe
--   armat      — a ajuns; se așteaptă revenirea
--   in_pozitie — s-a intrat; există un rând în u_pozitii
--   inchisa    — poziția s-a închis, setarea și-a trăit viața
--   anulata    — utilizatorul a retras-o înainte de intrare
--   expirat    — prețul a atins STOPUL înainte de intrare
--
-- `anulata` e distinctă de `expirat` din același motiv pentru care botul vechi
-- ține `sters` separat de `expirat`: una e decizia omului, cealaltă e verdictul
-- pieței. Contopite, istoricul n-ar mai spune nimic.
--
-- DE CE EXISTĂ `expirat`, ȘI DE CE E OBLIGATORIU: pragul de intrare urmărește
-- extremul, deci intrarea poate ajunge mult sub nivelul gândit — inclusiv SUB
-- stop, care e o valoare absolută. S-a văzut pe date reale: nivel 1450, stop
-- 1380, prețul cade la 1200, intrarea se face la 1227 și „stopul” de la 1380 se
-- declanșează imediat, pe profit. Absurd. Un stop atins înainte de intrare
-- înseamnă că teza a fost infirmată; atunci nu se mai cumpără nimic.
--
-- O SINGURĂ SETARE ACTIVĂ PE BANCĂ. MySQL nu are indecși unici parțiali, deci
-- regula e ținută de API și de motor, nu de bază. Dacă vreodată apar două,
-- motorul le ia în ordinea creării și o va folosi pe prima.
--
-- `ultima_lumanare` = ultima lumânare de 15m DEJA procesată pentru setarea asta.
-- La creare se pune lumânarea dinaintea primei care se deschide după acel
-- moment: o lumânare începută înainte ca utilizatorul să decidă conține minime
-- care nu-i aparțin.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_setari (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    banca           ENUM('long','short') NOT NULL,
    stare           ENUM('asteapta','armat','in_pozitie','inchisa','anulata','expirat')
                    NOT NULL DEFAULT 'asteapta',

    prag_intrare    DECIMAL(20,8) NOT NULL COMMENT 'unde crede utilizatorul că ajunge prețul',
    depasire_minima DECIMAL(20,8) NOT NULL COMMENT 'cât trebuie să treacă dincolo, ca să se armeze',
    revenire        DECIMAL(20,8) NOT NULL COMMENT 'cât trebuie să revină de la extrem, ca să intrăm',
    prag_iesire     DECIMAL(20,8) NOT NULL COMMENT 'ținta, absolută — nu se mută cu prețul real de intrare',
    urmarire        DECIMAL(20,8) NOT NULL COMMENT 'distanța sub maximul atins, după ce ținta e atinsă',
    prag_stop       DECIMAL(20,8) NULL     COMMENT 'NULL = fără stop; poziția așteaptă oricât',
    nota            VARCHAR(255)  NULL     COMMENT 'ce a văzut utilizatorul aici — motorul nu o atinge',

    extrem          DECIMAL(20,8) NULL COMMENT 'minimul (long) sau maximul (short) atins de la armare',
    armat_la        BIGINT        NULL,
    ultima_lumanare BIGINT        NOT NULL COMMENT 'ultima lumânare de 15m procesată (ms UTC)',

    creat_la        BIGINT        NOT NULL,
    actualizat_la   BIGINT        NOT NULL,
    incheiat_la     BIGINT        NULL COMMENT 'anulare sau închiderea poziției',

    PRIMARY KEY (id),
    KEY idx_banca_stare (banca, stare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Pozițiile deschise de unealtă.
--
-- DE CE SE COPIAZĂ PRAGURILE DIN SETARE: utilizatorul poate modifica ținta,
-- urmărirea sau stopul CÂT E ÎN POZIȚIE. Dacă motorul ar citi setarea, o
-- modificare ar rescrie retroactiv și povestea unei poziții deja închise. Aici
-- stă ce era valabil pentru poziția asta.
--
-- `inchidere_ceruta` e butonul „ieși acum": API-ul doar ridică steagul, motorul
-- închide la următoarea rulare, în cel mult 60 de secunde. Singurul cod care
-- atinge banii e motorul — două scrieri pe aceeași poziție s-ar putea ciocni.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_pozitii (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setare_id       INT UNSIGNED NOT NULL,
    banca           ENUM('long','short') NOT NULL,
    stare           ENUM('deschisa','inchisa') NOT NULL DEFAULT 'deschisa',

    intrare_ora     BIGINT        NOT NULL COMMENT 'închiderea lumânării care a declanșat',
    intrare_pret    DECIMAL(20,8) NOT NULL COMMENT 'prețul acelei închideri, nu pragul',
    cantitate       DECIMAL(30,8) NOT NULL COMMENT 'ZEC cumpărat (long) sau vândut (short)',

    prag_iesire     DECIMAL(20,8) NOT NULL COMMENT 'ținta, copiată la intrare',
    urmarire        DECIMAL(20,8) NOT NULL,
    prag_stop       DECIMAL(20,8) NULL,

    tinta_atinsa    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 după ce prețul a atins ținta o dată',
    extrem          DECIMAL(20,8) NULL COMMENT 'maximul (long) / minimul (short) de la atingerea țintei',
    inchidere_ceruta TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'butonul „ieși acum"',

    iesire_ora      BIGINT        NULL,
    iesire_pret     DECIMAL(20,8) NULL,
    motiv_iesire    ENUM('urmarire','stop','manual') NULL,

    comision_total  DECIMAL(20,8) NOT NULL DEFAULT 0 COMMENT 'ambele părți, în USDC',
    rezultat_proc   DECIMAL(10,4) NULL COMMENT 'net, după comisioane',

    -- Maxime de pe tot drumul: după închidere nu se mai pot afla. Aceleași
    -- întrebări ca la botul vechi — ar fi ajutat o urmărire mai strânsă? cât
    -- s-a lăsat pe masă? — la care altfel se răspunde din amintiri.
    mfe_proc        DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'cel mai mult în favoare',
    mae_proc        DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'cel mai adânc în minus',

    PRIMARY KEY (id),
    KEY idx_stare (stare),
    CONSTRAINT fk_u_pozitie_setare FOREIGN KEY (setare_id) REFERENCES u_setari (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Băncile uneltei. Două rânduri, mereu. Separate de cele ale botului vechi.
-- Banca de long se măsoară în USDC, cea de short în ZEC — și se AFIȘEAZĂ mereu
-- în moneda de măsură, chiar când stau în cealaltă.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_banci (
    banca          ENUM('long','short') NOT NULL,
    sold_usdc      DECIMAL(30,8) NOT NULL DEFAULT 0,
    sold_zec       DECIMAL(30,8) NOT NULL DEFAULT 0,
    actualizat_la  BIGINT        NOT NULL,
    PRIMARY KEY (banca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Registrul de mișcări — fiecare schimbare de sold, cu soldul rezultat.
-- Redundant față de u_pozitii, intenționat: dacă un calcul iese greșit, aici se
-- vede exact unde s-a rupt lanțul.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_miscari (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    banca          ENUM('long','short') NOT NULL,
    pozitie_id     INT UNSIGNED NULL,
    moment         BIGINT       NOT NULL,
    fel            ENUM('initializare','intrare','iesire') NOT NULL,
    pret           DECIMAL(20,8) NULL,
    cantitate_zec  DECIMAL(30,8) NULL,
    comision       DECIMAL(20,8) NOT NULL DEFAULT 0,
    sold_usdc_dupa DECIMAL(30,8) NOT NULL,
    sold_zec_dupa  DECIMAL(30,8) NOT NULL,
    explicatie     VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_banca_moment (banca, moment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Lumânările de 15m, așa cum le-a văzut serverul. Copie proprie, ca orice
-- decizie să poată fi refăcută fără să depindem de Binance.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_lumanari_15m (
    ora_deschidere BIGINT        NOT NULL COMMENT 'ms de la epocă, UTC — cheia',
    deschidere     DECIMAL(20,8) NOT NULL,
    maxim          DECIMAL(20,8) NOT NULL,
    minim          DECIMAL(20,8) NOT NULL,
    inchidere      DECIMAL(20,8) NOT NULL,
    volum          DECIMAL(30,8) NOT NULL,
    adaugat_la     BIGINT        NOT NULL,
    PRIMARY KEY (ora_deschidere)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Jurnalul cronului uneltei. Separat de `jurnal_cron`, ca un cron mort să nu
-- fie confundat cu celălalt. Fără el, „n-a avut ce face" arată identic cu
-- „scriptul a murit acum trei zile".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS u_jurnal (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pornit_la      BIGINT       NOT NULL,
    durata_ms      INT UNSIGNED NULL,
    ultima_lumanare BIGINT      NULL COMMENT 'ultima lumânare de 15m procesată la rularea asta',
    lumanari       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'câte lumânări noi a prins',
    rezultat       ENUM('ok','fara_date','eroare') NOT NULL,
    detaliu        TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_pornit (pornit_la)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- Cifre rotunde, alese ca randamentul să se citească direct din sold: cu 1 ZEC
-- la pornire, un sold de 1,0086 ZEC înseamnă +0,86% fără niciun calcul.
--
-- Spre deosebire de botul vechi, banca de short primește ZEC-ul DIRECT, nu prin
-- conversie la prima rulare. Nu e nevoie de prețul de atunci, deci nu e nevoie
-- nici de pasul de inițializare care a creat acolo capcana cu reinițializarea
-- peste o poziție deschisă.
-- ---------------------------------------------------------------------------
INSERT INTO u_banci (banca, sold_usdc, sold_zec, actualizat_la) VALUES
    ('long',  1000.00000000, 0.00000000, UNIX_TIMESTAMP() * 1000),
    ('short',    0.00000000, 1.00000000, UNIX_TIMESTAMP() * 1000)
ON DUPLICATE KEY UPDATE banca = banca;

-- Rândurile de pornire în registru, dar numai dacă registrul e gol — altfel o a
-- doua rulare din greșeală ar adăuga încă două „finanțări".
INSERT INTO u_miscari
    (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
     sold_usdc_dupa, sold_zec_dupa, explicatie)
SELECT 'long', NULL, UNIX_TIMESTAMP() * 1000, 'initializare', NULL, NULL, 0,
       1000.00000000, 0.00000000, 'finanțare inițială: 1000 USDC'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM u_miscari);

INSERT INTO u_miscari
    (banca, pozitie_id, moment, fel, pret, cantitate_zec, comision,
     sold_usdc_dupa, sold_zec_dupa, explicatie)
SELECT 'short', NULL, UNIX_TIMESTAMP() * 1000, 'initializare', NULL, 1.00000000, 0,
       0.00000000, 1.00000000, 'finanțare inițială: 1 ZEC'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM u_miscari WHERE banca = 'short');

-- Verificare, după rulare:
--   SHOW TABLES LIKE 'u\_%';        -- trebuie să apară 6 tabele
--   SELECT * FROM u_banci;          -- long 1000 USDC · short 1 ZEC
--   SELECT * FROM u_miscari;        -- exact două rânduri de inițializare
