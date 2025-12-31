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
                    "caption" => "Geheimnis-Tresor",
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
                ],
                [
                    "type" => "Button",
                    "caption" => "🔍 Test: Ident 'Test' auslesen",
                    "onClick" => "echo AVT_GetSecret(\$id, 'Test');"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Diese Funktion wird durch den Button "Tresor jetzt verschlüsseln" aufgerufen.
     */
    public function UpdateVault(array $VaultEditor): void {
        // 1. Notfall-Log ins Haupt-Meldungsfenster
        IPS_LogMessage("SecretsManager", "UpdateVault wurde aufgerufen! Zeilen: " . count($VaultEditor));
        
        // 2. Debug-Log (im Debug-Tab der Instanz)
        $this->SendDebug("UpdateVault", "Empfangene Daten: " . json_encode($VaultEditor), 0);

        // 3. Verschlüsseln
        $encryptedBlob = $this->EncryptData($VaultEditor);
        
        if ($encryptedBlob === "") {
            $this->SendDebug("UpdateVault", "ERROR: Verschlüsselung schlug fehl!", 0);
            return;
        }

        // 4. In Attribut schreiben
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        $this->SendDebug("UpdateVault", "Erfolgreich im Attribut gespeichert.", 0);
        
        echo "✅ Tresor erfolgreich gespeichert!";
    }

    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) return (string)$entry['Secret'];
        }
        return "Nicht gefunden!";
    }

    // --- Krypto ---

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            file_put_contents($path, $key);
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
        return json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']));
        return json_decode($dec, true) ?: [];
    }
}