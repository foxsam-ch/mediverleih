# Implementierungsprotokoll

Dokumentiert die Probleme, die beim ersten Einspielen des Schemas auf
MariaDB 11.8.6 (Ubuntu 26.04.1 LTS) aufgetreten sind, und wie sie gelöst wurden.

Umgebung: Hyper-V-VM `lnx-eval01`, 192.168.1.107, Ubuntu Server 26.04.1 LTS,
MariaDB 11.8.6, Apache 2.4 mit PHP 8.5, phpMyAdmin.

---

## Problem 1 – CHECK-Constraint auf einer Fremdschlüsselspalte

**Fehlermeldung**

```
ERROR 1901 (HY000): Function or expression 'institution_id'
cannot be used in the CHECK clause of `ck_kunde_adresse`
```

**Ausgangslage**

Die Tabelle `kunde` hat einen CHECK-Constraint, der sicherstellt, dass eine
Privatperson (ohne Institution) zwingend eine eigene Adresse hat:

```sql
CONSTRAINT ck_kunde_adresse CHECK (
    institution_id IS NOT NULL
    OR (strasse IS NOT NULL AND plz IS NOT NULL AND ort IS NOT NULL)
)
```

Gleichzeitig war `institution_id` als Fremdschlüssel mit
`ON DELETE RESTRICT ON UPDATE CASCADE` definiert.

**Analyse**

Vier Testfälle direkt auf dem Server eingegrenzt:

| Test | Konstellation | Ergebnis |
|---|---|---|
| A | CHECK auf normalen Spalten | OK |
| B | FK mit `ON DELETE RESTRICT ON UPDATE CASCADE` + CHECK | **Fehler 1901** |
| C | FK ganz ohne referentielle Aktion + CHECK | OK |
| D | FK mit `ON DELETE RESTRICT` + CHECK | OK |

Ursache ist also nicht der CHECK und nicht der Fremdschlüssel, sondern die
Kombination: MariaDB verbietet referentielle Aktionen wie `CASCADE` auf
Spalten, die in einem CHECK-Constraint verwendet werden. Der Grund ist
nachvollziehbar – eine kaskadierte Änderung würde den Constraint umgehen,
weil sie nicht als reguläre Anweisung geprüft wird.

**Lösung**

`ON UPDATE CASCADE` entfernt, `ON DELETE RESTRICT` beibehalten.

```sql
CONSTRAINT fk_kunde_institution FOREIGN KEY (institution_id)
    REFERENCES institution(id) ON DELETE RESTRICT,
```

**Bewertung**

Kein Funktionsverlust. `institution.id` ist ein AUTO_INCREMENT-Surrogatschlüssel
und wird nie geändert – ein `ON UPDATE CASCADE` hätte also ohnehin nie ausgelöst.
Das zeigt einen allgemeinen Punkt: Wer Surrogatschlüssel verwendet, braucht
`ON UPDATE CASCADE` grundsätzlich nicht.

---

## Problem 2 – Berechnete Spalte auf einer Fremdschlüsselspalte

**Fehlermeldung**

```
ERROR 1901 (HY000): Function or expression 'geraet_id'
cannot be used in the GENERATED ALWAYS AS clause of `aktiv_geraet_id`
```

**Ausgangslage**

Dieselbe Regel, andere Stelle. Die berechnete Spalte, die die Doppelausleihe
verhindert, verwendet `geraet_id`:

```sql
aktiv_geraet_id INT UNSIGNED
    AS (IF(rueckgabe_datum IS NULL, geraet_id, NULL)) PERSISTENT
```

und `geraet_id` war ebenfalls mit `ON UPDATE CASCADE` definiert.

**Lösung**

Gleiche Korrektur wie bei Problem 1: `ON UPDATE CASCADE` entfernt.

**Bewertung**

Die Einschränkung gilt für CHECK-Constraints **und** für berechnete Spalten.
Beides sind Ausdrücke, die MariaDB bei einer Kaskade nicht zuverlässig
neu auswerten kann.

---

