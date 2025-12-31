<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();

        // Pfad zum master.key (Klartext-Property, da nicht geheim)
        $this->RegisterPropertyString("KeyFolderPath", "");

        // ATTRIBUT für die verschlüsselten Daten
        // Attribute landen in der settings.json, werden aber von uns nur verschlüsselt befüllt.
        $this->RegisterAttributeString("EncryptedVault", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Status setzen: Wenn Pfad leer, dann Instanz inaktiv
        if ($this->ReadPropertyString("KeyFolderPath") == "") {
            $this->SetStatus(104); // IS_INACTIVE
        } else {
            $this->SetStatus(102); // IS_ACTIVE
        }
    }

    /**
     * Baut das Formular dynamisch auf.
     * Der Editor wird in den 'actions' Bereich verschoben, um Properties zu umgehen.
     */
    public function GetConfigurationForm(): string {
        // Lade die statische Vorlage
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // 1. Daten aus dem Attribut laden und im RAM entschlüsseln
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $decryptedData = $this->DecryptData($encrypted);

        // 2. Den Editor als LISTE in den ACTIONS-Bereich injizieren
        $form['actions'][] = [
            "type" => "List",
            "name" => "VaultEditor",
            "caption" => "Geheimnis-Tresor (Auto-Save via Attribute)",
            "rowCount" => 8,
            "add" => true,
            "delete" => true,
            "columns" => [
                [
                    "caption" => "Bezeichnung",
                    "name" => "Ident",
                    "width" => "200px",
                    "add" => "", // WICHTIG: Verhindert den "Could not add node" Fehler
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Geheimnis",
                    "name" => "Secret",
                    "width" => "auto",
                    "add" => "", // WICHTIG: Verhindert den "Could not add node" Fehler
                    "edit" => ["type" => "PasswordTextBox"]
                ]
            ],
            "values" => $decryptedData,
            // Bei jeder Änderung wird sofort die Funktion UpdateVault aufgerufen
            "onChange" => "AVT_UpdateVault(\$id, \$VaultEditor);"
        ];

        return json_encode($form);
    }

    /**
     * Diese Funktion wird vom UI-Event 'onChange' aufgerufen.
     * Sie verschlüsselt die gesamte Liste und schreibt sie in das Attribut.
     */
    public function UpdateVault(array $VaultEditor): void {
        // Daten verschlüsseln
        $encryptedBlob = $this->EncryptData($VaultEditor);
        
        // In das interne Attribut schreiben
        $this->WriteAttributeString("EncryptedVault", $encryptedBlob);
        
        // Optional: Logge die Änderung (ohne Klartext!)
        // $this->LogMessage("Vault aktualisiert: " . count($VaultEditor) . " Einträge verschlüsselt gespeichert.", KL_MESSAGE);
    }

    // =========================================================================
    // KRYPTOGRAPHIE HILFSFUNKTIONEN
    // =========================================================================

    /**
     * Lädt den Schlüssel aus der Datei oder generiert einen neuen.
     */
    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "") return "";

        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        
        if (!file_exists($path)) {
            $newKey = bin2hex(random_bytes(16)); // 128-bit Key
            if (@file_put_contents($path, $newKey) === false) {
                throw new Exception("Schlüsseldatei konnte nicht erstellt werden. Pfad schreibgeschützt?");
            }
            return $newKey;
        }
        
        return trim(file_get_contents($path));
    }

    /**
     * AES-128-GCM Verschlüsselung
     */
    private function EncryptData(array $data): string {
        $masterKeyHex = $this->GetMasterKey();
        if ($masterKeyHex === "") return "";

        $key = hex2bin($masterKeyHex);
        $plain = json_encode($data);
        
        $iv = random_bytes(12);
        $tag = ""; // Wird von openssl_encrypt befüllt
        
        $ciphertext = openssl_encrypt($plain, "aes-128-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        return json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
            "data" => base64_encode($ciphertext)
        ]);
    }

    /**
     * AES-128-GCM Entschlüsselung
     */
    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];

        try {
            $masterKeyHex = $this->GetMasterKey();
            if ($masterKeyHex === "") return [];

            $key = hex2bin($masterKeyHex);
            
            $decrypted = openssl_decrypt(
                base64_decode($decoded['data']),
                "aes-128-gcm",
                $key,
                OPENSSL_RAW_DATA,
                hex2bin($decoded['iv']),
                hex2bin($decoded['tag'])
            );

            if ($decrypted === false) return [];

            return json_decode($decrypted, true) ?: [];
        } catch (Exception $e) {
            $this->SendDebug("Decrypt Error", $e->getMessage(), 0);
            return [];
        }
    }

    /**
     * Liest ein einzelnes Geheimnis anhand der Bezeichnung aus.
     * Aufrufbar via: AVT_GetSecret($id, "MeineBezeichnung");
     */
    public function GetSecret(string $Ident): string {
        // 1. Verschlüsselten Tresor aus dem Attribut laden
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        
        // 2. Entschlüsseln (nutzt die vorhandene DecryptData Funktion)
        $data = $this->DecryptData($encrypted);
        
        // 3. Nach dem Ident suchen
        foreach ($data as $entry) {
            if (isset($entry['Ident']) && $entry['Ident'] === $Ident) {
                return (string)$entry['Secret'];
            }
        }
        
        // Wenn nichts gefunden wurde
        $this->LogMessage("Geheimnis mit Ident '$Ident' wurde nicht gefunden.", KL_WARNING);
        return "";
    }
}