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
/**
     * WICHTIG: Der Parameter MUSS als 'string' deklariert sein,
     * damit IP-Symcon den Aufruf aus der UI akzeptiert.
     */
/**
     * WICHTIG: Wir entfernen den Type Hint 'string', um den Fatal Error zu vermeiden.
     * Das Objekt IPSList wird intern von PHP wie ein String oder ein Objekt behandelt.
     * Die Warnung im Log "hat keinen Datentyp" kann für diesen Fall ignoriert werden.
     */
/**
     * WICHTIG: Kein Datentyp (Type Hint) im Parameter!
     * Das verhindert den Fatal Error beim Aufruf durch IPS.
     */
    public function UpdateVault($VaultEditor): void {
        
        $this->SendDebug("UpdateVault", "Start der Verarbeitung...", 0);

        // 1. Umwandlung IPSList -> JSON-String -> PHP-Array
        // Wir nutzen json_encode, da das IPSList-Objekt JsonSerializable ist.
        $json = json_encode($VaultEditor);
        $data = json_decode($json, true);

        // Validierung
        if (!is_array($data)) {
            $this->SendDebug("UpdateVault", "FEHLER: Konnte IPSList nicht dekodieren. Roh: " . $json, 0);
            echo "❌ Fehler: Daten konnten nicht verarbeitet werden!";
            return;
        }

        $this->SendDebug("UpdateVault", "Anzahl Zeilen im Tresor: " . count($data), 0);

        // 2. Verschlüsselung (deine bestehende Logik)
        $encryptedBlob = $this->EncryptData($data);
        
        if ($encryptedBlob === "") {
            echo "❌ Fehler: Verschlüsselung fehlgeschlagen!";
            return;
        }

        // 3. Speichern im Attribut
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        $this->SendDebug("UpdateVault", "Speichern erfolgreich abgeschlossen.");
        
        // Popup-Bestätigung für dich
        echo "✅ Tresor wurde mit " . count($data) . " Einträgen sicher gespeichert.";
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