## Problem 3 – Trigger kollidiert mit `INSERT ... SELECT`

**Fehlermeldung**

```
ERROR 1442 (HY000): Can't update table 'geraet' in stored function/trigger
because it is already used by statement which invoked this
stored function/trigger
```

**Ausgangslage**

Die Prozedur `sp_geraet_ausleihen` holte den Gerätezustand direkt im
INSERT-Statement:

```sql
INSERT INTO ausleihe_position (ausleihe_id, geraet_id, tagesansatz, zustand_ausgabe)
SELECT p_ausleihe_id, p_geraet_id, v_ansatz, g.zustand
  FROM geraet g WHERE g.id = p_geraet_id;
```

Der AFTER-INSERT-Trigger auf `ausleihe_position` setzt anschliessend
`geraet.status = 'verliehen'`. Damit will der Trigger eine Tabelle schreiben,
die die auslösende Anweisung gerade liest. MariaDB verbietet das, um
unvorhersehbare Ergebnisse zu vermeiden.

**Lösung**

Den Zustand vorab in eine Variable holen, dann mit einem einfachen
`INSERT ... VALUES` einfügen:

```sql
SELECT t.tagesansatz, g.zustand INTO v_ansatz, v_zustand
  FROM geraet g
  JOIN geraetetyp t ON t.id = g.geraetetyp_id
 WHERE g.id = p_geraet_id;

-- ... spaeter:
INSERT INTO ausleihe_position (ausleihe_id, geraet_id, tagesansatz, zustand_ausgabe)
VALUES (p_ausleihe_id, p_geraet_id, v_ansatz, v_zustand);
```

Das `SELECT ... INTO` ist eine eigenständige Anweisung und damit abgeschlossen,
bevor der INSERT den Trigger auslöst.

**Bewertung**

Die allgemeine Regel lautet: Eine Anweisung, die einen Trigger auslöst, darf
keine Tabelle lesen, die dieser Trigger schreibt. Das ist beim Entwurf von
Triggern leicht zu übersehen und fällt erst zur Laufzeit auf – ein gutes
Argument dafür, Datenbanklogik früh gegen die echte Datenbank zu testen
und nicht nur auf dem Papier zu entwerfen.

---

## Problem 4 – Routinen verschwinden nach Import über phpMyAdmin

**Symptom**

Nach einem Import von `02_logik.sql` über die SQL-Registerkarte in phpMyAdmin
waren alle Stored Procedures und die Funktion verschwunden. Tabellen, Views und
Trigger waren unverändert vorhanden. Die Applikation meldete beim Erfassen einer
Ausleihe:

```
FUNCTION mediverleih.fn_gebuehr does not exist
```

**Analyse**

`mysql.proc` enthielt keine einzige Zeile mehr für die Datenbank `mediverleih`.
Das Apache-Zugriffsprotokoll zeigte den Auslöser:

```
POST /phpmyadmin/index.php?route=/import
```

Die Ursache ist das Zusammenspiel zweier Details:

1. `CREATE OR REPLACE PROCEDURE` ist in MariaDB kein atomarer Vorgang, sondern
   ein **DROP gefolgt von CREATE**. Scheitert der CREATE-Teil, ist die alte
   Routine trotzdem weg.
2. `DELIMITER //` ist **kein SQL-Befehl**, sondern eine Anweisung an den
   Kommandozeilen-Client. Er sagt ihm, dass eine Anweisung erst beim `//` endet
   und nicht schon beim ersten `;` im Prozedurrumpf. phpMyAdmin wertet diese
   Zeile in der SQL-Registerkarte nicht gleich aus.

Ohne korrekte Delimiter-Behandlung wird der Prozedurrumpf beim ersten internen
Semikolon abgeschnitten. Das DROP läuft durch, das CREATE scheitert – und die
Routine ist verloren.

**Lösung**

Skripte mit Prozeduren und Funktionen über den Kommandozeilen-Client einspielen,
der `DELIMITER` korrekt verarbeitet:

```bash
sudo mariadb < sql/02_logik.sql
```

