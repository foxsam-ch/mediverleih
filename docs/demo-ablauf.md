# Demo-Ablauf für die Präsentation

Zum Ausdrucken. Jeder Schritt nennt die Klicks, den erwarteten Text und den Satz,
den du dazu sagst.

**Dauer:** 5–6 Minuten
**Kernaussage:** Die Regel liegt in der Datenbank, nicht in der Oberfläche.

---

## 0. Vorbereitung — 10 Minuten vorher

**1. Testdaten zurücksetzen.** Die Datumswerte sind relativ zu `CURDATE()` und
altern. Ohne Reset sind fast alle Ausleihen überfällig und das Dashboard sieht
unsinnig aus.

```bash
ssh mediverleih "sudo mariadb < ~/mediverleih/sql/03_testdaten.sql"
```

**2. Prüfen, dass alles läuft.**

```bash
ssh mediverleih "systemctl is-active mariadb apache2 && curl -s -o /dev/null -w '%{http_code}\n' http://localhost/"
```

Erwartung: `active`, `active`, `200`.

**3. Zwei Browser-Tabs öffnen:**

| Tab | Adresse |
|---|---|
| A | `http://192.168.1.107/` |
| B | `http://192.168.1.107/phpmyadmin` — angemeldet als `mediverleih_admin` |

**4. Zoom auf 125 % (Strg + +).** Auf dem Beamer ist 100 % zu klein.

**5. In Tab B schon zur Tabelle `geraet` navigieren**, damit du in Schritt 4 nicht
suchen musst.

> **Kein Netz oder Server tot?** Rückfallebene: Folie *Demo* überspringen, stattdessen
> die Folie *Zwei Schutzschichten* zeigen und die Fehlermeldungen vorlesen. Die
> Präsentation funktioniert auch ohne Live-Demo.

---

## Schritt 1 — Ausleihe erfassen

**Tab A → Dashboard**

Zeigen: 18 Geräte, 11 verfügbar, 6 verliehen, 2 überfällig.

> „Das ist der aktuelle Bestand. Ich leihe jetzt ein Gerät aus."

**Tab A → Geräte**

Nach `MP-0002` filtern. Status: **Verfügbar**.

> „MP-0002, ein Beatmungsgerät von Dräger. Status verfügbar."

**Tab A → Ausleihe erfassen**

| Feld | Wert |
|---|---|
| Empfänger | Marco Brunner — Kantonsspital Aarau |
| Gerät | **MP-0002 · Draegerwerk Beatmungsgeraet Evita V300** (obere Gruppe *Verfügbar*) |
| Ausleihart | Demostellung |
| Ausgabe / Rückgabe | Vorgabe lassen |
| Bemerkung | `Demo Kurstag 9` |

**→ Ausleihe erfassen**

Erwartet: grüner Balken

```
Ausleihe erfasst. Belegnummer: MV-2026-0007
```

**Tab A → Geräte**, nach `MP-0002` filtern.

> „Der Status steht jetzt auf **Verliehen**. Das hat nicht die PHP-Seite gemacht —
> die hat nur die Stored Procedure aufgerufen. Den Status gesetzt hat ein Trigger
> in der Datenbank."

---

## Schritt 2 — Dasselbe Gerät nochmals

**Tab A → Ausleihe erfassen**

Gerät **MP-0002** wählen — es steht jetzt in der unteren Gruppe
*„Nicht verfügbar – Ausleihe wird von der Datenbank abgewiesen"*.

Beliebigen Empfänger, beliebige Ausleihart. **→ Ausleihe erfassen**

Erwartet: roter Balken

```
Abgewiesen: Geraet ist nicht verfuegbar (Status pruefen).
```

> „Die Oberfläche filtert gesperrte Geräte bewusst **nicht** heraus. Sie lässt mich
> den Fehler machen — und die Datenbank weist ihn ab. Der Text kommt aus einem
> `SIGNAL` im Trigger, nicht aus dem PHP-Code."

---

## Schritt 3 — Ausgemustertes Gerät

**Tab A → Ausleihe erfassen**

