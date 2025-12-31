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
        $this->SendDebug("UI_Update", "Lade Formular...", 0);
        
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // 1. Daten laden
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $this->SendDebug("UI_Load", "Rohdaten aus Attribut: " . $encrypted, 0);
        
        $decryptedData = $this->DecryptData($encrypted);
        $this->SendDebug("UI_Load", "Entschlüsselte Einträge: " . count($decryptedData), 0);

        // 2. Editor injizieren
        $form['actions'][] = [
            "type" => "List",
            "name" => "VaultEditor",
            "caption" => "Geheimnis-Tresor (Auto-Save)",
            "rowCount" => 8,
            "add" => true,
            "delete" => true,
            "columns" => [
                ["caption" => "Bezeichnung", "name" => "Ident", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                ["caption" => "Geheimnis", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
            ],
            "values" => $decryptedData,
            "onChange" => "AVT_UpdateVault(\$id, \$VaultEditor);"
        ];

        return json_encode($form);
    }

    /**
     * Kern-Funktion: Empfängt Daten aus der UI
     */
    public function UpdateVault(array $VaultEditor): void {
        $this->SendDebug("UpdateVault", "Daten von UI empfangen. Anzahl Zeilen: " . count($VaultEditor), 0);
        
        if (count($VaultEditor) > 0) {
            $this->SendDebug("UpdateVault", "Erster Ident: " . ($VaultEditor[0]['Ident'] ?? 'unbekannt'), 0);
        }

        // Verschlüsseln
        $encryptedBlob = $this->EncryptData($VaultEditor);
        
        if ($encryptedBlob === "") {
            $this->SendDebug("UpdateVault", "FEHLER: Verschlüsselung fehlgeschlagen (Key fehlt?)", 0);
            return;
        }

        // In Attribut schreiben
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        $this->SendDebug("UpdateVault", "Verschlüsselter Blob im Attribut gespeichert.", 0);
    }

    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) return (string)$entry['Secret'];
        }
        return "";
    }

    // =========================================================================
    // KRYPTO MIT DEBUGGING
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "") {
            $this->SendDebug("Crypto_Key", "Pfad leer!", 0);
            return "";
        }
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        
        if (!file_exists($path)) {
            $newKey = bin2hex(random_bytes(16));
            if (@file_put_contents($path, $newKey) === false) {
                $this->SendDebug("Crypto_Key", "Konnte Key nicht schreiben auf: " . $path, 0);
                return "";
            }
            $this->SendDebug("Crypto_Key", "Neuer Key generiert.", 0);
            return $newKey;
        }
        return trim(file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        try {
            $masterKeyHex = $this->GetMasterKey();
            if ($masterKeyHex === "") return "";

            $key = hex2bin($masterKeyHex);
            $plain = json_encode($data);
            $iv = random_bytes(12);
            $tag = ""; 
            
            $ciphertext = openssl_encrypt($plain, "aes-128-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
            
            $result = json_encode([
                "iv"   => bin2hex($iv),
                "tag"  => bin2hex($tag),
                "data" => base64_encode($ciphertext)
            ]);
            
            $this->SendDebug("Crypto_Encrypt", "Erfolgreich verschlüsselt.", 0);
            return $result;
        } catch (Exception $e) {
            $this->SendDebug("Crypto_Encrypt", "Error: " . $e->getMessage(), 0);
            return "";
        }
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) {
            $this->SendDebug("Crypto_Decrypt", "Format ungültig oder leer.", 0);
            return [];
        }

        try {
            $masterKeyHex = $this->GetMasterKey();
            if ($masterKeyHex === "") return [];

            $decrypted = openssl_decrypt(
                base64_decode($decoded['data']),
                "aes-128-gcm",
                hex2bin($masterKeyHex),
                OPENSSL_RAW_DATA,
                hex2bin($decoded['iv']),
                hex2bin($decoded['tag'])
            );

            if ($decrypted === false) {
                $this->SendDebug("Crypto_Decrypt", "Entschlüsselung fehlgeschlagen (falscher Key?)", 0);
                return [];
            }

            return json_decode($decrypted, true) ?: [];
        } catch (Exception $e) {
            $this->SendDebug("Crypto_Decrypt", "Error: " . $e->getMessage(), 0);
            return [];
        }
    }
}