Wer es dennoch in phpMyAdmin tun will: unterhalb des SQL-Eingabefelds gibt es das
Feld **Delimiter**. Dort `//` eintragen und die `DELIMITER`-Zeilen aus dem Skript
weglassen. Zuverlässiger bleibt der Weg über die Kommandozeile.

**Bewertung**

Zwei Punkte zum Mitnehmen. Erstens: `CREATE OR REPLACE` ist bequem, aber im
Fehlerfall destruktiv – bei Routinen zerstört ein misslungener Ersetzungsversuch
den bestehenden Stand. Zweitens: Tabellen, Views und Trigger überlebten den
Vorgang unbeschädigt. Genau dieses selektive Schadensbild – Struktur intakt,
nur Routinen weg – ist der Fingerzeig auf ein Delimiter-Problem und nicht auf
einen Datenverlust.

Wiederherstellung dauerte einen Befehl. Dass das so einfach war, liegt daran,
dass die gesamte Datenbanklogik **als Skript im Projekt liegt** und nicht nur
in der laufenden Datenbank existiert.

---

## Problem 5 – Platzhalter in `04_rechte.sql` woertlich eingespielt

**Symptom**

Nach einem Neuaufbau der Datenbank lieferten alle Seiten der Applikation
HTTP 500. Das Apache-Fehlerlog nannte die Ursache:

```
SQLSTATE[HY000] [1045] Access denied for user 'mediverleih_app'@'localhost'
```

**Ursache**

`04_rechte.sql` enthält absichtlich die Platzhalter `__ADMIN_PW__`, `__APP_PW__`
und `__RO_PW__`. Normalerweise ersetzt `deploy/install.sh` sie vor dem Einspielen
durch zufällig erzeugte Passwörter und schreibt dieselben Werte in
`/var/www/mediverleih/config.php`.

Beim Neuaufbau wurde die Datei direkt mit `mariadb < sql/04_rechte.sql`
eingespielt. Damit erhielten die Datenbankbenutzer buchstäblich den String
`__APP_PW__` als Passwort, während `config.php` noch das ursprüngliche
Zufallspasswort enthielt. Die Anmeldung schlug fehl.

**Lösung**

Die Passwörter wieder mit den bestehenden Konfigurationsdateien abgleichen,
statt neue zu erzeugen:

```sql
SET PASSWORD FOR 'mediverleih_app'@'localhost' = PASSWORD('<Wert aus config.php>');
```

**Bewertung**

Das ist kein Datenbankfehler, sondern ein Betriebsfehler – und genau deshalb
lehrreich. Passwörter existieren an zwei Orten (Datenbank und
Applikationskonfiguration), und beide müssen gemeinsam geändert werden. Die
Trennung in ein Skript mit Platzhaltern plus ein Installationsskript, das sie
ersetzt, ist grundsätzlich richtig: So liegen keine echten Passwörter im
Projektverzeichnis. Sie erfordert aber, dass man das Rechte-Skript **nie direkt**
ausführt. `sql/04_rechte.sql` trägt dazu jetzt einen Warnblock im Kopf.

---

## Testprotokoll

Nach den Korrekturen wurden alle fachlichen Regeln gegen die laufende
Datenbank geprüft.

| # | Testfall | Erwartung | Ergebnis |
|---|---|---|---|
| 1 | Verfügbares Gerät ausleihen | Beleg entsteht, Status wird `verliehen` | bestanden |
| 2 | Dasselbe Gerät nochmals ausleihen | Abweisung durch Trigger | `ERROR 1644: Geraet ist nicht verfuegbar` |
| 3 | Ausgemustertes Gerät MP-0017 ausleihen | Abweisung durch Trigger | `ERROR 1644: Geraet ist nicht verfuegbar` |
| 4 | Trigger umgehen: Status von Hand auf `verfuegbar` setzen, dann direkt in `ausleihe_position` einfügen | Abweisung durch UNIQUE-Index | `ERROR 1062: Duplicate entry '2' for key 'uq_pos_aktiv'` |
| 5 | Nach fehlgeschlagener Ausleihe: leere Belege? | keine | 0 Belege ohne Position |

