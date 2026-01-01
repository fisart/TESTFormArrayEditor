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
        $decryptedValues = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));

        return json_encode([
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Disk-Clean)",
                    "rowCount" => 8,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Pfad (Ident)", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Wert (Secret)", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $decryptedValues,
                    // onChange sorgt dafür, dass Daten beim Verlassen einer Zelle synchronisiert werden
                    "onChange" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor jetzt final verschlüsseln & speichern",
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ],
                [
                    "type" => "Label",
                    "caption" => "Hinweis: Bitte nach der Eingabe einmal in eine andere Zeile klicken, bevor Sie speichern."
                ]
            ]
        ]);
    }

    // =========================================================================
    // ÖFFENTLICHE API (ZUM AUSLESEN IN SKRIPTEN)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $this->LogMessage("GetSecret Suche nach: " . $Path, KL_MESSAGE);
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $parts = explode('/', $Path);
        $current = $data;
        foreach ($parts as $part) {
            if (isset($current[$part])) {
                $current = $current[$part];
            } else {
                $this->LogMessage("Pfad '$Path' nicht gefunden.", KL_WARNING);
                return "";
            }
        }
        return is_string($current) ? $current : (json_encode($current) ?: "");
    }

    public function GetIdentifiers(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flat = [];
        $this->FlattenArray($data, "", $flat);
        return json_encode(array_column($flat, 'Ident'));
    }

    // =========================================================================
    // SPEICHER-LOGIK (DIE EINZIGE FUNKTIONIERENDE METHODE)
    // =========================================================================

    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- START SPEICHERVORGANG ---", KL_MESSAGE);
        
        $finalNestedArray = [];
        $count = 0;

        // WICHTIG: Das IPSList-Objekt MUSS per foreach durchlaufen werden.
        // Ein direktes json_encode($VaultEditor) würde hier nur 'null' liefern.
        foreach ($VaultEditor as $index => $row) {
            $path = (string)($row['Ident'] ?? '');
            $secret = (string)($row['Secret'] ?? '');

            // Debug-Log: Was sieht PHP in dieser Zeile?
            $this->LogMessage("Verarbeite Zeile $index: Pfad='$path', Secret-Länge=" . strlen($secret), KL_MESSAGE);

            if ($path === "") continue;

            // Verschachtelung aufbauen (A/B/C)
            $parts = explode('/', $path);
            $temp = &$finalNestedArray;
            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
            $temp = $secret;
            $count++;
        }

        if ($count > 0) {
            $encrypted = $this->EncryptData($finalNestedArray);
            if ($encrypted !== "") {
                $this->WriteAttributeString("EncryptedVault", $encrypted);
                $this->LogMessage("ERFOLG: $count Pfade verschlüsselt gespeichert.", KL_MESSAGE);
                echo "✅ Tresor erfolgreich gespeichert!";
            }
        } else {
            $this->LogMessage("ABBRUCH: Keine gültigen Daten zum Speichern gefunden.", KL_WARNING);
        }
    }

    // =========================================================================
    // INTERNE HILFSFUNKTIONEN (KRYPTO & FLATTENING)
    // =========================================================================

    private function FlattenArray($array, $prefix, &$result) {
        if (!is_array($array)) return;
        foreach ($array as $key => $value) {
            $fullKey = ($prefix === "") ? (string)$key : $prefix . "/" . $key;
            if (is_array($value)) {
                $this->FlattenArray($value, $fullKey, $result);
            } else {
                $result[] = ["Ident" => $fullKey, "Secret" => $value];
            }
        }
    }

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
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
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        return ($cipher === false) ? "" : json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        return json_decode($dec ?: '[]', true) ?: [];
    }
}