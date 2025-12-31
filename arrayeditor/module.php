<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();

        // Property für den Pfad (nicht geheim)
        $this->RegisterPropertyString("KeyFolderPath", "");

        // Attribut für die verschlüsselten Daten (disk-clean, da nur verschlüsselt befüllt)
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Status-Prüfung
        if ($this->ReadPropertyString("KeyFolderPath") == "") {
            $this->SetStatus(104); // Inaktiv
        } else {
            $this->SetStatus(102); // Aktiv
        }
    }

    /**
     * Baut das Formular dynamisch auf.
     */
    public function GetConfigurationForm(): string {
        $form = [
            "elements" => [
                [
                    "type" => "ValidationTextBox", 
                    "name" => "KeyFolderPath", 
                    "caption" => "Ordner für master.key (z.B. /var/lib/symcon/)"
                ]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Verschlüsselter Editor)",
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
                    // Wir laden die aktuellen Daten aus dem verschlüsselten Attribut
                    "values" => $this->DecryptData($this->ReadAttributeString("EncryptedVault"))
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor jetzt verschlüsseln & speichern",
                    // WICHTIG: JSON.stringify wandelt das UI-Objekt in Text um, bevor es an PHP geht
                    "onClick" => "AVT_UpdateVault(\$id, JSON.stringify(\$VaultEditor));"
                ],
                [
                    "type" => "Button",
                    "caption" => "🔍 Test: Ident 'Test' auslesen",
                    "onClick" => "echo 'Geheimnis für Test: ' . AVT_GetSecret(\$id, 'Test');"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Empfängt die Liste als JSON-String, verschlüsselt sie und speichert sie im Attribut.
     */
    public function UpdateVault(string $VaultEditor): void {
        
        $this->SendDebug("UpdateVault", "Empfange Daten...", 0);

        // 1. JSON-String in Array umwandeln
        $data = json_decode($VaultEditor, true);

        if (!is_array($data)) {
            $this->SendDebug("UpdateVault", "FEHLER: Ungültiges JSON-Format.", 0);
            echo "❌ Fehler: Daten konnten nicht verarbeitet werden!";
            return;
        }

        // 2. Verschlüsseln
        $encryptedBlob = $this->EncryptData($data);
        
        if ($encryptedBlob === "") {
            echo "❌ Fehler: Verschlüsselung fehlgeschlagen (Key/Pfad prüfen)!";
            return;
        }

        // 3. In Attribut schreiben (Symcon persistiert das automatisch in settings.json)
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        $this->SendDebug("UpdateVault", "Erfolgreich im Attribut gespeichert.", 0);
        echo "✅ Tresor mit " . count($data) . " Einträgen sicher verschlüsselt gespeichert!";
    }

    /**
     * Öffentliche API zum Auslesen eines Geheimnisses in Skripten.
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

    // =========================================================================
    // KRYPTOGRAPHIE
    // =========================================================================

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
        
        $ciphertext = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($ciphertext === false) return "";

        return json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
            "data" => base64_encode($ciphertext)
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];

        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];

        $decrypted = openssl_decrypt(
            base64_decode($decoded['data']),
            "aes-128-gcm",
            hex2bin($keyHex),
            OPENSSL_RAW_DATA,
            hex2bin($decoded['iv']),
            hex2bin($decoded['tag'])
        );

        if ($decrypted === false) return [];

        return json_decode($decrypted, true) ?: [];
    }
}