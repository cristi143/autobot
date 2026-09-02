-- ============================================================================
-- Două adăugiri pentru analiza de la etapa 4. Niciuna nu se poate completa
-- retroactiv, de aceea se fac devreme.
--
-- 1. STARE PROPRIE PENTRU EXPIRARE
--    Un triunghi expirat la vârf și unul șters de utilizator ajungeau amândouă
--    în `sters`, deosebite doar prin textul din `nota`. Sunt însă lucruri
--    diferite: expirarea e verdictul pieței („am văzut un tipar, nu s-a
--    confirmat"), ștergerea e o decizie a utilizatorului („am tras greșit").
--    Amândouă sunt exemple negative valoroase, dar din motive diferite.
--
-- 2. FEREASTRA VIZIBILĂ LA DESENARE
--    Dacă graficul era mărit pe trei zile sau depărtat pe treizeci, „vârfurile
--    evidente" sunt cu totul altele. Fără ea, am analiza tiparul fără să știm
--    la ce se uita omul.
--
-- Se rulează o dată, în phpMyAdmin, cu baza selectată în stânga.
-- ============================================================================

ALTER TABLE triunghiuri
    MODIFY stare ENUM('activ','consumat','expirat','sters') NOT NULL DEFAULT 'activ';

ALTER TABLE triunghiuri
    ADD COLUMN fereastra_de_la  BIGINT NULL COMMENT 'ms UTC, marginea stângă a graficului la desenare'
        AFTER desenat_la,
    ADD COLUMN fereastra_pana_la BIGINT NULL COMMENT 'ms UTC, marginea dreaptă'
        AFTER fereastra_de_la;

-- Triunghiurile marcate `sters` de motor erau de fapt expirate: nota o spune.
UPDATE triunghiuri
SET stare = 'expirat'
WHERE stare = 'sters' AND nota LIKE 'expirat:%';

SELECT stare, COUNT(*) AS cate FROM triunghiuri GROUP BY stare;
