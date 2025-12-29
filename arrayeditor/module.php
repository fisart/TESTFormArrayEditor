<?php
declare(strict_types=1);

class TestModul extends IPSModuleStrict {

    public function Create(): void {
        parent::Create();
        // Start-Daten im RAM-Buffer
        $this->SetBuffer("TestData", json_encode([
            ['Key' => 'Eintrag_A', 'Value' => 'Geheimnis 1', 'Action' => '📂 Open'],
            ['Key' => 'Eintrag_B', 'Value' => 'Geheimnis 2', 'Action' => '📂 Open'],
            ['Key' => 'Ordner_C',  'Value' => 'Ich bin ein Ordner', 'Action' => '📂 Open']
        ]));
    }

    public function GetConfigurationForm(): string {
        $json = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        
        // Daten injizieren
        $data = json_decode($this->GetBuffer("TestData"), true);
        $json['elements'][0]['values'] = $data;

        return json_encode($json);
    }

    public function FillData(): void {
        $this->ReloadForm();
    }

    public function HandleClick(array $TestList): void {
        // In Symcon 8.1 enthält $TestList bei onEdit:
        // 'row' => Index der Zeile
        // 'values' => Alle Zeilen als Array
        
        $index = $TestList['row'];
        $rows  = $TestList['values'];

        if ($index < 0 || !isset($rows[$index])) {
            echo "Keine Zeile ausgewählt.";
            return;
        }

        $name = $rows[$index]['Key'];

        // POPUP ERZWINGEN
        echo "ERFOLG! Du hast auf '$name' (Zeile $index) geklickt.";
        
        $this->LogMessage("Klick auf Name: " . $name, KL_MESSAGE);
    }
}