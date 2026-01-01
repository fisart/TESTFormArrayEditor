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

    public function GetConfigurationForm(): string {
        $this->LogMessage("--- Formular-Laden ---", KL_MESSAGE);
        
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        
        // 1. Navigation zum Pfad im Array
        $displayData = $data;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (isset($displayData[$part])) $displayData = $displayData[$part];
            }
        }

        // 2. Liste aufbauen (Folder vs. Records)
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
                ]
            ]
        ];

        // ZURÜCK-BUTTON (Immer sichtbar wenn nicht im Root)
        if ($currentPath !== "") {
            $form['actions'][] = [
                "type" => "Button",
                "caption" => "⬅️ ZURÜCK (Ebene höher)",
                "onClick" => "AVT_NavigateUp(\$id);"
            ];
        }

        // DIE HAUPTLISTE
        $form['actions'][] = [
            "type" => "List",
            "name" => "MasterListUI",
            "caption" => "Inhalt (KLICKEN zum Öffnen oder Editieren)",
            "rowCount" => 8,
            "columns" => [
                ["caption" => " ", "name" => "Icon", "width" => "35px"],
                ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                ["caption" => "Typ", "name" => "Type", "width" => "120px"]
            ],
            "values" => $masterList,
            // Wir nutzen die Instanz-Funktion für den Klick
            "onClick" => "AVT_HandleClick(\$id, \$MasterListUI);"
        ];

        // ERSTELLUNGS-BEREICH
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neues Element HIER erstellen:"];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "NewItemName", "caption" => "Name", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "📁 Unterordner anlegen", "onClick" => "AVT_CreateItem(\$id, \$NewItemName, 'Folder');"];
        $form['actions'][] = ["type" => "Button", "caption" => "🔑 Gerät/Record anlegen", "onClick" => "AVT_CreateItem(\$id, \$NewItemName, 'Record');"];

        // DETAIL-EDITOR (Nur für Records)
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
                            ["caption" => "Inhalt", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $detailValues
                    ],
                    ["type" => "Button", "caption" => "💾 Details speichern", "onClick" => "AVT_SaveRecord(\$id, \$DetailListUI);"],
                    ["type" => "Button", "caption" => "🗑️ Löschen", "onClick" => "AVT_DeleteItem(\$id, '$selectedRecord');"]
                ]
            ];
        }

        // IMPORT
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON Import", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Importieren", "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"];

        return json_encode($form);
    }

    // =========================================================================
    // INTERAKTION & NAVIGATION
    // =========================================================================

    public function HandleClick($Row): void {
        $ident = (string)($Row['Ident'] ?? '');
        $type  = (string)($Row['Type'] ?? '');

        $this->LogMessage("Click auf: $ident ($type)", KL_MESSAGE);

        if ($ident === "") return;

        if ($type === "Folder") {
            $current = $this->ReadAttributeString("CurrentPath");
            $this->WriteAttributeString("CurrentPath", ($current === "") ? $ident : $current . "/" . $ident);
            $this->WriteAttributeString("SelectedRecord", ""); // Editor schließen
        } else {
            // Record für Detail-Panel auswählen
            $this->WriteAttributeString("SelectedRecord", $ident);
        }
        $this->ReloadForm();
    }

    public function NavigateUp(): void {
        $current = $this->ReadAttributeString("CurrentPath");
        if ($current === "") return;
        
        $parts = explode('/', $current);
        array_pop($parts);
        
        $this->WriteAttributeString("CurrentPath", implode('/', $parts));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    // =========================================================================
    // SPEICHER-AKTIONEN
    // =========================================================================

    public function CreateItem(string $Name, string $Type): void {
        if ($Name === "") return;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) $temp[$part] = [];
                $temp = &$temp[$part];
            }
        }

        if ($Type === 'Folder') {
            $temp[$Name] = ["__folder" => true];
        } else {
            $temp[$Name] = ["User" => "", "PW" => ""];
            $this->WriteAttributeString("SelectedRecord", $Name);
        }

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
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

    public function DeleteItem(string $Name): void {
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) { $temp = &$temp[$part]; }
        }
        unset($temp[$Name]);
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    public function ImportJson(string $Input): void {
        $data = json_decode($Input, true);
        if (is_array($data)) {
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($data));
            $this->WriteAttributeString("CurrentPath", "");
            $this->WriteAttributeString("SelectedRecord", "");
            $this->ReloadForm();
        }
    }

    // =========================================================================
    // HELFER (RECORDBILDUNG & KRYPTO)
    // =========================================================================

    private function CheckIfFolder($value): bool {
        if (!is_array($value)) return false;
        if (isset($value['__folder'])) return true;
        // Wenn es ein Array ist, das wiederum Arrays enthält -> Folder
        foreach ($value as $v) { if (is_array($v)) return true; }
        return false;
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (isset($array[$part])) $array = $array[$part]; else return null;
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