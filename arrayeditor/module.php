<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "{}");
        $this->RegisterAttributeString("CurrentPath", "");    // Navigator-Position
        $this->RegisterAttributeString("SelectedRecord", ""); // Aktuell editiertes Gerät
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        $selectedRecord = $this->ReadAttributeString("SelectedRecord");
        
        // 1. Navigation zum aktuellen Pfad im Array
        $displayData = $data;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (isset($displayData[$part])) $displayData = $displayData[$part];
            }
        }

        // 2. Master-Liste für die aktuelle Ebene aufbauen
        $masterList = [];
        if (is_array($displayData)) {
            foreach ($displayData as $key => $value) {
                $isFolder = is_array($value) && $this->HasSubArrays($value);
                $masterList[] = [
                    "Icon"  => $isFolder ? "📁" : "🔑",
                    "Ident" => (string)$key,
                    "Type"  => $isFolder ? "Ordner" : "Gerät/Record"
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
                    "caption" => "📍 Position: " . ($currentPath === "" ? "root" : "root / " . str_replace("/", " / ", $currentPath)),
                    "bold" => true
                ],
                [
                    "type" => "Button",
                    "caption" => "⬅️ Zurück",
                    "visible" => ($currentPath !== ""),
                    "onClick" => "AVT_NavigateUp(\$id);"
                ],
                [
                    "type" => "List",
                    "name" => "MasterListUI",
                    "caption" => "Wählen Sie einen Ordner zum Öffnen oder ein Gerät zum Bearbeiten",
                    "rowCount" => 6,
                    "columns" => [
                        ["caption" => " ", "name" => "Icon", "width" => "35px"],
                        ["caption" => "Name", "name" => "Ident", "width" => "auto"],
                        ["caption" => "Typ", "name" => "Type", "width" => "150px"]
                    ],
                    "values" => $masterList,
                    // Ein Klick steuert alles:
                    "onClick" => "AVT_HandleClick(\$id, \$MasterListUI['Ident'], \$MasterListUI['Type']);"
                ]
            ]
        ];

        // 3. Detail-Panel (Erscheint nur, wenn ein Gerät/Record gewählt wurde)
        if ($selectedRecord !== "") {
            $recordPath = ($currentPath === "") ? $selectedRecord : $currentPath . "/" . $selectedRecord;
            $currentFields = $this->GetNestedValue($data, $recordPath) ?: [];
            
            $detailValues = [];
            foreach ($currentFields as $k => $v) {
                if (!is_array($v)) $detailValues[] = ["Key" => $k, "Value" => (string)$v];
            }

            $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📝 Details bearbeiten: " . $recordPath,
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
                        "caption" => "💾 Änderungen für '" . $selectedRecord . "' speichern",
                        "onClick" => "AVT_SaveRecord(\$id, \$DetailListUI);"
                    ]
                ]
            ];
        }

        return json_encode($form);
    }

    // =========================================================================
    // LOGIK FÜR KLICK-STEUERUNG
    // =========================================================================

    public function HandleClick(string $Ident, string $Type): void {
        if ($Type === "Ordner") {
            // Drill-Down: In den Ordner gehen
            $current = $this->ReadAttributeString("CurrentPath");
            $newPath = ($current === "") ? $Ident : $current . "/" . $Ident;
            $this->WriteAttributeString("CurrentPath", $newPath);
            $this->WriteAttributeString("SelectedRecord", ""); // Detail-Panel schließen
        } else {
            // Auswahl: Gerät im Detail-Panel anzeigen
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

        // Im Master-Array platzieren
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

    private function HasSubArrays($array): bool {
        if (!is_array($array)) return false;
        foreach ($array as $v) { if (is_array($v)) return true; }
        return false;
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (isset($array[$part])) $array = $array[$part]; else return null;
        }
        return $array;
    }

    // ... GetMasterKey, EncryptData, DecryptData (unverändert wie zuvor) ...
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