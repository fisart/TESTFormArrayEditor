<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        // Pfad für den master.key (Klartext-Property)
        $this->RegisterPropertyString("KeyFolderPath", "");
        // Verschlüsselter Tresor im Attribut (Disk-Clean)
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $decryptedValues = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));

        return json_encode([
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Verschlüsselt)",
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
                    // WICHTIG: Wir übergeben das Objekt direkt. In PHP iterieren wir manuell.
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ]);
    }

    // =========================================================================
    // ÖFFENTLICHE API (ZUM AUSLESEN)
    // =========================================================================

    /**
     * Liest ein Geheimnis aus einem Skript aus.
     * Beispiel: $pass = AVT_GetSecret($id, "artur");
     */
    public function GetSecret(string $Ident): string {
        $this->LogMessage("GetSecret Suche nach: " . $Ident, KL_MESSAGE);
        
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        foreach ($data as $entry) {
            // Wir prüfen alle Varianten der Schreibweise (Ident / ident)
            $rowIdent = $entry['Ident'] ?? $entry['ident'] ?? '';
            
            if (trim(strtolower((string)$rowIdent)) === trim(strtolower($Ident))) {
                // Wir haben den Ident gefunden! Jetzt den Wert (Secret / secret)
                $val = $entry['Secret'] ?? $entry['secret'] ?? '';
                
                if ($val === "") {
                    // Debug-Log, falls wir zwar den Ident finden, aber das Secret leer ist
                    $this->LogMessage("Ident '$Ident' gefunden, aber Secret ist leer! Keys in diesem Array: " . implode(", ", array_keys($entry)), KL_WARNING);
                } else {
                    $this->LogMessage("Treffer für '$Ident' gefunden.", KL_MESSAGE);
                }
                return (string)$val;
            }
        }
        
        $this->LogMessage("Kein Treffer für Ident '$Ident' im Tresor.", KL_WARNING);
        return "";
    }

    /**
     * Gibt alle Namen im Tresor als JSON-Array zurück.
     */
    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $keys = [];
        foreach ($data as $entry) {
            $keys[] = $entry['Ident'] ?? $entry['ident'] ?? 'unbekannt';
        }
        return json_encode($keys);
    }

    // =========================================================================
    // INTERNE SPEICHER-LOGIK
    // =========================================================================

    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- START SPEICHERN ---", KL_MESSAGE);
        
        $data = [];
        // WICHTIG: Manuelle Iteration durch das IPSList-Objekt behebt den Datenverlust
        foreach ($VaultEditor as $index => $row) {
            $ident = $row['Ident'] ?? $row['ident'] ?? '';
            $secret = $row['Secret'] ?? $row['secret'] ?? '';
            
            if ($ident !== "") {
                $this->LogMessage("Speichere Zeile $index: Ident=[$ident]", KL_MESSAGE);
                $data[] = [
                    'Ident'  => (string)$ident,
                    'Secret' => (string)$secret
                ];
            }
        }

        if (count($data) === 0) {
            $this->LogMessage("Speichern abgebrochen: Keine Daten zum Verschlüsseln vorhanden.", KL_WARNING);
            echo "Fehler: Liste ist leer.";
            return;
        }

        $encrypted = $this->EncryptData($data);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("Tresor erfolgreich im Attribut gespeichert. Anzahl Zeilen: " . count($data), KL_MESSAGE);
            echo "✅ Tresor wurde sicher verschlüsselt und gespeichert.";
        }
    }

    // =========================================================================
    // KRYPTO-ENGINE
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("Krypto-Key: Pfad ungültig: " . $folder, KL_ERROR);
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
        
        return ($cipher === false) ? "" : json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
            "data" => base64_encode($cipher)
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'], $decoded['iv'], $decoded['tag'])) return [];

        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];

        $dec = openssl_decrypt(
            base64_decode($decoded['data']),
            "aes-128-gcm",
            hex2bin($keyHex),
            OPENSSL_RAW_DATA,
            hex2bin($decoded['iv']),
            hex2bin($decoded['tag']),
            ""
        );

        if ($dec === false) {
            $this->LogMessage("Decrypt: Entschlüsselung fehlgeschlagen!", KL_ERROR);
            return [];
        }

        return json_decode($dec, true) ?: [];
    }
}