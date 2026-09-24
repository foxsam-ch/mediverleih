-- =====================================================================
--  MediVerleih - Verleih medizintechnischer Geraete
--  03_testdaten.sql : realistische Beispieldaten fuer die Demo
--
--  Alle Datumsangaben sind relativ zu CURDATE(). Damit ist immer
--  eine Ausleihe ueberfaellig - egal wann die Praesentation stattfindet.
--  Vor einer Vorfuehrung dieses Skript nochmals einspielen, damit die
--  Fristen wieder sinnvoll um den aktuellen Tag liegen.
-- =====================================================================

USE mediverleih;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE ausleihe_position;
TRUNCATE TABLE ausleihe;
TRUNCATE TABLE ausleihart;
TRUNCATE TABLE kunde;
TRUNCATE TABLE institution;
TRUNCATE TABLE geraet;
TRUNCATE TABLE geraetetyp;
TRUNCATE TABLE hersteller;
SET FOREIGN_KEY_CHECKS = 1;


-- --------------------------------------------------- Ausleiharten
-- Kontrollierte Liste: der Grund, aus dem ein Geraet verliehen wird.
INSERT INTO ausleihart (id, bezeichnung, beschreibung) VALUES
 (1, 'Demostellung',                'Geraet zur Erprobung vor einer Beschaffung'),
 (2, 'Leihgeraet infolge Reparatur','Ersatz, solange das Kundengeraet repariert wird'),
 (3, 'Leihgeraet infolge Revision', 'Ersatz waehrend der geplanten Wartung des Kundengeraets'),
 (4, 'Kapazitaetsengpass',          'Zusaetzlicher Bedarf, z.B. Grossanlass oder Saisonspitze'),
 (5, 'Langzeitmiete',               'Laengerfristige Miete ohne eigenes Geraet');


-- --------------------------------------------------- Hersteller
INSERT INTO hersteller (id, name, land, support_email) VALUES
 (1, 'Draegerwerk',        'DE', 'service@draeger.example'),
 (2, 'Philips Healthcare', 'NL', 'support@philips.example'),
 (3, 'B. Braun',           'DE', 'technik@bbraun.example'),
 (4, 'Fresenius Kabi',     'DE', 'service@fresenius.example'),
 (5, 'GE HealthCare',      'US', 'support@gehealthcare.example'),
 (6, 'Mindray',            'CN', 'service@mindray.example');


-- --------------------------------------------------- Geraetetypen
INSERT INTO geraetetyp (id, hersteller_id, bezeichnung, modellnummer, beschreibung) VALUES
 (1, 1, 'Beatmungsgeraet Evita V300',       'EVITAV300', 'Intensivbeatmungsgeraet fuer invasive und nicht-invasive Beatmung'),
 (2, 1, 'Transportbeatmung Oxylog 3000+',   'OXYLOG3000','Notfall- und Transportbeatmungsgeraet, akkubetrieben'),
 (3, 3, 'Infusionspumpe Infusomat Space P', 'INFSPACEP', 'Volumetrische Infusionspumpe, stapelbar'),
 (4, 4, 'Spritzenpumpe Injectomat MC',      'INJMCAG',   'Spritzenpumpe fuer praezise Medikamentendosierung'),
 (5, 2, 'Patientenmonitor IntelliVue MX450','MX450',     'Transportfaehiger Monitor: EKG, SpO2, NIBP, Temperatur'),
 (6, 5, 'EKG-Geraet MAC 2000',              'MAC2000',   '12-Kanal-Ruhe-EKG mit Ausdruck'),
 (7, 2, 'Defibrillator HeartStart FR3',     'FR3',       'Halbautomatischer externer Defibrillator'),
 (8, 6, 'Ultraschallgeraet TE7',            'TE7',       'Mobiles Ultraschallsystem fuer Point-of-Care-Diagnostik');


