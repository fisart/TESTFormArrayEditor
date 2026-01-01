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

        // Navigation oben
        if ($currentPath !== "") {
            $form['actions'][] = ["type" => "Button", "caption" => "⬅️ Eine Ebene zurück", "onClick" => "AVT_NavigateUp(\$id);"];
        }

        // Master-Liste
        $form['actions'][] = [
            "type" => "List",
            "name" => "MasterListUI",
            "caption" => "Auswahl (Klick auf Zeile)",
            "rowCount" => 6,
            "columns" => [
                ["caption" => " ", "name" => "Icon", "width" => "35px"],
                ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                ["caption" => "Typ", "name" => "Type", "width" => "120px"]
            ],
            "values" => $masterList,
            "onClick" => "AVT_HandleClick(\$id, \$MasterListUI);"
        ];

        // NEU: BEREICH ZUM ERSTELLEN
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neues Element an dieser Position erstellen:"];
        $form['actions'][] = [
            "type" => "ValidationTextBox",
            "name" => "NewItemName",
            "caption" => "Name für neuen Ordner/Gerät",
            "value" => ""
        ];
        $form['actions'][] = [
            "type" => "HorizontalSection",
            "items" => [
                ["type" => "Button", "caption" => "📁 Ordner anlegen", "onClick" => "AVT_CreateItem(\$id, \$NewItemName, 'Folder');"],
                ["type" => "Button", "caption" => "🔑 Gerät/Record anlegen", "onClick" => "AVT_CreateItem(\$id, \$NewItemName, 'Record');"]
            ]
        ];

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
                            ["caption" => "Feld", "name" => "Key", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Wert", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $detailValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Speichern",
                        "onClick" => "AVT_SaveRecord(\$id, \$DetailListUI);"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Ganzen Eintrag löschen",
                        "onClick" => "AVT_DeleteItem(\$id, '$selectedRecord');"
                    ]
                ]
            ];
        }

        return json_encode($form);
    }

    // =========================================================================
    // AKTIONEN FÜR DIE UI
    // =========================================================================

    public function CreateItem(string $Name, string $Type): void {
        if ($Name === "") { echo "Bitte einen Namen eingeben."; return; }
        
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        // Pointer setzen
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (!isset($temp[$part])) $temp[$part] = [];
                $temp = &$temp[$part];
            }
        }

        if (isset($temp[$Name])) { echo "Name existiert bereits!"; return; }

        if ($Type === 'Folder') {
            $temp[$Name] = ["__folder" => true]; // Markierung für leeren Ordner
        } else {
            $temp[$Name] = ["User" => "", "PW" => ""]; // Initialer Record
            $this->WriteAttributeString("SelectedRecord", $Name); // Sofort zum Editieren öffnen
        }

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    public function DeleteItem(string $Name): void {
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                $temp = &$temp[$part];
            }
        }
        unset($temp[$Name]);

        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->WriteAttributeString("SelectedRecord", "");
        $this->ReloadForm();
    }

    public function HandleClick($Row): void {
        $ident = (string)($Row['Ident'] ?? '');
        $type  = (string)($Row['Type'] ?? '');
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

    // =========================================================================
    // HELFER
    // =========================================================================

    private function CheckIfFolder($value): bool {
        if (!is_array($value)) return false;
        if (isset($value['__folder'])) return true;
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