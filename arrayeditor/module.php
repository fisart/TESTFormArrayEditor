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

        // Navigation
        if ($currentPath !== "") {
            $form['actions'][] = [
                "type" => "Button",
                "caption" => "⬅️ Eine Ebene zurück",
                "onClick" => "IPS_RequestAction(\$id, 'NavigateUp', '');"
            ];
        }

        // Master-Liste mit Inline-Konvertierung beim Klick
        $form['actions'][] = [
            "type" => "List",
            "name" => "MasterListUI",
            "caption" => "Inhalt (Klicken zum Navigieren/Editieren)",
            "rowCount" => 6,
            "columns" => [
                ["caption" => " ", "name" => "Icon", "width" => "35px"],
                ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                ["caption" => "Typ", "name" => "Type", "width" => "120px"]
            ],
            "values" => $masterList,
            // Hier nutzen wir den Support-Trick für die Auswahl einer Zeile
            "onClick" => "IPS_RequestAction(\$id, 'HandleClick', json_encode(\$MasterListUI));"
        ];

        // Bereich zum Erstellen
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neues Element erstellen:"];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "NewItemName", "caption" => "Name", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "📁 Ordner anlegen", "onClick" => "IPS_RequestAction(\$id, 'CreateFolder', \$NewItemName);"];
        $form['actions'][] = ["type" => "Button", "caption" => "🔑 Record anlegen", "onClick" => "IPS_RequestAction(\$id, 'CreateRecord', \$NewItemName);"];

        // Detail-Panel
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
                        /**
                         * DER SUPPORT-TRICK:
                         * Wir loopen durch die Liste, bauen ein Array und senden es als JSON-String.
                         */
                        "onClick" => "\$D=[]; foreach(\$DetailListUI as \$r){ \$D[]=\$r; } IPS_RequestAction(\$id, 'SaveRecord', json_encode(\$D));"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Ganzen Eintrag löschen",
                        "onClick" => "IPS_RequestAction(\$id, 'DeleteItem', '');"
                    ]
                ]
            ];
        }

        return json_encode($form);
    }

    /**
     * ZENTRALES EINGANGSTOR (RequestAction)
     */
    public function RequestAction($Ident, $Value) {
        $this->LogMessage("RequestAction: $Ident", KL_MESSAGE);

        switch ($Ident) {
            case "HandleClick":
                $row = json_decode($Value, true);
                $this->HandleClickAction($row);
                break;

            case "NavigateUp":
                $this->NavigateUpAction();
                break;

            case "SaveRecord":
                $data = json_decode($Value, true);
                $this->SaveRecordAction($data);
                break;

            case "CreateFolder":
                $this->CreateItemAction($Value, 'Folder');
                break;

            case "CreateRecord":
                $this->CreateItemAction($Value, 'Record');
                break;

            case "DeleteItem":
                $this->DeleteItemAction();
                break;

            default:
                throw new Exception("Unbekannter Ident: $Ident");
        }
    }

    // =========================================================================
    // PRIVATE LOGIK-FUNKTIONEN (RequestAction Handler)
    // =========================================================================

    private function HandleClickAction($row) {
        $ident = (string)($row['Ident'] ?? '');
        $type = (string)($row['Type'] ?? '');
        if ($ident === "") return;

        if ($type === "Folder") {
            $current = $this->ReadAttributeString("CurrentPath");
            $this->WriteAttributeString("CurrentPath", ($current === "") ? $ident : $current . "/" . $ident);
            $this->WriteAttributeString("SelectedRecord", "");
        } else {
            $this->WriteAttributeString("SelectedRecord", $ident);
        }
        $this->ReloadForm();
    }

    private function SaveRecordAction($inputList) {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        $fullPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;

        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $newFields = [];
        foreach ($inputList as $row) {
            if ($row['Key'] !== "") $newFields[(string)$row['Key']] = (string)$row['Value'];
        }

        $parts = explode('/', $fullPath);
        $temp = &$masterData;
        foreach ($parts as $part) {
            if (!isset($temp[$part]) || !is_array($temp[$part])) $temp[$part] = [];
            $temp = &$temp[$part];
        }
        $temp = $newFields;

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ Gespeichert!";
    }

    private function CreateItemAction($name, $type) {
        if ($name === "") return;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (!isset($temp[$part])) $temp[$part] = [];
                $temp = &$temp[$part];
            }
        }
        if ($type === 'Folder') { $temp[$name] = ["__folder" => true]; } 
        else { $temp[$name] = ["User" => "", "PW" => ""]; $this->WriteAttributeString("SelectedRecord", $name); }
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    private function DeleteItemAction() {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) { $temp = &$temp[$part]; }
        }
        unset($temp[$selectedRecord]);
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    private function NavigateUpAction() {
        $current = $this->ReadAttributeString("CurrentPath");
        $parts = explode('/', $current);
        array_pop($parts);
        $this->WriteAttributeString("CurrentPath", implode('/', $parts));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    // =========================================================================
    // HELFER & KRYPTO (BEWÄHRT)
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