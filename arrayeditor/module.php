<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();

        // Property für den Pfad zum Key (nicht geheim)
        $this->RegisterPropertyString("KeyFolderPath", "");

        // ATTRIBUT für die verschlüsselten Daten (landet in settings.json, aber verschlüsselt)
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    /**
     * Baut das Formular dynamisch auf.
     */
    public function GetConfigurationForm(): string {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // 1. Daten aus Attribut laden und entschlüsseln
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $decryptedData = $this->DecryptData($encrypted);

        // 2. Editor-Liste zu den ACTIONS hinzufügen
        $form['actions'][] = [
            "type" => "List",
            "name" => "VaultEditor",
            "caption" => "Geheimnis-Tresor",
            "rowCount" => 5,
            "add" => true,
            "delete" => true,
            "columns" => [
                [
                    "caption" => "Bezeichnung",
                    "name" => "Ident",
                    "width" => "200px",
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Geheimnis",
                    "name" => "Secret",
                    "width" => "auto",
                    "edit" => ["type" => "PasswordTextBox"]
                ]
            ],
            "values" => $decryptedData,
            "onChange" => "AVT_UpdateVault(\$id, \$VaultEditor);"
        ];

        return json_encode($form);
    }

    /**
     * Wird bei jeder Änderung in der Liste sofort aufgerufen.
     */
    public function UpdateVault(array $VaultEditor): void {
        // Die Liste kommt als Array von Objekten an
        $jsonString = json_encode($VaultEditor);
        
        // Verschlüsseln
        $encrypted = $this->EncryptData($VaultEditor);
        
        // In Attribut speichern
        $this->WriteAttributeString("EncryptedVault", $encrypted);
        
        // Optional für das Log:
        // $this->LogMessage("Vault wurde aktualisiert und verschlüsselt gespeichert.", KL_MESSAGE);
    }

    // --- HILFSFUNKTIONEN FÜR KRYPTO ---

    private function GetMasterKey(): string {
        $path = rtrim($this->ReadPropertyString("KeyFolderPath"), '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            // Falls kein Key da ist, generieren wir einen (nur für Testzwecke hier direkt)
            $newKey = bin2hex(random_bytes(16));
            @file_put_contents($path, $newKey);
            return $newKey;
        }
        return trim(file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $key = hex2bin($this->GetMasterKey());
        $plain = json_encode($data);
        $iv = random_bytes(12);
        $tag = "";
        
        $ciphertext = openssl_encrypt($plain, "aes-128-gcm", $key, 0, $iv, $tag);
        
        return json_encode([
            "iv" => bin2hex($iv),
            "tag" => bin2hex($tag),
            "data" => $ciphertext
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded) return [];

        $key = hex2bin($this->GetMasterKey());
        
        $decrypted = openssl_decrypt(
            $decoded['data'],
            "aes-128-gcm",
            $key,
            0,
            hex2bin($decoded['iv']),
            hex2bin($decoded['tag'])
        );

        return json_decode($decrypted, true) ?: [];
    }
}