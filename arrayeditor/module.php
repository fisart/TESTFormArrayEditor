<?php
declare(strict_types=1);

class TestModul extends IPSModuleStrict {

    public function Create(): void {
        parent::Create();
        // Wir markieren die Instanz als "Entsperrt" für den Test
        $this->SetBuffer("IsUnlocked", "true");
        $this->SetBuffer("TestData", json_encode([
            ['Key' => 'Eintrag_A', 'Value' => 'Geheimnis 1'],
            ['Key' => 'Eintrag_B', 'Value' => 'Geheimnis 2'],
            ['Key' => 'Ordner_C',  'Value' => 'Ich bin ein Ordner']
        ]));
    }

    public function GetConfigurationForm(): string {
        $json = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        
        // Liste befüllen
        $data = json_decode($this->GetBuffer("TestData"), true) ?: [];
        $values = [];
        foreach ($data as $row) {
            $values[] = [
                'Key'    => $row['Key'],
                'Value'  => $row['Value'],
                // TRICK: Wir schreiben den Namen des Keys in die Action-Zelle
                'Action' => $row['Key'] 
            ];
        }
        $json['elements'][0]['values'] = $values;

        return json_encode($json);
    }

    // Wir empfangen jetzt einen STRING (den Namen aus der Zelle)
    public function HandleClick(string $Name): void {
        if ($Name === "") {
            echo "Fehler: Kein Name empfangen.";
            return;
        }

        // BEWEIS-POPUP
        echo "ERFOLG! PHP hat den Namen '" . $Name . "' direkt aus der Zelle erhalten.";
        
        $this->LogMessage("Klick auf Name: " . $Name, KL_MESSAGE);
    }
}