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
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $decryptedValues = $this->DecryptData($encrypted);

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
                    "caption" => "🔓 Tresor speichern (LogMessage-Test)",
                    // Wir übergeben die Liste direkt. IPS macht daraus ein IPSList-Objekt.
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Wir verzichten auf den Type-Hint 'string' oder 'array', um IPSList-Objekte zu akzeptieren.
     */
    public function UpdateVault($VaultEditor): void {
        
        // 1. Logge den Start der Funktion ins Meldungsfenster
        $this->LogMessage("UpdateVault wurde aufgerufen!", KL_MESSAGE);

        // 2. Umwandlung des Objekts in ein lesbares Array
        // Das IPSList-Objekt lässt sich über json_encode serialisieren
        $json = json_encode($VaultEditor);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            $this->LogMessage("FEHLER: Daten konnten nicht verarbeitet werden!", KL_ERROR);
            echo "Fehler beim Verarbeiten der Liste.";
            return;
        }

        $this->LogMessage("Anzahl empfanger Zeilen: " . count($data), KL_MESSAGE);

        // 3. Verschlüsseln
        $encryptedBlob = $this->EncryptData($data);
        
        if ($encryptedBlob === "") {
            $this->LogMessage("FEHLER: Verschlüsselung fehlgeschlagen (Key-Pfad prüfen)!", KL_ERROR);
            echo "Fehler: Verschlüsselung fehlgeschlagen!";
            return;
        }

        // 4. In Attribut schreiben
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        $this->LogMessage("ERFOLG: Tresor wurde im Attribut gespeichert.", KL_MESSAGE);
        echo "✅ Tresor wurde sicher gespeichert!";
    }

    // --- Krypto ---

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
        try {
            $keyHex = $this->GetMasterKey();
            if ($keyHex === "") return "";
            $plain = json_encode($data);
            $iv = random_bytes(12);
            $tag = "";
            $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) return "";
            return json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
        } catch (Exception $e) {
            return "";
        }
    }

    private function DecryptedVault(): array {
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']));
        return json_decode($dec ?: '[]', true) ?: [];
    }

    private function DecryptData(string $encrypted): array {
        // Hilfsfunktion für die Form-Anzeige
        return $this->DecryptedVault();
    }
}