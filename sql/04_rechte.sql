-- =====================================================================
--  MediVerleih - Verleih medizintechnischer Geraete
--  04_rechte.sql : Datenbankbenutzer und Berechtigungen
--
--  Grundsatz: die Webapplikation laeuft NICHT als root.
--  Sie darf Daten lesen und schreiben, aber keine Tabellen aendern
--  oder loeschen. Der Lesebenutzer sieht ueberhaupt nur die Views.
--
--  ACHTUNG - diese Datei NICHT unveraendert einspielen!
--
--  Die Platzhalter __ADMIN_PW__, __APP_PW__ und __RO_PW__ werden von
--  deploy/install.sh durch zufaellig erzeugte Passwoerter ersetzt.
--  Wer die Datei direkt mit "mariadb < 04_rechte.sql" einspielt, setzt
--  die Platzhalter woertlich als Passwort. Die Applikation kann sich
--  dann nicht mehr anmelden (Fehler 1045) und liefert HTTP 500, weil
--  ihre config.php noch das alte Passwort enthaelt.
--
--  Richtig:   sudo bash deploy/install.sh
--  Von Hand:  die drei Platzhalter vorher ersetzen und danach
--             /var/www/mediverleih/config.php entsprechend anpassen.
-- =====================================================================

USE mediverleih;

-- --------------------------------------------------- Administrator
-- Anmeldung in phpMyAdmin. Der MariaDB-root nutzt unter Ubuntu
-- unix_socket-Authentifizierung und kann sich dort nicht anmelden -
-- deshalb ein eigener Admin-Benutzer mit Passwort.
DROP USER IF EXISTS 'mediverleih_admin'@'localhost';
CREATE USER 'mediverleih_admin'@'localhost' IDENTIFIED BY '__ADMIN_PW__';
GRANT ALL PRIVILEGES ON mediverleih.* TO 'mediverleih_admin'@'localhost';


-- --------------------------------------------------- Applikationsbenutzer
-- Diesen Benutzer verwendet die PHP-Applikation.
DROP USER IF EXISTS 'mediverleih_app'@'localhost';
CREATE USER 'mediverleih_app'@'localhost' IDENTIFIED BY '__APP_PW__';

GRANT SELECT, INSERT, UPDATE, DELETE ON mediverleih.* TO 'mediverleih_app'@'localhost';
GRANT EXECUTE ON mediverleih.* TO 'mediverleih_app'@'localhost';

-- bewusst NICHT vergeben: CREATE, DROP, ALTER, GRANT
-- Selbst wenn jemand eine SQL-Injection findet, kann er keine
-- Tabelle loeschen und keine Rechte ausweiten.


-- --------------------------------------------------- Nur-Lese-Benutzer
-- Fuer die SQL-Konsole in der Praesentation.
-- Sieht nur die Views, nicht die Rohtabellen.
DROP USER IF EXISTS 'mediverleih_ro'@'localhost';
CREATE USER 'mediverleih_ro'@'localhost' IDENTIFIED BY '__RO_PW__';

GRANT SELECT ON mediverleih.v_kunde              TO 'mediverleih_ro'@'localhost';
GRANT SELECT ON mediverleih.v_geraete_uebersicht TO 'mediverleih_ro'@'localhost';
GRANT SELECT ON mediverleih.v_ausleihen_offen    TO 'mediverleih_ro'@'localhost';

FLUSH PRIVILEGES;


-- --------------------------------------------------- Kontrolle
SELECT user, host FROM mysql.user WHERE user LIKE 'mediverleih%';
SHOW GRANTS FOR 'mediverleih_app'@'localhost';
SHOW GRANTS FOR 'mediverleih_ro'@'localhost';
