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
        $decryptedValues = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor",
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

    /**
     * API: Ein Secret auslesen
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

    /**
     * API: Alle Namen auflisten
     */
    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $keys = array_column($data, 'Ident');
        return json_encode($keys);
    }

    /**
     * INTERN: Speichern
     */
    public function UpdateVault(string $VaultEditor): void {
        $this->LogMessage("--- Speichervorgang ---", KL_MESSAGE);
        
        $data = json_decode($VaultEditor, true);
        if (!is_array($data)) {
            $this->LogMessage("UpdateVault: JSON-Fehler beim Einlesen der UI-Daten!", KL_ERROR);
            return;
        }

        $this->LogMessage("Anzahl Zeilen aus UI: " . count($data), KL_MESSAGE);

        $encrypted = $this->EncryptData($data);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Sofort-Check nach dem Speichern
            $checkKeys = $this->GetIdentifiers();
            $this->LogMessage("Speichern erfolgreich. Vorhandene Keys im Speicher: " . $checkKeys, KL_MESSAGE);
            
            echo "✅ Tresor gespeichert!";
        } else {
            $this->LogMessage("UpdateVault: Verschlüsselung schlug fehl!", KL_ERROR);
        }
    }

    // =========================================================================
    // KRYPTO-ENGINE
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
        $tag = ""; // Wird von PHP befüllt
        
        // GCM Verschlüsselung
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
            "",
            16
        );

        if ($dec === false) {
            $this->LogMessage("Decrypt: Entschlüsselung fehlgeschlagen!", KL_ERROR);
            return [];
        }

        return json_decode($dec, true) ?: [];
    }
}