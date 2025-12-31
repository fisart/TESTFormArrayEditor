<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        // Pfad für den Schlüssel
        $this->RegisterPropertyString("KeyFolderPath", "");
        // Verschlüsselter Container im Attribut (Disk-Clean)
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $this->LogMessage("--- FORMULAR-LADEN ---", KL_MESSAGE);
        
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $this->LogMessage("Lese Attribut 'EncryptedVault'. Länge: " . strlen($encrypted), KL_MESSAGE);

        $decryptedValues = $this->DecryptData($encrypted);
        $this->LogMessage("Entschlüsselte Zeilen für UI: " . count($decryptedValues), KL_MESSAGE);

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Deep-Debug / Disk-Clean)",
                    "rowCount" => 8,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Ident", "name" => "Ident", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Secret", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $decryptedValues
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor verschlüsseln & speichern",
                    // Wir senden das IPSList Objekt direkt an PHP
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ];
        return json_encode($form);
    }

    // =========================================================================
    // ÖFFENTLICHE API FÜR SKRIPTE
    // =========================================================================

    /**
     * Liest ein Geheimnis aus einem Skript aus.
     * Beispiel: $pass = AVT_GetSecret($id, "WLAN_Key");
     */
    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            // Prüfung Case-Insensitive und Trimmed
            $currentIdent = $entry['Ident'] ?? '';
            if (trim(strtolower((string)$currentIdent)) === trim(strtolower($Ident))) {
                return (string)($entry['Secret'] ?? '');
            }
        }
        return "";
    }

    /**
     * Listet alle Namen (Idents) im Tresor auf.
     * Beispiel: $keys = AVT_GetIdentifiers($id);
     */
    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $keys = array_column($data, 'Ident');
        return json_encode($keys);
    }

    // =========================================================================
    // INTERNE SPEICHER-LOGIK
    // =========================================================================

    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- START SPEICHERN ---", KL_MESSAGE);
        
        $type = gettype($VaultEditor);
        $this->LogMessage("Datentyp von UI: " . $type, KL_MESSAGE);

        $data = [];
        try {
            $count = 0;
            // Manuelle Iteration durch das IPSList-Objekt
            foreach ($VaultEditor as $index => $row) {
                $count++;
                $ident = $row['Ident'] ?? 'FEHLT';
                $secret = $row['Secret'] ?? 'FEHLT';
                
                $this->LogMessage("Zeile $index: Ident=[$ident]", KL_MESSAGE);
                
                $data[] = [
                    'Ident'  => (string)$ident,
                    'Secret' => (string)$secret
                ];
            }
            $this->LogMessage("Wandlung fertig. Zeilen: $count", KL_MESSAGE);
        } catch (Throwable $e) {
            $this->LogMessage("FEHLER bei Wandlung: " . $e->getMessage(), KL_ERROR);
        }

        if (count($data) === 0) {
            $this->LogMessage("Abbruch: Keine Daten gefunden.", KL_WARNING);
            echo "Fehler: Liste leer.";
            return;
        }

        $encrypted = $this->EncryptData($data);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Verifizierung
            $verify = $this->ReadAttributeString("EncryptedVault");
            $this->LogMessage("Gespeichert. Attribut-Länge: " . strlen($verify), KL_MESSAGE);
            
            echo "✅ Erfolgreich verschlüsselt und gespeichert.";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung schlug fehl!", KL_ERROR);
            echo "Verschlüsselungsfehler!";
        }
    }

    // =========================================================================
    // KRYPTO-ENGINE
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("Key-Error: Pfad ungültig: $folder", KL_ERROR);
            return "";
        }
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