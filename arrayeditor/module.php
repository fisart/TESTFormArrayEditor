<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        // Properties nur für unkritische Daten
        $this->RegisterPropertyString("KeyFolderPath", "");
        // Attribute für die verschlüsselten Geheimnisse
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        // Daten aus dem Attribut laden für die Anzeige
        $decryptedValues = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));

        $form = [
            "elements" => [
                [
                    "type" => "ValidationTextBox", 
                    "name" => "KeyFolderPath", 
                    "caption" => "Ordner für master.key (Pfad muss existieren!)"
                ]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Actions-Bereich / Disk-Clean)",
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
                    /** 
                     * WICHTIG: json_encode($VaultEditor) wandelt das IPS-Objekt in einen String um.
                     * Damit akzeptiert der PHP-Linter den Typ 'string' und es gibt keinen TypeError.
                     */
                    "onClick" => "AVT_UpdateVault(\$id, json_encode(\$VaultEditor));"
                ]
            ]
        ];
        return json_encode($form);
    }

    /**
     * WICHTIG: Der Parameter MUSS 'string' sein, um die Linter-Warnung zu entfernen.
     */
    public function UpdateVault(string $VaultEditor): void {
        
        // Debugging via LogMessage (sichtbar im Meldungsfenster unten)
        $this->LogMessage("UpdateVault wurde getriggert.", KL_MESSAGE);

        $data = json_decode($VaultEditor, true);

        if (!is_array($data)) {
            $this->LogMessage("FEHLER: JSON-Daten konnten nicht dekodiert werden.", KL_ERROR);
            echo "Fehler: Ungültiges Datenformat!";
            return;
        }

        $this->LogMessage("Verarbeite " . count($data) . " Zeilen aus dem Tresor.", KL_MESSAGE);

        // Verschlüsseln
        $encrypted = $this->EncryptData($data);
        
        if ($encrypted !== "") {
            // Im Attribut speichern (Support-Empfehlung)
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("Tresor erfolgreich im Attribut gespeichert.", KL_MESSAGE);
            echo "✅ Erfolgreich gespeichert!";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung fehlgeschlagen. Ist der Key-Pfad korrekt?", KL_ERROR);
            echo "Fehler: Verschlüsselung fehlgeschlagen!";
        }
    }

    public function GetSecret(string $Ident): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) return (string)$entry['Secret'];
        }
        return "";
    }

    // =========================================================================
    // INTERNE KRYPTO-LOGIK
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("MasterKey Fehler: Ordner nicht gefunden: " . $folder, KL_WARNING);
            return "";
        }
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            if (@file_put_contents($path, $key) === false) return "";
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