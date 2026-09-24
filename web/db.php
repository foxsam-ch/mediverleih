<?php
declare(strict_types=1);

/* =====================================================================
   MediVerleih - Datenbankzugriff

   Zwei getrennte Verbindungen mit unterschiedlichen Rechten:

     db()     mediverleih_app  darf lesen und schreiben, aber kein
                               DROP/ALTER/GRANT
     db_ro()  mediverleih_ro   darf nur SELECT auf drei Views

   Die SQL-Konsole verwendet ausschliesslich db_ro(). Selbst wenn dort
   jemand ein DELETE eintippt, fehlt dem Benutzer schlicht das Recht.
   ===================================================================== */

function konfiguration(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $pfad = __DIR__ . '/config.php';
        if (!is_readable($pfad)) {
            http_response_code(500);
            exit('config.php fehlt. Wird von deploy/install.sh erzeugt.');
        }
        $cfg = require $pfad;
    }
    return $cfg;
}

function verbindung(string $benutzerSchluessel, string $passwortSchluessel): PDO
{
    $cfg = konfiguration();
    return new PDO(
        $cfg['dsn'],
        $cfg[$benutzerSchluessel],
        $cfg[$passwortSchluessel],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Echte Prepared Statements, keine Emulation im Treiber:
            // die Werte werden nie in den SQL-Text eingesetzt.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

/** Schreibender Zugriff fuer die Applikation. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = verbindung('benutzer', 'passwort');
    }
    return $pdo;
}

/** Nur-Lese-Zugriff fuer die SQL-Konsole. */
function db_ro(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = verbindung('ro_benutzer', 'ro_passwort');
    }
    return $pdo;
}

/**
 * Kurzform fuer eine Abfrage mit Parametern.
 * Alle Werte gehen als Parameter in das Statement, nie in den SQL-Text.
 */
function abfrage(string $sql, array $parameter = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($parameter);
    return $stmt->fetchAll();
}

/** Erste Spalte der ersten Zeile - fuer Zaehlwerte. */
function wert(string $sql, array $parameter = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($parameter);
    return $stmt->fetchColumn();
}

/**
 * Fehlermeldungen aus der Datenbank lesbar machen.
 * Unsere Trigger und Prozeduren melden ueber SIGNAL SQLSTATE '45000'
 * fachliche Klartexte - die wollen wir dem Benutzer direkt zeigen.
 * Technische Meldungen (Duplicate entry o. ae.) werden uebersetzt.
 */
function fehlertext(PDOException $e): string
{
    $roh = $e->getMessage();

    if (str_contains($roh, 'uq_pos_aktiv')) {
        return 'Dieses Geraet ist bereits in einer laufenden Ausleihe. '
             . 'Der UNIQUE-Index auf der Datenbank hat den Vorgang abgewiesen.';
    }

    // Format: SQLSTATE[45000]: <<Unknown error>>: 1644 Meldungstext
    if (preg_match('/1644\s+(.*)$/s', $roh, $treffer)) {
        return trim($treffer[1]);
    }

    return $roh;
}
