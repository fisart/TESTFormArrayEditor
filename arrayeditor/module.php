<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        // Unkritische Pfade
        $this->RegisterPropertyString("KeyFolderPath", "");
        // Verschlüsselter Tresor
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
        $this->LogMessage("--- Formular-Laden (RequestAction-Way) ---", KL_MESSAGE);

        // 1. Daten laden und flachklopfen für die UI-Liste
        $nestedData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);

        $form = [
            "elements" => [
                [
                    "type" => "ValidationTextBox", 
                    "name" => "KeyFolderPath", 
                    "caption" => "Ordner für master.key"
                ]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Nested Tresor (Nutze '/' im Ident für Pfade)",
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
                    "caption" => "🔓 Tresor verschlüsselt speichern",
                    /**
                     * DER SUPPORT-WEG:
                     * Wir rufen IPS_RequestAction auf. Das ist eine System-Funktion.
                     * Wir wandeln die Liste in JSON um, damit $Value ein String ist.
                     */
                    "onClick" => "IPS_RequestAction(\$id, 'SaveVault', json_encode(\$VaultEditor));"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * DAS ZENTRALE EINGANGSTOR (RequestAction)
     * Verarbeitet Befehle von der UI.
     */
    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "SaveVault":
                // $Value ist hier der JSON-String vom Button
                $this->ProcessSaveVault($Value);
                break;

            default:
                throw new Exception("Action '$Ident' nicht bekannt.");
        }
    }

    /**
     * INTERNE SPEICHER-LOGIK
     */
    private function ProcessSaveVault(string $JsonData) {
        $this->LogMessage("--- Start Speichern via RequestAction ---", KL_MESSAGE);
        
        $inputList = json_decode($JsonData, true);
        if (!is_array($inputList)) {
            $this->LogMessage("SaveVault: JSON-Fehler", KL_ERROR);
            return;
        }

        $finalNestedArray = [];
        foreach ($inputList as $row) {
            $path = (string)($row['Ident'] ?? '');
            $secret = (string)($row['Secret'] ?? '');

            if ($path === "") continue;

            // Pfad-Logik anwenden
            $parts = explode('/', $path);
            $temp = &$finalNestedArray;
            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
            $temp = $secret;
        }

        $encrypted = $this->EncryptData($finalNestedArray);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("Nested Vault gespeichert. Einträge: " . count($inputList), KL_MESSAGE);
            echo "✅ Tresor erfolgreich gespeichert!";
        }
    }

    // =========================================================================
    // ÖFFENTLICHE API (GetSecret)
    // =========================================================================

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

    public function GetIdentifiers(): string {
        $nestedData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);
        return json_encode(array_column($flatValues, 'Ident'));
    }

    // =========================================================================
    // HILFSFUNKTIONEN (Flattening & Krypto)
    // =========================================================================

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
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
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
        return json_decode($dec ?: '[]', true) ?: [];
    }
}