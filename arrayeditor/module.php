<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "{}");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $this->LogMessage("--- Akkordeon-Editor (mit Typ-Wahl): Lade Panels ---", KL_MESSAGE);

        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => []
        ];

        ksort($data); 
        foreach ($data as $category => $content) {
            
            // Unterelemente für diese Kategorie flachklopfen und Typ/Icon bestimmen
            $flatValues = [];
            if (is_array($content)) {
                $this->FlattenArrayForExplorer($content, "", $flatValues);
            } else {
                $flatValues[] = ["Icon" => "🔑", "Ident" => ".", "Secret" => (string)$content, "Type" => "Wert"];
            }

            $hash = md5($category);
            $listName = "List_" . $hash;
            $b64Cat = base64_encode($category);

            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📁 " . $category . " (" . count($flatValues) . " Einträge)",
                "items" => [
                    [
                        "type" => "List",
                        "name" => $listName,
                        "rowCount" => 8,
                        "add" => true,
                        "delete" => true,
                        "columns" => [
                            ["caption" => " ", "name" => "Icon", "width" => "35px", "add" => "🔑"],
                            ["caption" => "Unterpfad / Feld", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Wert", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            [
                                "caption" => "Typ", 
                                "name" => "Type", 
                                "width" => "150px", 
                                "add" => "Wert", 
                                "edit" => [
                                    "type" => "Select",
                                    "options" => [
                                        ["caption" => "Wert / Passwort", "value" => "Wert"],
                                        ["caption" => "Ordner (Container)", "value" => "Ordner"]
                                    ]
                                ]
                            ]
                        ],
                        "values" => $flatValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Änderungen für '" . $category . "' speichern",
                        "onClick" => "AVT_UpdateCategory(\$id, '$b64Cat', \$$listName);"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Gruppe löschen",
                        "onClick" => "AVT_DeleteCategory(\$id, '$b64Cat');"
                    ]
                ]
            ];
        }

        // Steuerung für neue Hauptkategorien
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neue Hauptkategorie (Ebene 1) anlegen", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "NewCategoryName", "caption" => "Name der neuen Gruppe", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Gruppe erstellen", "onClick" => "AVT_AddCategory(\$id, \$NewCategoryName);"];

        // Import/Export Logik bleibt erhalten
        $form['actions'][] = ["type" => "Label", "caption" => "📥 JSON IMPORT", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON String hier einfügen", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "JSON jetzt importieren", "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"];

        return json_encode($form);
    }

    // =========================================================================
    // SPEICHER-AKTIONEN
    // =========================================================================

    public function UpdateCategory(string $CategoryBase64, $ListData): void {
        $category = base64_decode($CategoryBase64);
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $newCategoryContent = [];
        foreach ($ListData as $row) {
            $path = (string)($row['Ident'] ?? '');
            $val  = (string)($row['Secret'] ?? '');
            $type = (string)($row['Type'] ?? 'Wert');

            if ($path === "" || $path === ".") continue;

            $parts = explode('/', $path);
            $temp = &$newCategoryContent;
            foreach ($parts as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) { $temp[$part] = []; }
                $temp = &$temp[$part];
            }

            if ($type === "Ordner") {
                // Falls es schon ein Ordner war, Inhalt bewahren, sonst leeres Array
                // Wir navigieren im alten Stand der Kategorie, um Daten nicht zu verlieren
                $oldContent = $masterData[$category] ?? [];
                $existing = $this->GetNestedValue($oldContent, $path);
                $temp = is_array($existing) ? $existing : [];
            } else {
                $temp = $val;
            }
        }

        $masterData[$category] = $newCategoryContent;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        
        // Debug Log
        $this->LogMessage("--- TRESOR AKTUALISIERT ---", KL_MESSAGE);
        $this->LogMessage(json_encode($masterData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), KL_MESSAGE);
        
        $this->ReloadForm();
        echo "✅ '$category' wurde gespeichert.";
    }

    private function GetNestedValue($array, $path) {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (isset($array[$part])) { $array = $array[$part]; } else { return null; }
        }
        return $array;
    }

    public function DeleteCategory(string $CategoryBase64): void {
        $category = base64_decode($CategoryBase64);
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        if (isset($masterData[$category])) {
            unset($masterData[$category]);
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
            $this->ReloadForm();
        }
    }

    public function AddCategory(string $Name): void {
        if ($Name === "") return;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        if (!isset($masterData[$Name])) {
            $masterData[$Name] = [];
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
            $this->ReloadForm();
        }
    }

    public function ImportJson(string $Input): void {
        $data = json_decode($Input, true);
        if (is_array($data)) {
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($data));
            $this->ReloadForm();
            echo "✅ Import erfolgreich!";
        }
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    private function FlattenArrayForExplorer($array, $prefix, &$result) {
        if (!is_array($array)) return;
        foreach ($array as $key => $value) {
            $fullKey = ($prefix === "") ? (string)$key : $prefix . "/" . $key;
            $isFolder = is_array($value);
            
            $result[] = [
                "Icon"   => $isFolder ? "📁" : "🔑",
                "Ident"  => $fullKey,
                "Secret" => $isFolder ? "" : (string)$value,
                "Type"   => $isFolder ? "Ordner" : "Wert"
            ];
            
            if ($isFolder) {
                $this->FlattenArrayForExplorer($value, $fullKey, $result);
            }
        }
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