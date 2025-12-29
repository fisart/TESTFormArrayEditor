<?php
declare(strict_types=1);

class TestModul extends IPSModuleStrict {

    public function Create(): void {
        parent::Create();
        // Wir speichern die Testdaten im RAM-Buffer
        $this->SetBuffer("TestData", json_encode([
            ['Key' => 'Eintrag_A', 'Value' => 'Geheimnis 1', 'Action' => '📂 Open'],
            ['Key' => 'Eintrag_B', 'Value' => 'Geheimnis 2', 'Action' => '📂 Open'],
            ['Key' => 'Ordner_C',  'Value' => 'Ich bin ein Ordner', 'Action' => '📂 Open']
        ]));
    }

    public function GetConfigurationForm(): string {
        $json = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        
        // Wir befüllen die Liste aus dem Buffer
        $data = json_decode($this->GetBuffer("TestData"), true);
        $json['elements'][0]['values'] = $data;

        return json_encode($json);
    }

    public function FillData(): void {
        $this->ReloadForm();
    }

    // Wir empfangen NUR den Index als Ganzzahl (Integer)
    public function HandleClick(int $index): void {
        
        // 1. Wir holen uns die Daten aus unserem eigenen Buffer
        $data = json_decode($this->GetBuffer("TestData"), true);

        // 2. Wir prüfen, ob der Index existiert
        if (!isset($data[$index])) {
            echo "Fehler: Zeile $index nicht im Buffer gefunden.";
            return;
        }

        // 3. Den Namen aus dem Buffer-Eintrag lesen
        $name = $data[$index]['Key'];

        // 4. ERFOLG!
        echo "ERFOLG! PHP hat Zeile $index erkannt. Der Key heißt: '$name'";
        
        $this->LogMessage("Klick auf Index $index erkannt. Name: $name", KL_MESSAGE);
    }
}