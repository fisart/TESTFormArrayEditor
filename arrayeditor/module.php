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
    }

    public function GetConfigurationForm(): string {
        $this->LogMessage("--- UI REFRESH ---", KL_MESSAGE);
        
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        
        // Navigation zum aktuellen Zweig
        $displayData = $data;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (isset($displayData[$part])) $displayData = $displayData[$part];
            }
        }

        $masterList = [];
        if (is_array($displayData)) {
            ksort($displayData);
            foreach ($displayData as $key => $value) {
                if ($key === "__folder") continue;
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
                ],
                [
                    "type" => "Button",
                    "caption" => "⬅️ EINE EBENE HOCH",
                    "visible" => ($currentPath !== ""),
                    "onClick" => "IPS_RequestAction(\$id, 'NavigateUp', '');"
                ],
                [
                    "type" => "List",
                    "name" => "MasterListUI",
                    "caption" => "1. Zeile markieren:",
                    "rowCount" => 8,
                    "columns" => [
                        ["caption" => " ", "name" => "Icon", "width" => "35px"],
                        ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                        ["caption" => "Typ", "name" => "Type", "width" => "120px"]
                    ],
                    "values" => $masterList
                ],
                [
                    "type" => "Button",
                    "caption" => "➡️ MARKIERTE ZEILE ÖFFNEN / EDITIEREN",
                    "onClick" => "if(isset(\$MasterListUI)) { IPS_RequestAction(\$id, 'HandleClick', json_encode(\$MasterListUI)); } else { echo 'Bitte erst eine Zeile markieren!'; }"
                ],
                [
                    "type" => "Button",
                    "caption" => "🗑️ MARKIERTE ZEILE LÖSCHEN",
                    "onClick" => "if(isset(\$MasterListUI)) { IPS_RequestAction(\$id, 'DeleteFromList', json_encode(\$MasterListUI)); } else { echo 'Bitte erst eine Zeile markieren!'; }"
                ],
                ["type" => "Label", "caption" => "________________________________________________________________________________________________"],
                ["type" => "Label", "caption" => "➕ NEUES ELEMENT HIER ANLEGEN:"],
                ["type" => "ValidationTextBox", "name" => "NewItemName", "caption" => "Name", "value" => ""],
                [
                    "type" => "Button",
                    "caption" => "📁 Ordner erstellen",
                    "onClick" => "IPS_RequestAction(\$id, 'CreateFolder', \$NewItemName);"
                ],
                [
                    "type" => "Button",
                    "caption" => "🔑 Record erstellen",
                    "onClick" => "IPS_RequestAction(\$id, 'CreateRecord', \$NewItemName);"
                ]
            ]
        ];

        // DETAIL-EDITOR (Nur wenn ein Record gewählt wurde)
        if ($selectedRecord !== "") {
            $recordPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;
            $fields = $this->GetNestedValue($data, $recordPath);
            $detailValues = [];
            if (is_array($fields)) {
                foreach ($fields as $k => $v) {
                    if (!is_array($v) && $k !== "__folder") $detailValues[] = ["Key" => $k, "Value" => (string)$v];
                }
            }

            $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📝 Editor: " . $recordPath,
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
                        "caption" => "💾 Details speichern",
                        "onClick" => "\$D=[]; foreach(\$DetailListUI as \$r){ \$D[]=\$r; } IPS_RequestAction(\$id, 'SaveRecord', json_encode(\$D));"
                    ]
                ]
            ];
        }

        // IMPORT
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON Import", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Importieren", "onClick" => "IPS_RequestAction(\$id, 'ImportJson', \$ImportInput);"];

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value) {
        $this->LogMessage("--- RequestAction: $Ident ---", KL_MESSAGE);

        switch ($Ident) {
            case "HandleClick":
                $row = json_decode($Value, true);
                if (isset($row['Ident'])) $this->ProcessNavigation($row['Ident'], $row['Type']);
                break;
            case "DeleteFromList":
                $row = json_decode($Value, true);
                if (isset($row['Ident'])) $this->DeleteItemAction($row['Ident']);
                break;
            case "NavigateUp":
                $current = $this->ReadAttributeString("CurrentPath");
                $parts = explode('/', $current); array_pop($parts);
                $this->WriteAttributeString("CurrentPath", implode('/', $parts));
                $this->WriteAttributeString("SelectedRecord", "");
                $this->ReloadForm();
                break;
            case "SaveRecord":
                $this->SaveRecordAction(json_decode($Value, true));
                break;
            case "CreateFolder":
                $this->CreateItemAction($Value, 'Folder');
                break;
            case "CreateRecord":
                $this->CreateItemAction($Value, 'Record');
                break;
            case "ImportJson":
                $data = json_decode($Value, true);
                if (is_array($data)) {
                    $this->WriteAttributeString("EncryptedVault", $this->EncryptData($data));
                    $this->WriteAttributeString("CurrentPath", "");
                    $this->ReloadForm();
                }
                break;
            default:
                throw new Exception("Unbekannter Ident: $Ident");
        }
    }

    private function ProcessNavigation($ident, $type) {
        if ($type === "Folder") {
            $current = $this->ReadAttributeString("CurrentPath");
            $this->WriteAttributeString("CurrentPath", ($current === "") ? $ident : $current . "/" . $ident);
            $this->WriteAttributeString("SelectedRecord", "");
        } else {
            $this->WriteAttributeString("SelectedRecord", $ident);
        }
        $this->ReloadForm();
    }

    private function DeleteItemAction($name) {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (isset($temp[$part])) $temp = &$temp[$part];
            }
        }
        
        if (isset($temp[$name])) {
            unset($temp[$name]);
            $this->LogMessage("Gelöscht: $name an Position $currentPath", KL_MESSAGE);
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
            $this->WriteAttributeString("SelectedRecord", "");
            $this->ReloadForm();
        }
    }

    // =========================================================================
    // ÖFFENTLICHE API (GETSECRET)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        foreach ($parts as $part) {
            if (isset($data[$part])) { $data = $data[$part]; } else { return ""; }
        }
        return is_string($data) ? $data : (json_encode($data) ?: "");
    }

    // =========================================================================
    // INTERNE SPEICHER-LOGIK
    // =========================================================================

    private function SaveRecordAction($inputList) {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        $fullPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $newFields = [];
        foreach ($inputList as $row) { if ($row['Key'] !== "") $newFields[(string)$row['Key']] = (string)$row['Value']; }
        $parts = explode('/', $fullPath); $temp = &$masterData;
        foreach ($parts as $part) { if (!isset($temp[$part]) || !is_array($temp[$part])) $temp[$part] = []; $temp = &$temp[$part]; }
        $temp = $newFields;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    private function CreateItemAction($name, $type) {
        if ($name === "") return;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $temp = &$masterData;
        if ($currentPath !== "") { foreach (explode('/', $currentPath) as $part) { if (!isset($temp[$part])) $temp[$part] = []; $temp = &$temp[$part]; } }
        if ($type === 'Folder') { $temp[$name] = ["__folder" => true]; } 
        else { $temp[$name] = ["User" => "", "PW" => ""]; $this->WriteAttributeString("SelectedRecord", $name); }
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    // =========================================================================
    // HELFER (KRYPTO & STRUKTUR)
    // =========================================================================

    private function CheckIfFolder($value): bool {
        if (!is_array($value)) return false;
        if (isset($value['__folder'])) return true;
        foreach ($value as $v) { if (is_array($v)) return true; }
        return false;
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) { if (isset($array[$part])) $array = $array[$part]; else return null; }
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