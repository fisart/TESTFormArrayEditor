<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    /**
     * DYNAMISCHES FORMULAR
     */
    public function GetConfigurationForm(): string {
        $this->LogMessage("--- FORMULAR-LADEN GESTARTET ---", KL_MESSAGE);

        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $this->LogMessage("Rohdaten im Attribut (Länge): " . strlen($encrypted), KL_MESSAGE);

        $nestedData = $this->DecryptData($encrypted);
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);
        
        $this->LogMessage("Anzahl Zeilen für UI geladen: " . count($flatValues), KL_MESSAGE);

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Nested Tresor (Disk-Clean / RequestAction)",
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Pfad (Ident)", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Wert (Secret)", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $flatValues
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor verschlüsseln & speichern",
                    "onClick" => "IPS_RequestAction(\$id, 'SaveVault', json_encode(\$VaultEditor));"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * DAS EINGANGSTOR (RequestAction)
     */
    public function RequestAction($Ident, $Value) {
        $this->LogMessage("--- RequestAction aufgerufen! Ident: " . $Ident, KL_MESSAGE);

        switch ($Ident) {
            case "SaveVault":
                // NEU: Diese Zeile zeigt uns den exakten Inhalt der Liste aus der UI
                $this->LogMessage("Inhalt JSON: " . $Value, KL_MESSAGE);
                
                $this->LogMessage("Empfangener JSON-String (Länge): " . strlen((string)$Value), KL_MESSAGE);
                
                $data = json_decode((string)$Value, true);
                if (is_array($data)) {
                    $this->ProcessSaveVault($data);
                } else {
                    $this->LogMessage("FEHLER: JSON-Daten konnten nicht dekodiert werden!", KL_ERROR);
                }
                break;

            default:
                throw new Exception("Unbekannter Ident: " . $Ident);
        }
    }

    /**
     * VERARBEITUNG & VERSCHLÜSSELUNG
     */
    private function ProcessSaveVault(array $inputList) {
        $this->LogMessage("Verarbeite " . count($inputList) . " Zeilen aus der UI...", KL_MESSAGE);

        $finalNestedArray = [];
        $count = 0;
        foreach ($inputList as $row) {
            $path = (string)($row['Ident'] ?? '');
            $secret = (string)($row['Secret'] ?? '');

            if ($path === "") continue;

            $parts = explode('/', $path);
            $temp = &$finalNestedArray;
            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
            $temp = $secret;
            $count++;
        }

        $this->LogMessage("Struktur aufgebaut. Starte Verschlüsselung...", KL_MESSAGE);

        $encrypted = $this->EncryptData($finalNestedArray);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            $verify = $this->ReadAttributeString("EncryptedVault");
            $this->LogMessage("ERFOLG: Tresor gespeichert. Attribut-Länge auf Disk: " . strlen($verify), KL_MESSAGE);
            
            echo "✅ Tresor erfolgreich gespeichert!";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung lieferte kein Ergebnis!", KL_ERROR);
        }
    }

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        foreach ($parts as $part) {
            if (isset($data[$part])) {
                $data = $data[$part];
            } else {
                return "";
            }
        }
        return is_string($data) ? $data : (json_encode($data) ?: "");
    }

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

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("Key-Error: Pfad ungültig!", KL_ERROR);
            return "";
        }
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $this->LogMessage("Key-Info: Erzeuge neue master.key Datei.", KL_MESSAGE);
            $key = bin2hex(random_bytes(16));
            @file_put_contents($path, $key);
        }
        return trim((string)file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return "";
        $plain = json_encode($data);
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        return ($cipher === false) ? "" : json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        if ($dec === false) {
             $this->LogMessage("Decrypt: Fehlgeschlagen!", KL_ERROR);
             return [];
        }
        return json_decode($dec, true) ?: [];
    }
}