-- --------------------------------------------------- Geraete (Einzelstuecke)
INSERT INTO geraet (id, geraetetyp_id, seriennummer, inventarnummer) VALUES
 ( 1, 1, 'DRG-EV300-2201884', 'MP-0001'),
 ( 2, 1, 'DRG-EV300-2201907', 'MP-0002'),
 ( 3, 2, 'DRG-OXY3-1170455',  'MP-0003'),
 ( 4, 2, 'DRG-OXY3-1170461',  'MP-0004'),
 ( 5, 3, 'BBR-INFSP-88120033','MP-0005'),
 ( 6, 3, 'BBR-INFSP-88120034','MP-0006'),
 ( 7, 3, 'BBR-INFSP-88120035','MP-0007'),
 ( 8, 3, 'BBR-INFSP-90044120','MP-0008'),
 ( 9, 4, 'FRK-INJMC-4471002', 'MP-0009'),
 (10, 4, 'FRK-INJMC-4471008', 'MP-0010'),
 (11, 4, 'FRK-INJMC-4471015', 'MP-0011'),
 (12, 5, 'PHI-MX450-7712091', 'MP-0012'),
 (13, 5, 'PHI-MX450-7712104', 'MP-0013'),
 (14, 6, 'GEH-MAC2K-3300451', 'MP-0014'),
 (15, 6, 'GEH-MAC2K-3300467', 'MP-0015'),
 (16, 7, 'PHI-FR3-55120918',  'MP-0016'),
 (17, 8, 'MDR-TE7-99001472',  'MP-0017'),
 (18, 7, 'PHI-FR3-55120944',  'MP-0018');

-- MP-0017 ist ausser Betrieb genommen.
-- Der Trigger weist jede Ausleihe dieses Geraets ab
-- -> Demo-Fall fuer die Praesentation.
UPDATE geraet SET status = 'ausgemustert',
                  notiz  = 'Schallkopf defekt, wirtschaftlicher Totalschaden'
 WHERE id = 17;


-- --------------------------------------------------- Institutionen
INSERT INTO institution (id, name, typ, uid_nummer, strasse, plz, ort, land, telefon, email) VALUES
 (1, 'Kantonsspital Aarau',        'Spital',        'CHE-101.234.567', 'Tellstrasse 25',     '5001', 'Aarau',    'CH', '062 838 41 41', 'medizintechnik@ksa.example'),
 (2, 'Pflegezentrum Seeblick',     'Pflegeheim',    'CHE-102.876.543', 'Seestrasse 14',      '6005', 'Luzern',   'CH', '041 555 22 10', 'pflege@seeblick.example'),
 (3, 'Praxis Dr. med. N. Berger',  'Arztpraxis',    'CHE-103.445.221', 'Bahnhofstrasse 7',   '3600', 'Thun',     'CH', '033 555 78 90', 'praxis@berger.example'),
 (4, 'Rettungsdienst Region Bern', 'Rettungsdienst','CHE-104.998.112', 'Freiburgstrasse 18', '3010', 'Bern',     'CH', '031 632 21 11', 'material@rd-bern.example'),
 (5, 'Spitex Emmental',            'Spitex',        'CHE-105.771.334', 'Marktgasse 3',       '3400', 'Burgdorf', 'CH', '034 555 60 60', 'info@spitex-emmental.example');


-- --------------------------------------------------- Kunden (Empfaenger)
-- institution_id NULL = Privatperson, hat eigene Adresse (CHECK-Constraint)
INSERT INTO kunde (id, institution_id, anrede, vorname, nachname, funktion, email, telefon, strasse, plz, ort, land) VALUES
 (1, 1,    'Herr', 'Marco',  'Brunner',  'Leitung Medizintechnik', 'm.brunner@ksa.example',           '062 838 41 55', NULL, NULL, NULL, NULL),
 (2, 1,    'Frau', 'Sandra', 'Kaufmann', 'Stationsleitung IPS',    's.kaufmann@ksa.example',          '062 838 41 72', NULL, NULL, NULL, NULL),
 (3, 2,    'Herr', 'Peter',  'Steiner',  'Pflegedienstleitung',    'p.steiner@seeblick.example',      '041 555 22 14', NULL, NULL, NULL, NULL),
 (4, 3,    'Frau', 'Nina',   'Berger',   'Aerztin',                'n.berger@berger.example',         '033 555 78 91', NULL, NULL, NULL, NULL),
 (5, 4,    'Herr', 'Luca',   'Bergamin', 'Rettungssanitaeter HF',  'l.bergamin@rd-bern.example',      '031 632 21 40', NULL, NULL, NULL, NULL),
 (6, 5,    'Herr', 'Thomas', 'Meier',    'Pflegefachmann HF',      't.meier@spitex-emmental.example', '034 555 60 71', NULL, NULL, NULL, NULL),
 (7, NULL, 'Frau', 'Andrea', 'Huber',    'Angehoerige, Heimpflege','andrea.huber@example.ch',         '076 123 45 67', 'Lindenweg 9', '3400', 'Burgdorf', 'CH');


