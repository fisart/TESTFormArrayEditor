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
        $nestedData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);

        return json_encode([
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Geheimnis-Tresor (Nested)",
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Pfad (Ident)", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Wert (Secret)", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $flatValues
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor speichern",
                    // Wir übergeben das Objekt direkt. IPS_RequestAction ist das Ziel.
                    "onClick" => "IPS_RequestAction(\$id, 'SaveVault', \$VaultEditor);"
                ]
            ]
        ]);
    }

    /**
     * Das vom Support empfohlene "Eingangstor"
     */
    public function RequestAction($Ident, $Value) {
        $this->LogMessage("RequestAction aufgerufen mit Ident: " . $Ident, KL_MESSAGE);

        switch ($Ident) {
            case "SaveVault":
                // $Value ist hier das IPSList-Objekt
                $this->HandleSaveAction($Value);
                break;

            default:
                throw new Exception("Unbekannter Ident: " . $Ident);
        }
    }

    private function HandleSaveAction($IncomingData) {
        $this->LogMessage("Speichervorgang gestartet...", KL_MESSAGE);

        $finalArray = [];
        $count = 0;

        // Wir müssen manuell durch das Objekt iterieren (wie zuvor bewährt)
        try {
            foreach ($IncomingData as $row) {
                $path = (string)($row['Ident'] ?? '');
                $secret = (string)($row['Secret'] ?? '');

                if ($path === "") continue;

                // Pfad-Logik (Nested)
                $parts = explode('/', $path);
                $temp = &$finalArray;
                foreach ($parts as $part) {
                    if (!isset($temp[$part]) || !is_array($temp[$part])) {
                        $temp[$part] = [];
                    }
                    $temp = &$temp[$part];
                }
                $temp = $secret;
                $count++;
            }
        } catch (Throwable $e) {
            $this->LogMessage("Fehler bei der Datenverarbeitung: " . $e->getMessage(), KL_ERROR);
            return;
        }

        $this->LogMessage("Verarbeitete Zeilen: " . $count, KL_MESSAGE);

        if ($count === 0 && $this->ReadAttributeString("EncryptedVault") !== "") {
             // Optional: Schutz gegen versehentliches Leeren
             $this->LogMessage("Warnung: Leere Liste empfangen, speichere trotzdem.", KL_WARNING);
        }

        $encrypted = $this->EncryptData($finalArray);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("ERFOLG: Tresor wurde im Attribut gespeichert.", KL_MESSAGE);
            echo "✅ Tresor erfolgreich gespeichert!";
        } else {
            $this->LogMessage("FEHLER: Verschlüsselung fehlgeschlagen.", KL_ERROR);
        }
    }

    // =========================================================================
    // API & KRYPTO (Bewährtes Verfahren)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        foreach ($parts as $part) {
            if (isset($data[$part])) { $data = $data[$part]; } else { return ""; }
        }
        return is_string($data) ? $data : (json_encode($data) ?: "");
    }

    private function FlattenArray($array, $prefix, &$result) {
        if (!is_array($array)) return;
        foreach ($array as $key => $value) {
            $fullKey = ($prefix === "") ? (string)$key : $prefix . "/" . $key;
            if (is_array($value)) { $this->FlattenArray($value, $fullKey, $result); }
            else { $result[] = ["Ident" => $fullKey, "Secret" => $value]; }
        }
    }

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            @file_put_contents($path, $key);
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