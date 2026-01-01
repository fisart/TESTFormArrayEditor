<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        // Wir registrieren KEINE Properties für Daten.
        // Wir nutzen nur einen flüchtigen Buffer für die Session-Dauer im RAM.
        $this->SetBuffer("TempEditorData", "[]");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    /**
     * Erzeugt die Benutzeroberfläche.
     * Nutzt die Pfad-Logik (A/B/C), um Schachtelung zu simulieren.
     */
    public function GetConfigurationForm(): string {
        // Lade den aktuellen Stand aus dem RAM-Buffer
        $currentRaw = $this->GetBuffer("TempEditorData");
        $nestedData = json_decode($currentRaw, true) ?: [];
        
        // Für die flache UI-Liste flachklopfen
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);

        return json_encode([
            "elements" => [
                [
                    "type" => "Label",
                    "caption" => "EDITOR-TEST-MODUL (Stateless / Disk-Clean)"
                ],
                [
                    "type" => "Label",
                    "caption" => "Nutze Schrägstriche im Pfad für Schachtelung (z.B. Server/Web/Passwort)."
                ]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Struktur-Editor",
                    "rowCount" => 15,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Pfad (Ident)", "name" => "Ident", "width" => "400px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Wert", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                    ],
                    "values" => $flatValues,
                    "onChange" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ],
                [
                    "type" => "Button",
                    "caption" => "🚀 JSON-Struktur generieren & Loggen",
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ]);
    }

    /**
     * Kern-Funktion des Editors:
     * Wandelt die flache Liste in ein komplex geschachteltes JSON um.
     */
    public function UpdateVault($VaultEditor): void {
        $finalNestedArray = [];

        // 1. Iteration durch das UI-Objekt
        foreach ($VaultEditor as $row) {
            $path = (string)($row['Ident'] ?? '');
            $value = (string)($row['Secret'] ?? '');

            if ($path === "") continue;

            // 2. Pfad in Array-Struktur umwandeln (Nesting Logic)
            $parts = explode('/', $path);
            $temp = &$finalNestedArray;
            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
            $temp = $value;
        }

        // 3. Ergebnis als JSON-String aufbereiten
        $resultJson = json_encode($finalNestedArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // 4. AUSGABE IM MELDUNGSFENSTER (KL_MESSAGE)
        $this->LogMessage("--- EDITOR RESULTAT ---", KL_MESSAGE);
        $this->LogMessage($resultJson, KL_MESSAGE);

        // 5. Im RAM-Buffer für die aktuelle Sitzung merken
        $this->SetBuffer("TempEditorData", json_encode($finalNestedArray));
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    /**
     * Wandelt ein tiefes Array wieder in eine flache Liste für die UI um.
     */
    private function FlattenArray($array, $prefix, &$result) {
        if (!is_array($array)) return;
        foreach ($array as $key => $value) {
            $fullKey = ($prefix === "") ? (string)$key : $prefix . "/" . $key;
            if (is_array($value)) {
                $this->FlattenArray($value, $fullKey, $result);
            } else {
                $result[] = ["Ident" => $fullKey, "Secret" => $value];
            }
        }
    }
}