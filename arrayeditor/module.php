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
        $this->LogMessage("--- Formular-Laden (Nested) ---", KL_MESSAGE);

        // 1. Daten laden (Verschachteltes Array)
        $nestedData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        // 2. Daten für die Liste flachklopfen (z.B. "Ordner/Unterordner/Key")
        $flatValues = [];
        $this->FlattenArray($nestedData, "", $flatValues);

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "List",
                    "name" => "VaultEditor",
                    "caption" => "Verschachtelter Tresor (Nutze '/' im Ident für Pfade)",
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => "Pfad (z.B. Server/Web/Pass)", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Wert", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]]
                    ],
                    "values" => $flatValues
                ],
                [
                    "type" => "Button",
                    "caption" => "🔓 Tresor verschlüsselt speichern",
                    // WICHTIG: Prefix AVT muss zur module.json passen
                    "onClick" => "AVT_UpdateVault(\$id, \$VaultEditor);"
                ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * SPEICHERN: Baut aus den flachen Pfaden wieder ein tief verschachteltes Array
     */
    public function UpdateVault($VaultEditor): void {
        $this->LogMessage("--- Start Speichern (Nested) ---", KL_MESSAGE);
        
        $finalArray = [];
        $count = 0;

        foreach ($VaultEditor as $row) {
            $path = (string)($row['Ident'] ?? '');
            $value = (string)($row['Secret'] ?? '');

            if ($path === "") continue;

            $parts = explode('/', $path);
            $temp = &$finalArray;

            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
            $temp = $value;
            $count++;
        }

        $encrypted = $this->EncryptData($finalArray);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->LogMessage("Nested Array gespeichert. Pfade verarbeitet: $count", KL_MESSAGE);
            echo "✅ Tresor gespeichert!";
        }
    }

    /**
     * API: Ermöglicht Zugriff auf tief geschachtelte Werte
     * Beispiel: AVT_GetSecret($id, "Netzwerk/Router/Admin");
     */
    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        
        foreach ($parts as $part) {
            if (isset($data[$part])) {
                $data = $data[$part];
            } else {
                return "";
            }
        }
        return is_string($data) ? $data : (json_encode($data) ?: "");
    }

    // =========================================================================
    // HILFSFUNKTIONEN (Flattening & Krypto)
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