<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        
        // DAS EINZIGE ATTRIBUT (Verschlüsselt)
        $this->RegisterAttributeString("EncryptedVault", "{}");

        // Wir registrieren CurrentPath und SelectedRecord NICHT mehr als Attribute,
        // um keine Klartext-Namen in der settings.json zu hinterlassen.
        // Wir nutzen stattdessen flüchtige Buffer.
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    private function GetNavPath() { return $this->GetBuffer("CurrentPath"); }
    private function SetNavPath($path) { $this->SetBuffer("CurrentPath", $path); }
    private function GetSelected() { return $this->GetBuffer("SelectedRecord"); }
    private function SetSelected($ident) { $this->SetBuffer("SelectedRecord", $ident); }

    public function GetConfigurationForm(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->GetNavPath();
        $selectedRecord = $this->GetSelected();
        
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
                    "caption" => "⬅️ ZURÜCK",
                    "visible" => ($currentPath !== ""),
                    "onClick" => "IPS_RequestAction(\$id, 'NavigateUp', '');"
                ],
                [
                    "type" => "List",
                    "name" => "MasterListUI",
                    "caption" => "Inhalt",
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
                    "caption" => "➡️ ÖFFNEN / EDITIEREN",
                    "onClick" => "if(isset(\$MasterListUI)) { IPS_RequestAction(\$id, 'HandleClick', json_encode(\$MasterListUI)); } else { echo 'Bitte Zeile markieren!'; }"
                ],
                [
                    "type" => "Button",
                    "caption" => "🗑️ LÖSCHEN",
                    "onClick" => "if(isset(\$MasterListUI)) { IPS_RequestAction(\$id, 'DeleteFromList', json_encode(\$MasterListUI)); } else { echo 'Bitte Zeile markieren!'; }"
                ],
                ["type" => "Label", "caption" => "➕ NEU:"],
                ["type" => "ValidationTextBox", "name" => "NewItemName", "caption" => "Name", "value" => ""],
                ["type" => "Button", "caption" => "📁 Ordner", "onClick" => "IPS_RequestAction(\$id, 'CreateFolder', \$NewItemName);"],
                ["type" => "Button", "caption" => "🔑 Record", "onClick" => "IPS_RequestAction(\$id, 'CreateRecord', \$NewItemName);"]
            ]
        ];

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
                            ["caption" => "Feld", "name" => "Key", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Wert", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $detailValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Speichern",
                        "onClick" => "\$D=[]; foreach(\$DetailListUI as \$r){ \$D[]=\$r; } IPS_RequestAction(\$id, 'SaveRecord', json_encode(\$D));"
                    ]
                ]
            ];
        }

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value) {
        // KEIN LOG von $Value mehr hier!
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
                $parts = explode('/', $this->GetNavPath()); array_pop($parts);
                $this->SetNavPath(implode('/', $parts));
                $this->SetSelected("");
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
        }
    }

    private function ProcessNavigation($ident, $type) {
        if ($type === "Folder") {
            $current = $this->GetNavPath();
            $this->SetNavPath(($current === "") ? $ident : $current . "/" . $ident);
            $this->SetSelected("");
        } else {
            $this->SetSelected($ident);
        }
        $this->ReloadForm();
    }

    private function SaveRecordAction($inputList) {
        $currentPath = $this->GetNavPath();
        $selectedRecord = $this->GetSelected();
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
        $currentPath = $this->GetNavPath();
        $temp = &$masterData;
        if ($currentPath !== "") { foreach (explode('/', $currentPath) as $part) { if (!isset($temp[$part])) $temp[$part] = []; $temp = &$temp[$part]; } }
        if ($type === 'Folder') { $temp[$name] = ["__folder" => true]; } 
        else { $temp[$name] = ["User" => "", "PW" => ""]; $this->SetSelected($name); }
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    private function DeleteItemAction($name) {
        $currentPath = $this->GetNavPath();
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $temp = &$masterData;
        if ($currentPath !== "") { foreach (explode('/', $currentPath) as $part) { $temp = &$temp[$part]; } }
        unset($temp[$name]);
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->SetSelected("");
        $this->ReloadForm();
    }

    // --- Krypto & Helfer (Identisch aber ohne Logs) ---

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