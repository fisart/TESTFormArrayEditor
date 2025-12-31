<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        
        // Property für den Pfad (nicht geheim, bleibt in settings.json sichtbar)
        $this->RegisterPropertyString("KeyFolderPath", "");
        
        // Attribut für die Daten (wird von uns nur verschlüsselt befüllt)
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        // Statusprüfung für die Konsole
        if ($this->ReadPropertyString("KeyFolderPath") == "") {
            $this->SetStatus(104); // Inaktiv
        } else {
            $this->SetStatus(102); // Aktiv
        }
    }

    public function GetConfigurationForm(): string {
        // 1. Daten aus verschlüsseltem Attribut für die UI laden
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $decryptedValues = $this->DecryptData($encrypted);

        $form = [
            "elements" => [
                [
                    "type" => "ValidationTextBox", 
                    "name" => "KeyFolderPath", 
                    "caption" => "Ordner für master.key (Pfad muss existieren)"
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
                        [
                            "caption" => "Bezeichnung", 
                            "name" => "Ident", 
                            "width" => "200px", 
                            "add" => "", 
                            "edit" => ["type" => "ValidationTextBox"]
                        ],
                        [
                            "caption" => "Geheimnis", 
                            "name" => "Secret", 
                            "width" => "auto", 
                            "add" => "", 
                            "edit" => ["type" => "PasswordTextBox"]
                        ]
                    ],
                    "values" => $decryptedValues
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor verschlüsseln & speichern",
                    // WICHTIG: json_encode() wandelt das Objekt IPSList in einen String um
                    "onClick" => "AVT_UpdateVault(\$id, json_encode(\$VaultEditor));"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Empfängt die Daten aus der UI.
     * Durch json_encode im onClick kommt hier garantiert ein 'string' an.
     */
    public function UpdateVault(string $VaultEditor): void {
        
        // Debug-Logs immer mit 3 Parametern! (Letzter Parameter 0 = Text)
        $this->SendDebug("UpdateVault", "Empfange Daten: " . $VaultEditor, 0);

        $data = json_decode($VaultEditor, true);

        if (!is_array($data)) {
            $this->SendDebug("Error", "Daten konnten nicht dekodiert werden.", 0);
            echo "❌ Fehler: Ungültiges Datenformat!";
            return;
        }

        // Verschlüsseln
        $encryptedBlob = $this->EncryptData($data);
        
        if ($encryptedBlob === "") {
            $this->SendDebug("Error", "Verschlüsselung fehlgeschlagen. Pfad/Key prüfen.", 0);
            echo "❌ Fehler: Verschlüsselung fehlgeschlagen!";
            return;
        }

        // Im Attribut speichern (Persistenz ohne Property-Zwang)
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        $this->SendDebug("Success", "Verschlüsselter Tresor im Attribut gespeichert.", 0);
        
        // Bestätigung als Popup
        echo "✅ Tresor wurde sicher verschlüsselt und im Attribut gespeichert.";
    }

    /**
     * API für Skripte
     */
    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) {
                return (string)$entry['Secret'];
            }
        }
        return "";
    }

    // --- KRYPTOGRAPHIE ---

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";

        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        
        if (!file_exists($path)) {
            $newKey = bin2hex(random_bytes(16));
            if (@file_put_contents($path, $newKey) === false) return "";
            return $newKey;
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

        return json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
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

        if ($dec === false) return [];

        return json_decode($dec, true) ?: [];
    }
}