### Warum Test 4 der wichtigste ist

Tests 2 und 3 werden vom Trigger abgefangen. Ein Trigger ist Logik – und Logik
kann man umgehen, etwa indem man den Gerätestatus direkt in phpMyAdmin ändert.

Test 4 macht genau das: Der Status wird von Hand gefälscht, sodass der Trigger
die Ausleihe durchwinkt. Aufgehalten wird sie trotzdem – vom UNIQUE-Index auf
der berechneten Spalte `aktiv_geraet_id`. Dieser Index ist keine Logik, sondern
Struktur. Er lässt sich nicht umgehen, ohne das Schema zu ändern.

Test 5 belegt, dass die Transaktion in der Prozedur greift: Nach einer
abgewiesenen Ausleihe bleibt kein angefangener Beleg zurück.

---

## Zustand nach der Installation

| Objekt | Anzahl |
|---|---|
| Tabellen | 8 |
| Views | 3 |
| Stored Procedures | 2 |
| Trigger | 3 |
| Geräte im Testbestand | 18 |

Statusverteilung der Testdaten: 11 verfügbar, 6 verliehen, 1 ausgemustert.
Eine Ausleihe ist überfällig, zwei Belege sind abgeschlossen.

---

## Nachträgliche Reduktion des Umfangs

Ein erster Entwurf enthielt zusätzlich die sicherheitstechnische Kontrolle (STK)
mit Prüfintervall und Prüfterminen, die Risikoklasse nach Medizinprodukterecht
sowie die Gerätestatus `aufbereitung` und `wartung` mit zwei weiteren Prozeduren
(`sp_geraet_freigeben`, `sp_pruefung_erfassen`).

Diese Teile wurden bewusst wieder entfernt. Begründung: Der Zeitrahmen der
Modularbeit beträgt 6–8 Stunden, und jedes zusätzliche Konzept muss in der
Präsentation auch verteidigt werden können. Ein kleineres Modell, das vollständig
durchdrungen ist, ist einem grösseren vorzuziehen, dessen Details man in der
Fragerunde nicht mehr sicher erklären kann.

In einem zweiten Durchgang fielen zusätzlich weg: die Tabelle `kategorie` samt
Fremdschlüssel, der Gerätezustand (`geraet.zustand`, `zustand_ausgabe`,
`zustand_ruecknahme`) und der Tagesansatz. Mit dem Tagesansatz entfiel auch die
Funktion `fn_gebuehr()` – ohne Preis gibt es nichts zu berechnen. Das Projekt hat
seither keine Stored Function mehr.

Die Normalisierungsbegründung für die 2. Normalform musste dadurch neu gefasst
werden: Sie stützte sich zuvor auf den im Beleg eingefrorenen Tagesansatz. An
seine Stelle tritt `rueckgabe_datum`, das ebenfalls von der Kombination
(Beleg, Gerät) abhängt und nicht von einem der beiden allein. Der Nachweis bleibt
damit gültig – er steht nur auf einer anderen Spalte.

Übrig bleibt der fachliche Kern: Inventar, Ausleihe, Rückgabe – plus die beiden
Integritätsregeln, die den eigentlichen Punkt der Arbeit ausmachen.

**Eine Ergänzung kam bewusst zurück:** die Tabelle `ausleihart`. Das frühere
Freitextfeld `einsatzzweck` wurde durch einen Fremdschlüssel auf eine
kontrollierte Liste ersetzt (Demostellung, Leihgerät infolge Reparatur,
Leihgerät infolge Revision, Kapazitätsengpass, Langzeitmiete). Der Unterschied
zur entfernten Tabelle `kategorie` ist inhaltlich: `kategorie` sortierte
Gerätetypen und war für den Verleihvorgang ohne Bedeutung, während die
Ausleihart die Geschäftstransaktion selbst klassifiziert und Auswertungen
ermöglicht. Der Einzelfall steht weiterhin frei in `ausleihe.bemerkung`.
