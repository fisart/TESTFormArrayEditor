<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "{}");
        $this->RegisterAttributeString("SelectedIdent", ""); // Merkt sich die aktuelle Auswahl
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $this->LogMessage("--- Master-Detail Editor: Lade Ansicht ---", KL_MESSAGE);

        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $selectedIdent = $this->ReadAttributeString("SelectedIdent");
        
        // 1. Master-Liste aufbereiten (Alle Pfade zu Records finden)
        $masterList = [];
        $records = [];
        $this->FindAllRecords($data, "", $records);
        
        foreach ($records as $path => $fields) {
            $masterList[] = [
                "Ident" => $path,
                "Info"  => count($fields) . " Felder hinterlegt"
            ];
        }

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "Label",
                    "caption" => "🔍 1. Eintrag auswählen",
                    "bold" => true
                ],
                [
                    "type" => "List",
                    "name" => "MasterListUI",
                    "caption" => "Alle Geräte / Accounts",
                    "rowCount" => 6,
                    "columns" => [
                        ["caption" => "Name / Pfad", "name" => "Ident", "width" => "auto"],
                        ["caption" => "Details", "name" => "Info", "width" => "150px"]
                    ],
                    "values" => $masterList,
                    "onClick" => "AVT_SelectRecord(\$id, \$MasterListUI['Ident']);"
                ],
                [
                    "type" => "Button",
                    "caption" => "➕ Neuen Eintrag anlegen",
                    "onClick" => "AVT_CreateNewRecord(\$id);"
                ]
            ]
        ];

        // 2. Detail-Bereich (Nur sichtbar, wenn ein Ident ausgewählt ist)
        if ($selectedIdent !== "") {
            $currentFields = $this->GetNestedValue($data, $selectedIdent) ?: [];
            $detailValues = [];
            foreach ($currentFields as $k => $v) {
                if (!is_array($v)) {
                    $detailValues[] = ["Key" => $k, "Value" => (string)$v];
                }
            }

            $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📝 2. Details bearbeiten: " . $selectedIdent,
                "expanded" => true,
                "items" => [
                    [
                        "type" => "List",
                        "name" => "DetailListUI",
                        "caption" => "Felder für " . $selectedIdent,
                        "rowCount" => 6,
                        "add" => true,
                        "delete" => true,
                        "columns" => [
                            ["caption" => "Feldname (User, PW, IP...)", "name" => "Key", "width" => "250px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Inhalt", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $detailValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Details für '" . $selectedIdent . "' speichern",
                        "onClick" => "AVT_SaveRecordDetails(\$id, \$DetailListUI);"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Ganzen Eintrag '" . $selectedIdent . "' löschen",
                        "onClick" => "AVT_DeleteFullRecord(\$id);"
                    ]
                ]
            ];
        }

        // 3. Import (unverändert)
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Label", "caption" => "📥 JSON IMPORT", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Importieren", "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"];

        return json_encode($form);
    }

    // =========================================================================
    // AKTIONEN
    // =========================================================================

    public function SelectRecord(string $Ident): void {
        $this->WriteAttributeString("SelectedIdent", $Ident);
        $this->ReloadForm();
    }

    public function CreateNewRecord(): void {
        $this->WriteAttributeString("SelectedIdent", "NEUER_EINTRAG");
        $this->ReloadForm();
    }

    public function SaveRecordDetails($DetailList): void {
        $selectedIdent = $this->ReadAttributeString("SelectedIdent");
        if ($selectedIdent === "") return;

        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $newFields = [];
        foreach ($DetailList as $row) {
            if ($row['Key'] !== "") {
                $newFields[(string)$row['Key']] = (string)$row['Value'];
            }
        }

        // In das tiefe Array einweben
        $parts = explode('/', $selectedIdent);
        $temp = &$masterData;
        foreach ($parts as $part) {
            if (!isset($temp[$part]) || !is_array($temp[$part])) { $temp[$part] = []; }
            $temp = &$temp[$part];
        }
        $temp = $newFields;

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ Details für '$selectedIdent' gespeichert!";
    }

    public function DeleteFullRecord(): void {
        $selectedIdent = $this->ReadAttributeString("SelectedIdent");
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        // Löschen aus verschachteltem Array
        $parts = explode('/', $selectedIdent);
        $lastPart = array_pop($parts);
        $temp = &$masterData;
        foreach ($parts as $part) {
            if (isset($temp[$part])) { $temp = &$temp[$part]; }
        }
        unset($temp[$lastPart]);

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->WriteAttributeString("SelectedIdent", "");
        $this->ReloadForm();
    }

    public function ImportJson(string $Input): void {
        $data = json_decode($Input, true);
        if (is_array($data)) {
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($data));
            $this->WriteAttributeString("SelectedIdent", "");
            $this->ReloadForm();
            echo "✅ Import erfolgreich!";
        }
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    private function FindAllRecords($array, $prefix, &$records) {
        if (!is_array($array)) return;
        
        // Prüfen, ob dies ein "Blatt" ist (enthält nur Strings, keine weiteren Arrays)
        $hasSubArrays = false;
        foreach ($array as $v) { if (is_array($v)) { $hasSubArrays = true; break; } }

        if (!$hasSubArrays && !empty($array)) {
            $records[$prefix] = $array;
        } else {
            foreach ($array as $k => $v) {
                $newPrefix = ($prefix === "") ? (string)$k : $prefix . "/" . $k;
                $this->FindAllRecords($v, $newPrefix, $records);
            }
        }
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (isset($array[$part])) { $array = $array[$part]; } else { return null; }
        }
        return $array;
    }

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) { file_put_contents($path, bin2hex(random_bytes(16))); }
        return trim((string)file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return "";
        $cipher = openssl_encrypt(json_encode($data), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv = random_bytes(12), $tag, "", 16);
        return json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "{}") return [];
        $decoded = json_decode($encrypted, true);
        $keyHex = $this->GetMasterKey();
        if (!$decoded || !isset($decoded['data']) || $keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        return json_decode($dec ?: '[]', true) ?: [];
    }
}