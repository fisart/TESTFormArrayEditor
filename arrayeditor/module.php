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
                    "caption" => "Geheimnis-Tresor (Deep-Debug)",
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
                    // WICHTIG: Wir senden das Objekt direkt. PHP muss es wandeln.
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ];
        return json_encode($form);
    }

    /**
     * SPEICHERN MIT DEEP DEBUG
     * Kein Type-Hint beim Parameter, um IPSList zu erlauben.
     */
    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- START SPEICHERN ---", KL_MESSAGE);
        
        // 1. Typ-Prüfung des Eingangs-Objekts
        $type = gettype($VaultEditor);
        $this->LogMessage("Eingangs-Typ vom Browser: " . $type, KL_MESSAGE);

        $data = [];
        
        // 2. Manuelle Konvertierung des Objekts
        try {
            $count = 0;
            foreach ($VaultEditor as $index => $row) {
                $count++;
                $ident = $row['Ident'] ?? 'FEHLT';
                $secret = $row['Secret'] ?? 'FEHLT';
                
                $this->LogMessage("Verarbeite Zeile $index: Ident=[$ident]", KL_MESSAGE);
                
                $data[] = [
                    'Ident'  => (string)$ident,
                    'Secret' => (string)$secret
                ];
            }
            $this->LogMessage("Konvertierung abgeschlossen. Zeilen gefunden: $count", KL_MESSAGE);
        } catch (Throwable $e) {
            $this->LogMessage("FEHLER bei der Objekt-Iteration: " . $e->getMessage(), KL_ERROR);
        }

        // 3. Verschlüsselungs-Check
        if (count($data) === 0) {
            $this->LogMessage("ABBRUCH: Keine Daten zum Verschlüsseln vorhanden (Array leer).", KL_WARNING);
            echo "Keine Daten zum Speichern gefunden!";
            return;
        }

        $encrypted = $this->EncryptData($data);
        
        if ($encrypted !== "") {
            // 4. Persistenz-Check
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Sofort-Test: Können wir das eben geschriebene wieder lesen?
            $verify = $this->ReadAttributeString("EncryptedVault");
            $this->LogMessage("Speichern beendet. Attribut-Länge auf Disk: " . strlen($verify), KL_MESSAGE);
            
            echo "✅ Tresor wurde mit " . count($data) . " Zeilen gespeichert.";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung schlug fehl (EncryptData lieferte Leerstring).", KL_ERROR);
            echo "Verschlüsselungsfehler!";
        }
    }

    /**
     * API ZUM AUSLESEN
     */
    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && trim(strtolower($entry['Ident'])) === trim(strtolower($Ident))) {
                return (string)$entry['Secret'];
            }
        }
        return "";
    }

    // =========================================================================
    // KRYPTO-ENGINE MIT LOGS
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("Krypto-Key: Ordner-Pfad ungültig: " . $folder, KL_ERROR);
            return "";
        }
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $this->LogMessage("Krypto-Key: Erzeuge neue master.key Datei.", KL_MESSAGE);
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
        
        if ($cipher === false) {
            $this->LogMessage("Encrypt: openssl_encrypt Fehler!", KL_ERROR);
            return "";
        }

        return json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
            "data" => base64_encode($cipher)
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) {
            $this->LogMessage("Decrypt: JSON Struktur im Attribut ungültig.", KL_ERROR);
            return [];
        }

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
            $this->LogMessage("Decrypt: openssl_decrypt fehlgeschlagen!", KL_ERROR);
            return [];
        }

        return json_decode($dec, true) ?: [];
    }
}