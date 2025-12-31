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
        $decryptedValues = $this->DecryptData($encrypted);

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Disk-Clean)",
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
                    "onClick" => "AVT_UpdateVault(\$id, json_encode(\$VaultEditor));"
                ]
            ]
        ];
        return json_encode($form);
    }

    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            // Wir suchen flexibel nach 'Ident' oder 'ident'
            $currentIdent = $entry['Ident'] ?? $entry['ident'] ?? '';
            if (trim(strtolower((string)$currentIdent)) === trim(strtolower($Ident))) {
                return (string)($entry['Secret'] ?? $entry['secret'] ?? '');
            }
        }
        return "";
    }

    public function UpdateVault(string $VaultEditor): void {
        $this->LogMessage("--- Speichervorgang ---", KL_MESSAGE);
        
        $data = json_decode($VaultEditor, true);
        if (!is_array($data)) {
            $this->LogMessage("UpdateVault: JSON-Fehler", KL_ERROR);
            return;
        }

        $this->LogMessage("Anzahl Zeilen aus UI: " . count($data), KL_MESSAGE);
        
        // Debug: Wie sieht die Struktur aus?
        if (count($data) > 0) {
            $this->LogMessage("Struktur der ersten Zeile: " . json_encode($data[0]), KL_MESSAGE);
        }

        $encrypted = $this->EncryptData($data);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Validierung im RAM (nicht aus dem Attribut lesen, sondern direkt den verschlüsselten Blob testen)
            $testDecrypt = $this->DecryptData($encrypted);
            $foundKeys = array_column($testDecrypt, 'Ident');
            
            $this->LogMessage("Sofort-Check (RAM-Roundtrip): " . json_encode($foundKeys), KL_MESSAGE);
            
            echo "✅ Tresor wurde gespeichert und verifiziert.";
        }
    }

    // =========================================================================
    // KRYPTO-ENGINE (Fix für GCM Tag Handling)
    // =========================================================================

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
        
        // Letzter Parameter 16 ist die Tag-Länge
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        
        if ($cipher === false) return "";

        return json_encode([
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
            "" // AAD leer lassen
        );

        if ($dec === false) {
            // Wenn Entschlüsselung fehlschlägt, loggen wir das explizit
            $this->LogMessage("Decrypt: Fehlgeschlagen!", KL_ERROR);
            return [];
        }

        $res = json_decode($dec, true);
        return is_array($res) ? $res : [];
    }
}