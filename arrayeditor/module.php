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
        // Daten aus Attribut laden für die UI
        $decryptedValues = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));

        $form = [
            "elements" => [
                [
                    "type" => "ValidationTextBox", 
                    "name" => "KeyFolderPath", 
                    "caption" => "Ordner für master.key"
                ]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Verschlüsselt im Attribut)",
                    "rowCount" => 8,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Bezeichnung (Ident)", "name" => "Ident", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Geheimnis (Secret)", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
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
     * ÖFFENTLICHE API: Liest ein Geheimnis aus einem Skript aus.
     * Aufruf: AVT_GetSecret($id, "MeinIdent");
     */
    public function GetSecret(string $Ident): string {
        // 1. Gesamten Tresor entschlüsseln
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        // 2. Den passenden Ident suchen
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) {
                return (string)$entry['Secret'];
            }
        }
        
        // Falls nichts gefunden wurde
        return "";
    }

    /**
     * ÖFFENTLICHE API: Gibt alle vorhandenen Idents (Namen) zurück.
     * Hilfreich, um zu sehen, was im Tresor ist, ohne die Passwörter zu laden.
     */
    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $keys = array_column($data, 'Ident');
        return json_encode($keys);
    }

    /**
     * INTERN: Wird vom Button aufgerufen
     */
    public function UpdateVault(string $VaultEditor): void {
        $data = json_decode($VaultEditor, true);
        if (!is_array($data)) {
            $this->LogMessage("UpdateVault: JSON-Fehler", KL_ERROR);
            return;
        }

        $encrypted = $this->EncryptData($data);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("Tresor erfolgreich im Attribut gespeichert.", KL_MESSAGE);
            echo "✅ Gespeichert!";
        }
    }

    // =========================================================================
    // KRYPTOGRAPHIE
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            @file_put_contents($path, $key);
            return $key;
        }
        return trim((string)file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return "";
        $plain = json_encode($data);
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return "";
        return json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']));
        return json_decode($dec ?: '[]', true) ?: [];
    }
}