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
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $nestedData = $this->DecryptData($encrypted);
        
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
                    "caption" => "Verschachtelter Tresor (Disk-Clean)",
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
                     * WICHTIG: KEIN json_encode in der UI nutzen! 
                     * Nur die direkte Übergabe erlaubt PHP den Zugriff auf die Daten.
                     */
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ]);
    }

    /**
     * WICHTIG: KEIN 'string' Typ-Hint! 
     * Nur so akzeptiert PHP das IPSList-Objekt ohne Absturz.
     */
    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- START SPEICHERVORGANG ---", KL_MESSAGE);
        
        $finalNestedArray = [];
        $count = 0;

        // Manuelle Iteration durch das Objekt (funktioniert nur ohne json_encode in UI)
        foreach ($VaultEditor as $index => $row) {
            $path = (string)($row['Ident'] ?? $row['ident'] ?? '');
            $secret = (string)($row['Secret'] ?? $row['secret'] ?? '');

            if ($path === "") continue;

            $this->LogMessage("Verarbeite Zeile $index: Pfad='$path', Secret-Länge=" . strlen($secret), KL_MESSAGE);

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

        $this->LogMessage("DEBUG: Klartext-Array vor Verschlüsselung: " . json_encode($finalNestedArray), KL_MESSAGE);

        $encrypted = $this->EncryptData($finalNestedArray);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Sofort-Check
            $verify = $this->DecryptData($encrypted);
            $this->LogMessage("DEBUG: Verifizierung nach Decrypt: " . json_encode($verify), KL_MESSAGE);
            
            $this->LogMessage("ERFOLG: $count Pfade verschlüsselt gespeichert.", KL_MESSAGE);
            echo "✅ Tresor erfolgreich gespeichert!";
        }
    }

    /**
     * API: Ein Secret auslesen. Pfad-basiert.
     */
    public function GetSecret(string $Path): string {
        $this->LogMessage("GetSecret Suche nach Pfad: " . $Path, KL_MESSAGE);
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $parts = explode('/', $Path);
        $current = $data;
        
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                $this->LogMessage("Pfad-Teil '$part' nicht gefunden.", KL_WARNING);
                return "";
            }
        }
        
        $val = is_string($current) ? $current : (json_encode($current) ?: "");
        if ($val !== "") $this->LogMessage("Erfolg: Wert für '$Path' geladen.", KL_MESSAGE);
        return $val;
    }

    /**
     * API: Alle Identifiers auflisten.
     */
    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flat = [];
        $this->FlattenArray($data, "", $flat);
        return json_encode(array_column($flat, 'Ident'));
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
            file_put_contents($path, $key);
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