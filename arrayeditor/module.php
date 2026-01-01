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

    public function GetConfigurationForm(): string {
        // Daten für die UI laden
        $nestedData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);

        return json_encode([
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Nested Tresor (Disk-Clean)",
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
                    /**
                     * WICHTIG: json_encode sorgt für einen String-Parameter.
                     * Dadurch passt der Typ 'string' in der PHP-Funktion.
                     */
                    "onClick" => "AVT_UpdateVault(\$id, json_encode(\$VaultEditor));"
                ]
            ]
        ]);
    }

    /**
     * WICHTIG: 'string' entfernt die gelbe PHPLibrary-Warnung.
     */
    public function UpdateVault(string $VaultEditor): void {
        $this->LogMessage("--- START SPEICHERVORGANG ---", KL_MESSAGE);
        
        $inputList = json_decode($VaultEditor, true);
        if (!is_array($inputList)) {
            $this->LogMessage("FEHLER: Ungültiges JSON-Format empfangen.", KL_ERROR);
            return;
        }

        // Falls nur ein Objekt geschickt wurde, in Array packen
        if (isset($inputList['Ident'])) {
            $inputList = [$inputList];
        }

        $finalNestedArray = [];
        foreach ($inputList as $index => $row) {
            $path = (string)($row['Ident'] ?? '');
            $secret = (string)($row['Secret'] ?? '');

            if ($path === "") continue;

            $this->LogMessage("Verarbeite Zeile $index: Pfad='$path', Secret-Länge=" . strlen($secret), KL_MESSAGE);

            // Robuster Aufbau des verschachtelten Arrays
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

        // BEWEIS-LOG 1: Was wurde im RAM zusammengebaut?
        $this->LogMessage("DEBUG: Klartext-Array vor Verschlüsselung: " . json_encode($finalNestedArray), KL_MESSAGE);

        $encrypted = $this->EncryptData($finalNestedArray);
        
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // BEWEIS-LOG 2: Sofortige Gegenprüfung
            $verify = $this->DecryptData($encrypted);
            $this->LogMessage("DEBUG: Verifizierung nach Decrypt: " . json_encode($verify), KL_MESSAGE);
            
            $this->LogMessage("ERFOLG: " . count($inputList) . " Pfade verschlüsselt gespeichert.", KL_MESSAGE);
            echo "✅ Tresor erfolgreich gespeichert!";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung fehlgeschlagen.", KL_ERROR);
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

    // =========================================================================
    // INTERNE HELFER
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