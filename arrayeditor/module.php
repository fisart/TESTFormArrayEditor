<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "{}");
        $this->RegisterAttributeString("CurrentPath", "");    
        $this->RegisterAttributeString("SelectedRecord", ""); 
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        
        // 1. Navigation zum Pfad
        $displayData = $data;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (isset($displayData[$part])) $displayData = $displayData[$part];
            }
        }

        // 2. Liste aufbauen
        $masterList = [];
        if (is_array($displayData)) {
            ksort($displayData);
            foreach ($displayData as $key => $value) {
                $isFolder = $this->CheckIfFolder($value);
                $masterList[] = [
                    "Icon"  => $isFolder ? "📁" : "🔑",
                    "Ident" => (string)$key,
                    "Type"  => $isFolder ? "Folder" : "Record"
                ];
            }
        }

        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => [
                [
                    "type" => "Label",
                    "caption" => "📍 Position: root" . ($currentPath !== "" ? " / " . str_replace("/", " / ", $currentPath) : ""),
                    "bold" => true
                ]
            ]
        ];

        // Zurück Button
        if ($currentPath !== "") {
            $form['actions'][] = [
                "type" => "Button",
                "caption" => "⬅️ Ebene höher",
                "onClick" => "AVT_NavigateUp(\$id);"
            ];
        }

        // Die Hauptliste
        $form['actions'][] = [
            "type" => "List",
            "name" => "MasterListUI",
            "caption" => "Klicken zum Öffnen (📁) oder Editieren (🔑)",
            "rowCount" => 8,
            "columns" => [
                ["caption" => " ", "name" => "Icon", "width" => "35px"],
                ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                ["caption" => "Typ", "name" => "Type", "width" => "150px"]
            ],
            "values" => $masterList,
            "onClick" => "AVT_HandleClick(\$id, \$MasterListUI['Ident'], \$MasterListUI['Type']);"
        ];

        // 3. Detail-Panel (nur für Records)
        if ($selectedRecord !== "") {
            $recordPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;
            $fields = $this->GetNestedValue($data, $recordPath);
            
            $detailValues = [];
            if (is_array($fields)) {
                foreach ($fields as $k => $v) {
                    if (!is_array($v)) $detailValues[] = ["Key" => $k, "Value" => (string)$v];
                }
            }

            $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📝 Bearbeite: " . $recordPath,
                "expanded" => true,
                "items" => [
                    [
                        "type" => "List",
                        "name" => "DetailListUI",
                        "rowCount" => 6,
                        "add" => true,
                        "delete" => true,
                        "columns" => [
                            ["caption" => "Feldname", "name" => "Key", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Wert", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $detailValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Speichern",
                        "onClick" => "AVT_SaveRecord(\$id, \$DetailListUI);"
                    ]
                ]
            ];
        }

        // Import Bereich (Unten)
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Button", "caption" => "📥 JSON Importieren", "onClick" => "AVT_ShowImport(\$id);"];

        return json_encode($form);
    }

    /**
     * Ein Klick verarbeitet Navigation ODER Auswahl
     */
    public function HandleClick(string $Ident, string $Type): void {
        if ($Type === "Folder") {
            $current = $this->ReadAttributeString("CurrentPath");
            $newPath = ($current === "") ? $Ident : $current . "/" . $Ident;
            $this->WriteAttributeString("CurrentPath", $newPath);
            $this->WriteAttributeString("SelectedRecord", ""); // Panel zu
        } else {
            // Es ist ein Record -> Detail-Panel öffnen
            $this->WriteAttributeString("SelectedRecord", $Ident);
        }
        $this->ReloadForm();
    }

    public function NavigateUp(): void {
        $current = $this->ReadAttributeString("CurrentPath");
        $parts = explode('/', $current);
        array_pop($parts);
        $this->WriteAttributeString("CurrentPath", implode('/', $parts));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    public function SaveRecord($DetailList): void {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        $fullPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;

        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $newFields = [];
        foreach ($DetailList as $row) {
            if ($row['Key'] !== "") $newFields[(string)$row['Key']] = (string)$row['Value'];
        }

        // Pfad im Array ansteuern und setzen
        $parts = explode('/', $fullPath);
        $temp = &$masterData;
        foreach ($parts as $part) {
            if (!isset($temp[$part]) || !is_array($temp[$part])) $temp[$part] = [];
            $temp = &$temp[$part];
        }
        $temp = $newFields;

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        echo "✅ Gespeichert!";
        $this->ReloadForm();
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    /**
     * Erkennt, ob ein Array ein "Ordner" (enthält Unter-Arrays) 
     * oder ein "Record" (enthält nur Daten/Strings) ist.
     */
    private function CheckIfFolder($value): bool {
        if (!is_array($value)) return false;
        foreach ($value as $v) {
            if (is_array($v)) return true; // Sobald ein Unter-Array gefunden wird -> Ordner
        }
        return false; // Nur Strings gefunden -> Record
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (isset($array[$part])) $array = $array[$part]; else return null;
        }
        return $array;
    }

    // Krypto-Funktionen (unverändert)
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