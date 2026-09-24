-- =====================================================================
--  MediVerleih - Verleih medizintechnischer Geraete
--  02_logik.sql : Views, Trigger, Stored Procedures
--
--  ACHTUNG - diese Datei NICHT ueber die SQL-Registerkarte in
--  phpMyAdmin einspielen!
--
--  "DELIMITER //" ist kein SQL-Befehl, sondern eine Anweisung an den
--  Kommandozeilen-Client. phpMyAdmin wertet sie dort nicht aus und
--  schneidet die Prozedurruempfe beim ersten internen Semikolon ab.
--  Da "CREATE OR REPLACE" intern erst DROP und dann CREATE ausfuehrt,
--  sind die bestehenden Routinen danach geloescht und nicht ersetzt.
--
--  Richtig:   sudo mariadb < sql/02_logik.sql
--  Siehe docs/implementierung.md, Problem 4.
-- =====================================================================

USE mediverleih;


-- =====================================================================
--  VIEWS
-- =====================================================================

-- ---------------------------------------------------------------------
-- v_kunde : Kunde mit effektiver Adresse
--   Institutionskunde -> Adresse der Institution
--   Privatperson      -> eigene Adresse
--   COALESCE loest das an einer einzigen Stelle auf, statt die
--   Adresse in der Kundentabelle zu wiederholen.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_kunde AS
SELECT
    k.id                               AS kunde_id,
    i.name                             AS institution,
    i.typ                              AS institutionstyp,
    k.anrede,
    k.vorname,
    k.nachname,
    CONCAT(k.vorname, ' ', k.nachname) AS name_voll,
    k.funktion,
    k.email,
    k.telefon,
    COALESCE(k.strasse, i.strasse)     AS strasse,
    COALESCE(k.plz,     i.plz)         AS plz,
    COALESCE(k.ort,     i.ort)         AS ort,
    COALESCE(k.land,    i.land, 'CH')  AS land,
    CASE WHEN k.institution_id IS NULL THEN 'Privat' ELSE 'Institution' END AS kundentyp
FROM kunde k
LEFT JOIN institution i ON i.id = k.institution_id;


-- ---------------------------------------------------------------------
-- v_geraete_uebersicht : ein Geraet mit allen Stammdaten, damit die
--   Applikation nicht bei jeder Liste mehrere Joins schreiben muss.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_geraete_uebersicht AS
SELECT
    g.id          AS geraet_id,
    g.inventarnummer,
    g.seriennummer,
    h.name        AS hersteller,
    t.bezeichnung AS typ,
    t.modellnummer,
    g.status,
    g.notiz
FROM geraet g
JOIN geraetetyp t ON t.id = g.geraetetyp_id
JOIN hersteller h ON h.id = t.hersteller_id;


-- ---------------------------------------------------------------------
-- v_ausleihen_offen : laufende Ausleihen inkl. Verzugstagen.
--   Ueberfaellig wird berechnet, nicht gespeichert - so kann der
--   Wert nie veralten.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_ausleihen_offen AS
SELECT
    a.id                AS ausleihe_id,
    a.belegnummer,
    vk.institution,
    vk.name_voll        AS kunde,
    vk.strasse, vk.plz, vk.ort,
    g.id                AS geraet_id,
    g.inventarnummer,
    CONCAT(h.name, ' ', t.bezeichnung) AS geraet,
    g.seriennummer,
    art.bezeichnung     AS ausleihart,
    a.bemerkung,
    a.ausleihdatum,
    a.geplante_rueckgabe,
    a.leihdauer_tage,
    GREATEST(DATEDIFF(CURDATE(), a.geplante_rueckgabe), 0) AS verzugstage,
    (DATEDIFF(CURDATE(), a.geplante_rueckgabe) > 0)        AS ist_ueberfaellig
FROM ausleihe a
JOIN ausleihe_position p ON p.ausleihe_id = a.id
JOIN ausleihart art ON art.id = a.ausleihart_id
JOIN v_kunde   vk ON vk.kunde_id = a.kunde_id
JOIN geraet    g  ON g.id  = p.geraet_id
JOIN geraetetyp t ON t.id  = g.geraetetyp_id
JOIN hersteller h ON h.id  = t.hersteller_id
WHERE p.rueckgabe_datum IS NULL
  AND a.status = 'offen';


-- =====================================================================
--  TRIGGER
--  Halten Geraete- und Belegstatus automatisch korrekt - auch bei
--  manueller Bearbeitung in phpMyAdmin.
-- =====================================================================

DELIMITER //

