<?php
declare(strict_types=1);

/* =====================================================================
   MediVerleih - ERD-Generator

   Liest das Schema aus information_schema und erzeugt daraus ein
   Entity-Relationship-Diagramm im Mermaid-Format.

   Der Punkt dabei: Das Diagramm wird nicht gezeichnet, sondern aus den
   tatsaechlich vorhandenen Fremdschluesseln abgeleitet. Es kann deshalb
   nicht vom Schema abweichen - auch nicht nach einer spaeteren
   Aenderung. Was im Bild als Linie erscheint, ist eine echte
   FOREIGN-KEY-Constraint in InnoDB.

   Aufruf auf dem Server:
       sudo php deploy/erd-generieren.php > docs/erd.md
       sudo php deploy/erd-generieren.php andere_db > erd.md
   ===================================================================== */

$db = $argv[1] ?? 'mediverleih';

$pdo = new PDO(
    'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=information_schema;charset=utf8mb4',
    'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* ---------------------------------------------------------------------
   Beziehungsbeschriftungen.

   Die Struktur kommt aus der Datenbank, die Wortwahl nicht - "produziert"
   steht nirgends im Schema. Diese Zuordnung ist deshalb bewusst von Hand
   gepflegt. Fehlt ein Eintrag, faellt das Diagramm auf "1:n" zurueck und
   bleibt trotzdem korrekt.
   --------------------------------------------------------------------- */
const BESCHRIFTUNG = [
    'geraetetyp.hersteller_id'     => 'produziert',
    'geraet.geraetetyp_id'         => 'hat Exemplar',
    'kunde.institution_id'         => 'beschaeftigt',
    'ausleihe.kunde_id'            => 'taetigt',
    'ausleihe.ausleihart_id'       => 'klassifiziert',
    'ausleihe_position.ausleihe_id'=> 'enthaelt',
    'ausleihe_position.geraet_id'  => 'betrifft',
];

// ------------------------------------------------------- Tabellen
$tabellen = $pdo->prepare(
    "SELECT table_name FROM tables
      WHERE table_schema = ? AND table_type = 'BASE TABLE'
      ORDER BY table_name"
);
$tabellen->execute([$db]);
$tabellen = array_column($tabellen->fetchAll(), 'table_name');

if (!$tabellen) {
    fwrite(STDERR, "Keine Tabellen in Datenbank '$db' gefunden.\n");
    exit(1);
}

// ------------------------------------------------------- Spalten
$spalten = $pdo->prepare(
    "SELECT table_name, column_name, data_type, is_nullable,
            column_key, extra, column_comment
       FROM columns
      WHERE table_schema = ?
      ORDER BY table_name, ordinal_position"
);
$spalten->execute([$db]);

$nachTabelle = [];
foreach ($spalten->fetchAll() as $s) {
    $nachTabelle[$s['table_name']][] = $s;
}

// ------------------------------------------------------- Fremdschluessel
$fks = $pdo->prepare(
    "SELECT kcu.table_name, kcu.column_name, kcu.referenced_table_name,
            kcu.referenced_column_name, kcu.constraint_name,
            c.is_nullable, rc.delete_rule, rc.update_rule
       FROM key_column_usage kcu
       JOIN columns c
         ON c.table_schema = kcu.table_schema
        AND c.table_name   = kcu.table_name
        AND c.column_name  = kcu.column_name
       JOIN referential_constraints rc
         ON rc.constraint_schema = kcu.table_schema
        AND rc.constraint_name   = kcu.constraint_name
      WHERE kcu.table_schema = ?
        AND kcu.referenced_table_name IS NOT NULL
      ORDER BY kcu.referenced_table_name, kcu.table_name"
);
$fks->execute([$db]);
$fks = $fks->fetchAll();

/** Welche Spalten sind Fremdschluessel? */
$istFk = [];
foreach ($fks as $fk) {
    $istFk[$fk['table_name'] . '.' . $fk['column_name']] = true;
}

/* ---------------------------------------------------------------------
   Aufgeloeste n:m-Beziehungen erkennen.

   Zwei Fremdschluessel allein genuegen NICHT als Merkmal - "ausleihe" hat
   ebenfalls zwei (auf kunde und auf ausleihart) und ist trotzdem eine
   eigenstaendige Entitaet. Das entscheidende Merkmal ist ein
   zusammengesetzter UNIQUE ueber genau die Fremdschluesselspalten: Er
   sagt "diese Kombination darf es nur einmal geben" - und das ist die
   Definition einer Zuordnung.
   --------------------------------------------------------------------- */
$uniques = $pdo->prepare(
    "SELECT tc.table_name, tc.constraint_name, kcu.column_name
       FROM table_constraints tc
       JOIN key_column_usage kcu
         ON kcu.constraint_schema = tc.constraint_schema
        AND kcu.constraint_name   = tc.constraint_name
        AND kcu.table_name        = tc.table_name
      WHERE tc.table_schema = ?
        AND tc.constraint_type = 'UNIQUE'
      ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position"
);
$uniques->execute([$db]);

$uniqueSpalten = [];
foreach ($uniques->fetchAll() as $u) {
    $uniqueSpalten[$u['table_name']][$u['constraint_name']][] = $u['column_name'];
}

$aufloesungen = [];
foreach ($uniqueSpalten as $tabelle => $constraints) {
    foreach ($constraints as $spalten) {
        if (count($spalten) < 2) {
            continue;
        }
        // Alle Spalten des UNIQUE muessen Fremdschluessel sein
        $ziele = [];
        foreach ($spalten as $sp) {
            foreach ($fks as $fk) {
                if ($fk['table_name'] === $tabelle && $fk['column_name'] === $sp) {
                    $ziele[] = $fk['referenced_table_name'];
                }
            }
        }
        if (count($ziele) === count($spalten)) {
            $aufloesungen[$tabelle] = $ziele;
        }
    }
}

/**
 * Kardinalitaet aus dem Schema ableiten, nicht aus der Absicht:
 *   FK NOT NULL -> genau eine Elternzeile      ||
 *   FK NULL     -> keine oder eine Elternzeile |o
 * Auf der Kindseite steht immer "null bis viele" (o{), denn mehr
 * erzwingt eine Fremdschluesselbeziehung technisch nicht.
 */
function kardinalitaet(string $nullable): string
{
    return $nullable === 'NO' ? '||--o{' : '|o--o{';
}

function label(string $tabelle, string $spalte): string
{
    return BESCHRIFTUNG[$tabelle . '.' . $spalte] ?? '1:n';
}

// =====================================================================
//  Ausgabe
// =====================================================================

$datum = date('d.m.Y H:i');

echo <<<KOPF
# Entity-Relationship-Diagramm

**Datenbank:** `$db`
**Erzeugt am:** $datum

> Dieses Dokument wird von `deploy/erd-generieren.php` aus
> `information_schema` erzeugt. Jede Linie im Diagramm entspricht einer
> tatsächlich vorhandenen `FOREIGN KEY`-Constraint in InnoDB – das
> Diagramm kann deshalb nicht vom Schema abweichen.
>
> Neu erzeugen:
> ```bash
> sudo php deploy/erd-generieren.php > docs/erd.md
> ```

---

KOPF;

// ---------------------------------------------------------------------
// 1. Notation. Statischer Text, deshalb NOWDOC - nichts wird ersetzt.
// ---------------------------------------------------------------------
echo <<<'LESEN'

## 1. Das Diagramm lesen

Die Diagramme nutzen die **Krähenfuss-Notation** (crow's foot). Die Zeichen stehen
an beiden Enden einer Linie und geben an, wie viele Zeilen der jeweiligen Tabelle
beteiligt sind.

```
||   genau eins
|o   null oder eins
o{   null oder mehr
|{   eins oder mehr
```

Der senkrechte Strich steht für *eins*, das `o` für *null*, und die geschweifte
Klammer ist der namensgebende Krähenfuss – sie zeigt immer auf die n-Seite.

### Leserichtung

Gelesen wird von links nach rechts, mit dem Verb dazwischen:

```
HERSTELLER ||--o{ GERAETETYP : "produziert"
```

- Ein Hersteller produziert **null bis viele** Gerätetypen.
- Ein Gerätetyp hat **genau einen** Hersteller.

Beide Aussagen gelten gleichzeitig. Ein Strich sind immer zwei Aussagen, eine je
Richtung.

### Woher die Kardinalitäten kommen

Sie sind nicht gesetzt, sondern abgelesen:

```
Fremdschluesselspalte ist NOT NULL   ->  ||   genau eins
Fremdschluesselspalte erlaubt NULL   ->  |o   null oder eins
immer auf der Kindseite              ->  o{   null bis viele
```

Die Kindseite ist immer `o{`, nie `|{`. Das ist keine Nachlässigkeit, sondern
korrekt: Ein Fremdschlüssel erzwingt technisch nie eine Mindestanzahl. Dass in
der Praxis kein Beleg ohne Position entsteht, garantiert die Transaktion in der
zuständigen Stored Procedure – nicht das Schema.

LESEN;

// ---------------------------------------------------------------------
// Aufgeloeste n:m-Beziehungen, aus der Anzahl Fremdschluessel abgeleitet
// ---------------------------------------------------------------------
if ($aufloesungen) {
    echo "\n### Wo die n:m-Beziehungen stecken\n\n";
    echo "Eine n:m-Beziehung erscheint im Diagramm nicht als eine Linie, sondern als\n"
       . "zwei, die auf dieselbe Tabelle zeigen. Erkennbar ist eine solche\n"
       . "Auflösungstabelle an einem **zusammengesetzten UNIQUE über ihre\n"
       . "Fremdschlüssel** – er besagt, dass es jede Kombination nur einmal geben\n"
       . "darf. Zwei Fremdschlüssel allein genügen als Merkmal nicht: `ausleihe`\n"
       . "hat ebenfalls zwei und ist trotzdem eine eigenständige Entität.\n\n";

    foreach ($aufloesungen as $tabelle => $ziele) {
        printf("- `%s` löst `%s` auf\n", $tabelle, implode("` & `", array_unique($ziele)));
    }
    echo "\n";
}

echo <<<'UEBER'

---

## 2. Übersicht

Nur Entitäten und Beziehungen – für Präsentationsfolie und Einstieg.

```mermaid
erDiagram

UEBER;

foreach ($fks as $fk) {
    printf("    %-18s %s %-18s : \"%s\"\n",
        strtoupper($fk['referenced_table_name']),
        kardinalitaet($fk['is_nullable']),
        strtoupper($fk['table_name']),
        label($fk['table_name'], $fk['column_name'])
    );
}

echo "```\n\n---\n\n## 3. Mit Attributen\n\n";
echo "Vollständiges Modell mit Spalten.\n\n"
   . "| Kürzel | Bedeutung |\n|---|---|\n"
   . "| `PK` | Primärschlüssel |\n"
   . "| `FK` | Fremdschlüssel – entspricht einer Linie im Diagramm |\n"
   . "| `UK` | eindeutig (UNIQUE), aber kein Primärschlüssel |\n"
   . "| `optional` | Spalte erlaubt NULL |\n"
   . "| `berechnet` | Generated Column, wird nie von Hand gefüllt |\n\n"
   . "```mermaid\nerDiagram\n";

foreach ($fks as $fk) {
    printf("    %-18s %s %-18s : \"%s\"\n",
        strtoupper($fk['referenced_table_name']),
        kardinalitaet($fk['is_nullable']),
        strtoupper($fk['table_name']),
        label($fk['table_name'], $fk['column_name'])
    );
}

echo "\n";

foreach ($tabellen as $t) {
    echo '    ' . strtoupper($t) . " {\n";
    foreach ($nachTabelle[$t] as $s) {
        $schluessel = '';
        if ($s['column_key'] === 'PRI') {
            $schluessel = 'PK';
        } elseif (isset($istFk[$t . '.' . $s['column_name']])) {
            $schluessel = 'FK';
        } elseif ($s['column_key'] === 'UNI') {
            $schluessel = 'UK';
        }

        $notiz = [];
        if (str_contains($s['extra'], 'GENERATED')) {
            $notiz[] = 'berechnet';
        }
        if ($s['is_nullable'] === 'YES') {
            $notiz[] = 'optional';
        }

        printf("        %-9s %-24s %-3s %s\n",
            $s['data_type'],
            $s['column_name'],
            $schluessel,
            $notiz ? '"' . implode(', ', $notiz) . '"' : ''
        );
    }
    echo "    }\n\n";
}

echo "```\n\n---\n\n## 4. Beziehungen im Detail\n\n";
echo "| Von | Spalte | Nach | Pflicht | ON DELETE | ON UPDATE |\n";
echo "|---|---|---|---|---|---|\n";

foreach ($fks as $fk) {
    printf("| `%s` | `%s` | `%s` | %s | %s | %s |\n",
        $fk['table_name'],
        $fk['column_name'],
        $fk['referenced_table_name'],
        $fk['is_nullable'] === 'NO' ? 'ja' : 'nein (NULL erlaubt)',
        $fk['delete_rule'],
        $fk['update_rule']
    );
}

$anzTabellen = count($tabellen);
$anzFks      = count($fks);

echo <<<FUSS


### Was aus dieser Tabelle abzulesen ist

- **`kunde.institution_id` erlaubt NULL** – das ist die technische Umsetzung von
  "Privatperson oder Institution". Im Diagramm erscheint diese Beziehung
  deshalb als *null oder eine* (`|o`) statt *genau eine* (`||`).
- **`ON DELETE RESTRICT`** an fast allen Stellen: Ein Gerät mit Ausleihhistorie
  lässt sich nicht löschen. Die Historie bleibt nachvollziehbar.
- **`ON DELETE CASCADE`** nur bei `ausleihe_position` → `ausleihe`: Positionen
  ohne Beleg wären sinnlos, sie verschwinden mit ihm.
- **Fehlendes `ON UPDATE CASCADE`** bei `kunde.institution_id` und
  `ausleihe_position.geraet_id`: Diese Spalten werden in einem CHECK-Constraint
  bzw. in einer berechneten Spalte verwendet, und MariaDB verbietet dort
  referentielle Aktionen (Fehler 1901, siehe `docs/implementierung.md`).

---

## 5. Kennzahlen

| | |
|---|---|
| Tabellen | $anzTabellen |
| Fremdschlüsselbeziehungen | $anzFks |

FUSS;
