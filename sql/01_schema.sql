-- =====================================================================
--  MediVerleih - Verleih medizintechnischer Geraete
--  01_schema.sql : Datenbank, Tabellen, Constraints, Indizes
--  MariaDB 10.11+
-- =====================================================================

DROP DATABASE IF EXISTS mediverleih;
CREATE DATABASE mediverleih
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE mediverleih;


-- ---------------------------------------------------------------------
-- 1) hersteller
--    Eigene Tabelle, weil der Hersteller vom TYP abhaengt, nicht vom
--    einzelnen Geraet -> vermeidet transitive Abhaengigkeit (3NF).
-- ---------------------------------------------------------------------
CREATE TABLE hersteller (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL,
    land          VARCHAR(60) NULL,
    support_email VARCHAR(120) NULL,
    CONSTRAINT uq_hersteller_name UNIQUE (name)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 2) geraetetyp  -- das Modell, z.B. "Draeger Evita V300"
--    Die Bezeichnung gehoert zum Typ, nicht zum Einzelstueck.
-- ---------------------------------------------------------------------
CREATE TABLE geraetetyp (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hersteller_id INT UNSIGNED NOT NULL,
    bezeichnung   VARCHAR(120)  NOT NULL,
    modellnummer  VARCHAR(60)   NOT NULL,
    beschreibung  TEXT          NULL,

    CONSTRAINT fk_typ_hersteller FOREIGN KEY (hersteller_id)
        REFERENCES hersteller(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    -- ein Hersteller vergibt eine Modellnummer nur einmal
    CONSTRAINT uq_typ_modell UNIQUE (hersteller_id, modellnummer)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 3) geraet  -- das physische Einzelstueck mit Seriennummer
--
--    status:
--      verfuegbar    einsatzbereit
--      verliehen     aktuell bei einem Kunden
--      ausgemustert  ausser Betrieb, nicht mehr verleihbar
-- ---------------------------------------------------------------------
CREATE TABLE geraet (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geraetetyp_id  INT UNSIGNED NOT NULL,
    seriennummer   VARCHAR(80)  NOT NULL,
    inventarnummer VARCHAR(30)  NOT NULL,
    status  ENUM('verfuegbar','verliehen','ausgemustert')
            NOT NULL DEFAULT 'verfuegbar',
    notiz   VARCHAR(255) NULL,

    CONSTRAINT fk_geraet_typ FOREIGN KEY (geraetetyp_id)
        REFERENCES geraetetyp(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT uq_geraet_serie UNIQUE (seriennummer),
    CONSTRAINT uq_geraet_inv   UNIQUE (inventarnummer),
    INDEX ix_geraet_status (status)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 4) institution  -- Spital, Pflegeheim, Arztpraxis, Rettungsdienst
--    (entspricht der "Firma" aus der Anforderung, im medizinischen
--     Umfeld ist "Institution" der treffendere Begriff)
-- ---------------------------------------------------------------------
CREATE TABLE institution (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    typ ENUM('Spital','Pflegeheim','Arztpraxis','Rettungsdienst','Spitex','Andere')
        NOT NULL DEFAULT 'Spital',
    uid_nummer VARCHAR(20)  NULL,
    strasse    VARCHAR(120) NOT NULL,
    plz        VARCHAR(10)  NOT NULL,
    ort        VARCHAR(80)  NOT NULL,
    land       CHAR(2)      NOT NULL DEFAULT 'CH',
    telefon    VARCHAR(30)  NULL,
    email      VARCHAR(120) NULL,

    CONSTRAINT uq_institution_uid UNIQUE (uid_nummer),
    INDEX ix_institution_name (name)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 5) kunde  -- die Person, die das Geraet entgegennimmt
--    institution_id NULL  -> Privatperson (z.B. Heimpflege),
--                            eigene Adresse ist dann Pflicht
--    institution_id gesetzt -> Adressfelder duerfen leer bleiben,
--                            dann gilt die Adresse der Institution
-- ---------------------------------------------------------------------
CREATE TABLE kunde (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NULL,
    anrede   ENUM('Herr','Frau','keine') NOT NULL DEFAULT 'keine',
    vorname  VARCHAR(60)  NOT NULL,
    nachname VARCHAR(60)  NOT NULL,
    funktion VARCHAR(80)  NULL,      -- z.B. Stationsleitung, Techniker
    email    VARCHAR(120) NULL,
    telefon  VARCHAR(30)  NULL,
    strasse  VARCHAR(120) NULL,
    plz      VARCHAR(10)  NULL,
    ort      VARCHAR(80)  NULL,
    land     CHAR(2)      NULL,

    -- Bewusst OHNE "ON UPDATE CASCADE": MariaDB verbietet referentielle
    -- Aktionen auf Spalten, die in einem CHECK-Constraint vorkommen
    -- (Fehler 1901). Da institution.id ein unveraenderlicher
    -- Surrogatschluessel ist, geht dadurch nichts verloren.
    CONSTRAINT fk_kunde_institution FOREIGN KEY (institution_id)
        REFERENCES institution(id) ON DELETE RESTRICT,

    -- Privatperson ohne Institution muss eine eigene Adresse haben
    CONSTRAINT ck_kunde_adresse CHECK (
        institution_id IS NOT NULL
        OR (strasse IS NOT NULL AND plz IS NOT NULL AND ort IS NOT NULL)
    ),
    INDEX ix_kunde_name (nachname, vorname)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 6) ausleihart  -- WARUM wird ausgeliehen
--
--    Kontrollierte Liste statt Freitext. Der Grund einer Ausleihe ist
--    eine Kategorie, die auf beliebig viele Belege zutrifft
--    ("Leihgeraet infolge Reparatur"), nicht eine Einzelfall-
--    beschreibung. Als Freitext waere er nicht auswertbar: jede
--    Schreibweise ergaebe eine eigene Gruppe.
--    Der Einzelfall steht weiterhin frei in ausleihe.bemerkung.
-- ---------------------------------------------------------------------
CREATE TABLE ausleihart (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bezeichnung  VARCHAR(80)  NOT NULL,
    beschreibung VARCHAR(255) NULL,
    CONSTRAINT uq_ausleihart_bezeichnung UNIQUE (bezeichnung)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 7) ausleihe  -- der Beleg: WER leiht WANN und WIE LANGE
--    leihdauer_tage ist eine GENERATED COLUMN: nie von Hand erfasst,
--    immer aus den beiden Datumsfeldern berechnet.
-- ---------------------------------------------------------------------
CREATE TABLE ausleihe (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    belegnummer             VARCHAR(20)  NULL,
    kunde_id                INT UNSIGNED NOT NULL,
    ausleihart_id           INT UNSIGNED NOT NULL,
    ausleihdatum            DATE NOT NULL,
    geplante_rueckgabe      DATE NOT NULL,
    tatsaechliche_rueckgabe DATE NULL,
    status ENUM('offen','zurueckgegeben','storniert') NOT NULL DEFAULT 'offen',
    bemerkung               VARCHAR(255) NULL,   -- Freitext zum Einzelfall
    erfasst_am              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- geplante Leihdauer in Tagen, automatisch berechnet
    leihdauer_tage INT AS (DATEDIFF(geplante_rueckgabe, ausleihdatum)) VIRTUAL,

    CONSTRAINT fk_ausleihe_kunde FOREIGN KEY (kunde_id)
        REFERENCES kunde(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ausleihe_art FOREIGN KEY (ausleihart_id)
        REFERENCES ausleihart(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CONSTRAINT uq_ausleihe_beleg UNIQUE (belegnummer),
    CONSTRAINT ck_ausleihe_zeitraum CHECK (geplante_rueckgabe >= ausleihdatum),
    INDEX ix_ausleihe_status (status),
    INDEX ix_ausleihe_frist  (geplante_rueckgabe),
    INDEX ix_ausleihe_art    (ausleihart_id)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 8) ausleihe_position  -- Aufloesung der n:m-Beziehung
--    Ein Beleg kann mehrere Geraete enthalten,
--    ein Geraet hat ueber die Zeit mehrere Ausleihen.
--
--    rueckgabe_datum ist das eigene Merkmal dieser Beziehung: Es haengt
--    weder vom Beleg allein noch vom Geraet allein ab, sondern von der
--    Kombination aus beiden. Genau deshalb gehoert es hierher (2NF).
--
--    aktiv_geraet_id ist der Kern der Integritaet:
--      laufende Position  -> enthaelt die geraet_id
--      zurueckgegeben     -> NULL
--    Der UNIQUE-Index darauf laesst pro Geraet nur EINE laufende
--    Position zu. NULL-Werte werden von MariaDB im UNIQUE-Index
--    mehrfach akzeptiert - genau das brauchen wir.
--    => Doppelausleihe ist auf Datenbankebene unmoeglich.
-- ---------------------------------------------------------------------
CREATE TABLE ausleihe_position (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ausleihe_id     INT UNSIGNED NOT NULL,
    geraet_id       INT UNSIGNED NOT NULL,
    rueckgabe_datum DATE NULL,

    aktiv_geraet_id INT UNSIGNED
        AS (IF(rueckgabe_datum IS NULL, geraet_id, NULL)) PERSISTENT,

    CONSTRAINT fk_pos_ausleihe FOREIGN KEY (ausleihe_id)
        REFERENCES ausleihe(id) ON DELETE CASCADE ON UPDATE CASCADE,
    -- Ebenfalls ohne "ON UPDATE CASCADE": geraet_id wird in der
    -- berechneten Spalte aktiv_geraet_id verwendet, und MariaDB
    -- verbietet dort referentielle Aktionen (Fehler 1901).
    CONSTRAINT fk_pos_geraet FOREIGN KEY (geraet_id)
        REFERENCES geraet(id)  ON DELETE RESTRICT,

    -- dasselbe Geraet nicht zweimal auf demselben Beleg
    CONSTRAINT uq_pos_beleg_geraet UNIQUE (ausleihe_id, geraet_id),
    -- pro Geraet hoechstens eine laufende Ausleihe
    CONSTRAINT uq_pos_aktiv UNIQUE (aktiv_geraet_id)
) ENGINE=InnoDB;
