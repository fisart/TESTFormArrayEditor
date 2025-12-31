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
        $this->LogMessage("--- Formular wird geladen ---", KL_MESSAGE);

        // 1. Rohdaten aus Attribut lesen
        $encrypted = $this->ReadAttributeString("EncryptedVault");
        $this->LogMessage("Rohdaten im Attribut (Länge): " . strlen($encrypted), KL_MESSAGE);

        // 2. Entschlüsseln mit detailliertem Logging
        $decryptedValues = $this->DecryptData($encrypted);
        $this->LogMessage("Anzahl geladener Zeilen nach Decrypt: " . count($decryptedValues), KL_MESSAGE);

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Actions)",
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
                    "onClick" => "AVT_UpdateVault(\$id, json_encode(\$VaultEditor));"
                ]
            ]
        ];
        return json_encode($form);
    }

    public function UpdateVault(string $VaultEditor): void {
        $this->LogMessage("--- Speichervorgang gestartet ---", KL_MESSAGE);

        $data = json_decode($VaultEditor, true);
        if (!is_array($data)) {
            $this->LogMessage("FEHLER: JSON-Dekodierung der Liste fehlgeschlagen!", KL_ERROR);
            return;
        }

        // Verschlüsseln
        $encrypted = $this->EncryptData($data);
        
        if ($encrypted !== "") {
            // Im Attribut speichern
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            
            // Sofortige Kontrolle: Wurde es geschrieben?
            $check = $this->ReadAttributeString("EncryptedVault");
            $this->LogMessage("Kontrolle nach Write: Attribut-Länge ist " . strlen($check), KL_MESSAGE);
            
            $this->LogMessage("Tresor erfolgreich im Attribut gespeichert.", KL_MESSAGE);
            echo "✅ Gespeichert!";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung lieferte leeres Ergebnis.", KL_ERROR);
            echo "❌ Verschlüsselungsfehler!";
        }
    }

    // =========================================================================
    // KRYPTO MIT DETAIL-LOGS
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) {
            $this->LogMessage("Krypto-Key: Pfad ungültig oder leer!", KL_WARNING);
            return "";
        }
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $this->LogMessage("Krypto-Key: Datei master.key existiert nicht. Erzeuge neue...", KL_MESSAGE);
            $key = bin2hex(random_bytes(16));
            file_put_contents($path, $key);
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
        
        if ($cipher === false) {
            $this->LogMessage("Krypto-Encrypt: openssl_encrypt fehlgeschlagen!", KL_ERROR);
            return "";
        }

        return json_encode([
            "iv"   => bin2hex($iv),
            "tag"  => bin2hex($tag),
            "data" => base64_encode($cipher)
        ]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") {
            $this->LogMessage("Krypto-Decrypt: Attribut ist leer, überspringe.", KL_MESSAGE);
            return [];
        }

        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) {
            $this->LogMessage("Krypto-Decrypt: JSON-Struktur im Attribut ungültig!", KL_ERROR);
            return [];
        }

        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") {
            $this->LogMessage("Krypto-Decrypt: Kein MasterKey vorhanden!", KL_ERROR);
            return [];
        }

        $dec = openssl_decrypt(
            base64_decode($decoded['data']),
            "aes-128-gcm",
            hex2bin($keyHex),
            OPENSSL_RAW_DATA,
            hex2bin($decoded['iv']),
            hex2bin($decoded['tag'])
        );

        if ($dec === false) {
            $this->LogMessage("Krypto-Decrypt: openssl_decrypt fehlgeschlagen! (Key oder IV falsch)", KL_ERROR);
            return [];
        }

        $res = json_decode($dec, true);
        if ($res === null) {
            $this->LogMessage("Krypto-Decrypt: Decrypt erfolgreich, aber Ergebnis ist kein gültiges JSON!", KL_ERROR);
            return [];
        }

        return $res;
    }
}