Gerät **MP-0017 · Mindray Ultraschallgeraet TE7 [Ausgemustert]** wählen.
**→ Ausleihe erfassen**

Erwartet: derselbe rote Balken.

> „MP-0017 ist ausser Betrieb — Schallkopf defekt. Dieselbe Regel, ein anderer
> Status. Der Trigger prüft nicht auf einen bestimmten Fall, sondern auf die
> Bedingung *Status ist nicht verfügbar*."

---

## Schritt 4 — Der eigentliche Punkt

> „Bis jetzt hat immer der Trigger abgewiesen. Ein Trigger ist Logik — und Logik
> kann man umgehen. Ich mache das jetzt."

**Tab B → phpMyAdmin → Datenbank `mediverleih` → SQL**

Erste Abfrage — den Status fälschen:

```sql
UPDATE geraet SET status = 'verfuegbar' WHERE id = 12;
```

> „MP-0012 ist gerade an das Pflegezentrum Seeblick verliehen, Beleg MV-2026-0003.
> Ich setze den Status von Hand auf verfügbar. Der Trigger prüft nur den Status —
> den habe ich gerade zurechtgelegt. Er wird die Ausleihe also durchwinken."

Zweite Abfrage — direkt einfügen, an der Applikation vorbei:

```sql
INSERT INTO ausleihe_position (ausleihe_id, geraet_id) VALUES (4, 12);
```

Erwartet:

```
#1062 - Duplicate entry '12' for key 'uq_pos_aktiv'
```

> „Abgewiesen. Nicht vom Trigger — den habe ich ausgetrickst — sondern vom
> UNIQUE-Index auf der berechneten Spalte `aktiv_geraet_id`. Solange die erste
> Ausleihe offen ist, steht dort die 12. Ein zweiter Eintrag mit derselben 12
> verletzt den Index.
>
> Der Trigger ist die freundliche Schicht mit der verständlichen Meldung. Der Index
> ist die harte — er lässt sich nicht umgehen, ohne das Schema zu ändern."

**Aufräumen** (vor der Fragerunde, damit die Daten stimmen):

```sql
UPDATE geraet SET status = 'verliehen' WHERE id = 12;
```

---

## Optional — wenn Zeit bleibt

**Tab A → SQL-Konsole**, Schaltfläche **„Auswertung nach Ausleihart"**

> „Das ist der Grund für die Tabelle `ausleihart`. Wäre der Ausleihgrund ein
> Freitextfeld, liesse sich das nicht gruppieren."

Danach Schaltfläche **„Rechte-Demo: Rohtabelle"**

```
1142 SELECT command denied to user 'mediverleih_ro'@'localhost' for table 'geraet'
```

> „Die Konsole lässt nur SELECT durch. Aber selbst wenn diese Prüfung eine Lücke
> hätte — der Datenbankbenutzer darf die Rohtabellen gar nicht lesen. Zwei
> unabhängige Schichten, wie vorhin."

---

## Nach der Demo

Testdaten nochmals zurücksetzen, falls jemand nachfragt und du sauber starten willst:

```bash
ssh mediverleih "sudo mariadb < ~/mediverleih/sql/03_testdaten.sql"
```

---

## Spickzettel — die Werte auf einen Blick

| | |
|---|---|
| Applikation | `http://192.168.1.107/` |
| phpMyAdmin | `http://192.168.1.107/phpmyadmin` |
| DB-Benutzer phpMyAdmin | `mediverleih_admin` |
| Gerät für Schritt 1+2 | **MP-0002** (id 2) |
| Gerät für Schritt 3 | **MP-0017** (id 17, ausgemustert) |
| Gerät für Schritt 4 | **MP-0012** (id 12, verliehen auf Beleg MV-2026-0003) |
| Beleg für Schritt 4 | `ausleihe_id = 4` |
| Erwarteter Fehler Schritt 2+3 | `Geraet ist nicht verfuegbar (Status pruefen).` |
| Erwarteter Fehler Schritt 4 | `#1062 Duplicate entry '12' for key 'uq_pos_aktiv'` |
