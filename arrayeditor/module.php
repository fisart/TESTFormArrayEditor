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
        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Auto-Save)",
                    "rowCount" => 5,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Ident", "name" => "Ident", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Secret", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $this->DecryptData($this->ReadAttributeString("EncryptedVault"))
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor jetzt verschlüsseln & speichern",
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * WICHTIG: Das 'array' vor $VaultEditor wurde entfernt, 
     * da IP-Symcon hier oft einen JSON-String oder ein Objekt liefert.
     */
    public function UpdateVault($VaultEditor): void {
        // 1. Umwandlung: Wenn es ein String (JSON) ist, decodieren. Sonst als Array casten.
        $data = is_string($VaultEditor) ? json_decode($VaultEditor, true) : (array)$VaultEditor;

        // Validierung
        if (!is_array($data)) {
            $this->SendDebug("UpdateVault", "Fehler: Daten konnten nicht in ein Array umgewandelt werden.", 0);
            echo "❌ Fehler beim Verarbeiten der Daten!";
            return;
        }

        $this->SendDebug("UpdateVault", "Empfangene Zeilen: " . count($data), 0);

        // 2. Verschlüsseln
        $encryptedBlob = $this->EncryptData($data);
        
        if ($encryptedBlob === "") {
            echo "❌ Fehler: Verschlüsselung fehlgeschlagen!";
            return;
        }

        // 3. In Attribut schreiben
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        $this->SendDebug("UpdateVault", "Erfolgreich gespeichert.", 0);
        echo "✅ Tresor erfolgreich verschlüsselt und gespeichert!";
    }

    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) return (string)$entry['Secret'];
        }
        return "";
    }

    // --- Krypto-Logik (unverändert stabil) ---

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            @file_put_contents($path, $key);
            return $key;
        }
        return trim(file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return "";
        $plain = json_encode($data);
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag);
        return json_encode([
            "iv" => bin2hex($iv),
            "tag" => bin2hex($tag),
            "data" => base64_encode($cipher)
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        
        $dec = openssl_decrypt(
            base64_decode($decoded['data']), 
            "aes-128-gcm", 
            hex2bin($keyHex), 
            OPENSSL_RAW_DATA, 
            hex2bin($decoded['iv']), 
            hex2bin($decoded['tag'])
        );
        return json_decode($dec, true) ?: [];
    }
}