-- ---------------------------------------------------------------------
-- 1) Vor dem Einfuegen: das Geraet muss verfuegbar sein.
-- ---------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_pos_before_insert
BEFORE INSERT ON ausleihe_position
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20);

    SELECT status INTO v_status FROM geraet WHERE id = NEW.geraet_id;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Geraet existiert nicht.';
    END IF;

    IF v_status <> 'verfuegbar' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Geraet ist nicht verfuegbar (Status pruefen).';
    END IF;
END//


-- ---------------------------------------------------------------------
-- 2) Nach dem Einfuegen: Geraet auf "verliehen" setzen.
-- ---------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_pos_after_insert
AFTER INSERT ON ausleihe_position
FOR EACH ROW
BEGIN
    UPDATE geraet SET status = 'verliehen' WHERE id = NEW.geraet_id;
END//


-- ---------------------------------------------------------------------
-- 3) Nach der Rueckgabe: Geraet freigeben und den Beleg schliessen,
--    sobald alle Positionen zurueck sind.
-- ---------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_pos_after_update
AFTER UPDATE ON ausleihe_position
FOR EACH ROW
BEGIN
    DECLARE v_offen INT;

    IF NEW.rueckgabe_datum IS NOT NULL AND OLD.rueckgabe_datum IS NULL THEN

        UPDATE geraet SET status = 'verfuegbar' WHERE id = NEW.geraet_id;

        SELECT COUNT(*) INTO v_offen
          FROM ausleihe_position
         WHERE ausleihe_id = NEW.ausleihe_id
           AND rueckgabe_datum IS NULL;

        IF v_offen = 0 THEN
            UPDATE ausleihe
               SET status = 'zurueckgegeben',
                   tatsaechliche_rueckgabe = NEW.rueckgabe_datum
             WHERE id = NEW.ausleihe_id;
        END IF;
    END IF;
END//


-- =====================================================================
--  STORED PROCEDURES
-- =====================================================================

-- ---------------------------------------------------------------------
-- sp_geraet_ausleihen
--   Legt Beleg + Position in EINER Transaktion an.
--   Faellt irgendetwas um (z.B. Trigger meldet "nicht verfuegbar"),
--   wird alles zurueckgerollt - es bleibt kein leerer Beleg stehen.
--   Die Pruefung des Geraets uebernimmt trg_pos_before_insert; eine
--   zweite Pruefung hier waere Verdopplung derselben Regel.
-- ---------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE sp_geraet_ausleihen(
    IN  p_kunde_id      INT UNSIGNED,
    IN  p_geraet_id     INT UNSIGNED,
    IN  p_von           DATE,
    IN  p_bis           DATE,
    IN  p_ausleihart_id INT UNSIGNED,
    IN  p_bemerkung     VARCHAR(255),
    OUT p_ausleihe_id   INT UNSIGNED
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    INSERT INTO ausleihe (kunde_id, ausleihart_id, ausleihdatum,
                          geplante_rueckgabe, bemerkung)
    VALUES (p_kunde_id, p_ausleihart_id, p_von, p_bis, p_bemerkung);

    SET p_ausleihe_id = LAST_INSERT_ID();

    UPDATE ausleihe
       SET belegnummer = CONCAT('MV-', YEAR(p_von), '-',
                                LPAD(p_ausleihe_id, 4, '0'))
     WHERE id = p_ausleihe_id;

    -- loest trg_pos_before_insert (Pruefung) und
    -- trg_pos_after_insert (Statuswechsel) aus
    INSERT INTO ausleihe_position (ausleihe_id, geraet_id)
    VALUES (p_ausleihe_id, p_geraet_id);

    COMMIT;
END//


-- ---------------------------------------------------------------------
-- sp_geraet_zurueckgeben
--   Setzt das Rueckgabedatum. Alles Weitere erledigt der Trigger:
--   Geraet -> verfuegbar, Beleg -> geschlossen.
-- ---------------------------------------------------------------------
CREATE OR REPLACE PROCEDURE sp_geraet_zurueckgeben(
    IN p_geraet_id INT UNSIGNED
)
BEGIN
    DECLARE v_pos_id INT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT id INTO v_pos_id
      FROM ausleihe_position
     WHERE geraet_id = p_geraet_id
       AND rueckgabe_datum IS NULL
     LIMIT 1;

    IF v_pos_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Fuer dieses Geraet ist keine Ausleihe offen.';
    END IF;

    UPDATE ausleihe_position
       SET rueckgabe_datum = CURDATE()
     WHERE id = v_pos_id;

    COMMIT;
END//

DELIMITER ;
