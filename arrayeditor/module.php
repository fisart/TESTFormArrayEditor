<?php
declare(strict_types=1);

class TestModul extends IPSModuleStrict {

    public function Create(): void {
        parent::Create();
        // Wir nutzen einen Buffer, um die Liste im RAM zu halten
        $this->SetBuffer("TestData", json_encode([
            ['Key' => 'Eintrag_A', 'Value' => 'Geheimnis 1'],
            ['Key' => 'Eintrag_B', 'Value' => 'Geheimnis 2'],
            ['Key' => 'Ordner_C',  'Value' => 'Ich bin ein Ordner']
        ]));
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm(): string {
        $json = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        
        // Daten beim Laden direkt in die Liste injizieren
        $data = json_decode($this->GetBuffer("TestData"), true);
        $json['elements'][0]['values'] = $data;

        return json_encode($json);
    }

    // Diese Funktion füllt die Daten manuell (zum Testen)
    public function FillData(): void {
        $this->ReloadForm();
    }

    // DAS IST DIE ENTSCHEIDENDE FUNKTION
    public function HandleClick(array $TestList): void {
        // 1. Index der angeklickten Zeile holen
        $index = $TestList['row'];
        $rows  = $TestList['values'];

        // 2. Prüfen ob gültig
        if ($index < 0 || !isset($rows[$index])) {
            echo "Fehler: Keine Zeile erkannt.";
            return;
        }

        // 3. Den Key auslesen
        $name = $rows[$index]['Key'];

        // 4. BEWEIS: Ein Popup im Browser anzeigen
        echo "ERFOLG! Du hast auf '$name' geklickt.";

        // 5. DIAGNOSE: Ins Log schreiben
        $this->LogMessage("Klick auf Index $index erkannt. Name: $name", KL_MESSAGE);
    }
}