-- =====================================================================
--  AUSLEIHEN
--  Die INSERTs in ausleihe_position loesen bewusst die Trigger aus:
--  jedes betroffene Geraet wechselt automatisch auf "verliehen".
-- =====================================================================

INSERT INTO ausleihe (id, belegnummer, kunde_id, ausleihart_id, ausleihdatum, geplante_rueckgabe, bemerkung) VALUES
 (1, 'MV-2026-0001', 1, 3, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 26 DAY), 'Revision der eigenen Beatmungsgeraete auf der IPS'),
 (2, 'MV-2026-0002', 4, 1, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Evaluation vor Beschaffung eines eigenen Geraets'),
 (3, 'MV-2026-0003', 3, 2, DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL  2 DAY), 'Verlaengerung angefragt, noch offen'),
 (4, 'MV-2026-0004', 5, 4, DATE_SUB(CURDATE(), INTERVAL  6 DAY), DATE_ADD(CURDATE(), INTERVAL  8 DAY), 'Reservefahrzeug fuer Grossanlass'),
 (5, 'MV-2026-0005', 7, 5, DATE_SUB(CURDATE(), INTERVAL  3 DAY), DATE_ADD(CURDATE(), INTERVAL  4 DAY), 'Heimpflege, Instruktion vor Ort erfolgt'),
 (6, 'MV-2026-0006', 6, 5, CURDATE(),                            DATE_ADD(CURDATE(), INTERVAL 21 DAY), 'Spitex-Einsatz bei Langzeitpatient');

INSERT INTO ausleihe_position (ausleihe_id, geraet_id) VALUES
 (1,  1),   -- Evita V300      - wird unten zurueckgegeben
 (1,  5),   -- Infusomat       - wird unten zurueckgegeben
 (2, 14),   -- EKG MAC 2000    - wird unten zurueckgegeben
 (3, 12),   -- Monitor MX450   - OFFEN und ueberfaellig
 (3,  6),   -- Infusomat       - OFFEN und ueberfaellig
 (4,  3),   -- Oxylog 3000+    - offen, in der Frist
 (4, 16),   -- Defibrillator   - offen, in der Frist
 (5,  9),   -- Spritzenpumpe   - offen, in der Frist
 (6,  8);   -- Infusomat       - offen, in der Frist


-- --------------------------------------------------- Rueckgaben
-- Das UPDATE loest trg_pos_after_update aus: Geraet wird wieder
-- verfuegbar und der Beleg geschlossen, sobald alle Positionen
-- zurueck sind.
UPDATE ausleihe_position
   SET rueckgabe_datum = DATE_SUB(CURDATE(), INTERVAL 27 DAY)
 WHERE ausleihe_id = 1;

UPDATE ausleihe_position
   SET rueckgabe_datum = DATE_SUB(CURDATE(), INTERVAL 12 DAY)
 WHERE ausleihe_id = 2;


-- =====================================================================
--  KONTROLLE
-- =====================================================================

SELECT '--- Geraete nach Status ---' AS abschnitt;
SELECT status, COUNT(*) AS anzahl FROM geraet GROUP BY status;

SELECT '--- Offene Ausleihen ---' AS abschnitt;
SELECT belegnummer, kunde, geraet, geplante_rueckgabe, verzugstage
  FROM v_ausleihen_offen
 ORDER BY verzugstage DESC;

SELECT '--- Abgeschlossene Belege ---' AS abschnitt;
SELECT belegnummer, status, ausleihdatum, tatsaechliche_rueckgabe
  FROM ausleihe WHERE status = 'zurueckgegeben';

SELECT '--- Ausleihen nach Ausleihart ---' AS abschnitt;
SELECT art.bezeichnung AS ausleihart, COUNT(a.id) AS anzahl
  FROM ausleihart art
  LEFT JOIN ausleihe a ON a.ausleihart_id = art.id
 GROUP BY art.id, art.bezeichnung
 ORDER BY anzahl DESC;
