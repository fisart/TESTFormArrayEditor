<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "{}");
        $this->RegisterAttributeString("CurrentPath", ""); // Speichert z.B. "RASPI/Sonos"
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        
        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => []
        ];

        ksort($data); 
        foreach ($data as $category => $content) {
            
            // Bestimmen, ob dieser Panel-Pfad gerade fokussiert ist
            $pathParts = explode('/', $currentPath);
            $isActive = ($pathParts[0] === $category);
            $subPath = $isActive ? substr($currentPath, strlen($category) + 1) : "";

            // Daten für dieses Panel filtern
            $displayData = $content;
            if ($isActive && $subPath !== "") {
                foreach (explode('/', $subPath) as $part) {
                    if (isset($displayData[$part]) && is_array($displayData[$part])) {
                        $displayData = $displayData[$part];
                    }
                }
            }

            $flatValues = [];
            $this->FlattenLevel($displayData, $flatValues);

            $hash = md5($category);
            $listName = "List_" . $hash;
            $b64Cat = base64_encode($category);

            $panelItems = [];
            
            // Breadcrumb innerhalb des Panels
            $panelItems[] = [
                "type" => "Label", 
                "caption" => "📍 Pfad: " . $category . ($subPath !== "" ? " / " . str_replace("/", " / ", $subPath) : ""),
                "italic" => true
            ];

            // Zurück-Button innerhalb des Panels
            if ($subPath !== "") {
                $panelItems[] = [
                    "type" => "Button",
                    "caption" => "⬅️ Eine Ebene nach oben",
                    "onClick" => "AVT_NavigateUp(\$id);"
                ];
            }

            $panelItems[] = [
                "type" => "List",
                "name" => $listName,
                "rowCount" => 8,
                "add" => true,
                "delete" => true,
                "columns" => [
                    ["caption" => " ", "name" => "Icon", "width" => "35px", "add" => "🔑"],
                    ["caption" => "Name (Feld/Ordner)", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                    ["caption" => "Wert", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                    [
                        "caption" => "Typ", "name" => "Type", "width" => "120px", "add" => "Wert", 
                        "edit" => ["type" => "Select", "options" => [
                            ["caption" => "Wert / Passwort", "value" => "Wert"],
                            ["caption" => "Ordner (Container)", "value" => "Ordner"]
                        ]]
                    ]
                ],
                "values" => $flatValues
            ];

            $panelItems[] = [
                "type" => "Button",
                "caption" => "💾 Speichern",
                "onClick" => "AVT_UpdateCategory(\$id, '$b64Cat', \$$listName);"
            ];

            $panelItems[] = [
                "type" => "Button",
                "caption" => "📂 Unterordner öffnen",
                "onClick" => "if (isset(\${$listName})) { AVT_NavigateDown(\$id, '$category', \${$listName}['Ident'], \${$listName}['Type']); } else { echo 'Kein Ordner gewählt'; }"
            ];

            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📁 " . $category . ($isActive && $subPath !== "" ? " (> $subPath)" : ""),
                "items" => $panelItems
            ];
        }

        // Neue Hauptgruppe & Import (unverändert)
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neue Hauptkategorie anlegen", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "NewCategoryName", "caption" => "Name", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Gruppe erstellen", "onClick" => "AVT_AddCategory(\$id, \$NewCategoryName);"];
        $form['actions'][] = ["type" => "Label", "caption" => "📥 JSON IMPORT", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Importieren", "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"];

        return json_encode($form);
    }

    // =========================================================================
    // NAVIGATION INNERHALB DES AKKORDEONS
    // =========================================================================

    public function NavigateDown(string $Category, string $Ident, string $Type): void {
        if ($Type !== "Ordner") {
            echo "Nur Ordner können geöffnet werden.";
            return;
        }
        $current = $this->ReadAttributeString("CurrentPath");
        
        // Wenn wir schon in der Kategorie sind, hängen wir an, sonst starten wir neu
        if (strpos($current, $Category) === 0) {
            $newPath = $current . "/" . $Ident;
        } else {
            $newPath = $Category . "/" . $Ident;
        }
        
        $this->WriteAttributeString("CurrentPath", $newPath);
        $this->ReloadForm();
    }

    public function NavigateUp(): void {
        $current = $this->ReadAttributeString("CurrentPath");
        $parts = explode('/', $current);
        array_pop($parts);
        $this->WriteAttributeString("CurrentPath", implode('/', $parts));
        $this->ReloadForm();
    }

    // =========================================================================
    // SPEICHER-AKTIONEN
    // =========================================================================

    public function UpdateCategory(string $CategoryBase64, $ListData): void {
        $category = base64_decode($CategoryBase64);
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        
        // Den Pfad innerhalb der Kategorie bestimmen
        $subPath = "";
        if (strpos($currentPath, $category) === 0) {
            $subPath = substr($currentPath, strlen($category) + 1);
        }

        // Pointer auf die aktuelle Ebene setzen
        $temp = &$masterData[$category];
        if ($subPath !== "") {
            foreach (explode('/', $subPath) as $part) {
                if (!isset($temp[$part])) $temp[$part] = [];
                $temp = &$temp[$part];
            }
        }

        $newList = [];
        foreach ($ListData as $row) {
            $name = (string)($row['Ident'] ?? '');
            if ($name === "") continue;
            if (($row['Type'] ?? 'Wert') === "Ordner") {
                $newList[$name] = (isset($temp[$name]) && is_array($temp[$name])) ? $temp[$name] : [];
            } else {
                $newList[$name] = (string)($row['Secret'] ?? '');
            }
        }

        $temp = $newList;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ Gespeichert!";
    }

    public function AddCategory(string $Name): void {
        if ($Name === "") return;
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $masterData[$Name] = [];
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    public function DeleteCategory(string $CategoryBase64): void {
        $category = base64_decode($CategoryBase64);
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        unset($masterData[$category]);
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
    }

    public function ImportJson(string $Input): void {
        $data = json_decode($Input, true);
        if (is_array($data)) {
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($data));
            $this->WriteAttributeString("CurrentPath", "");
            $this->ReloadForm();
        }
    }

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================

    private function FlattenLevel($data, &$result) {
        if (!is_array($data)) return;
        foreach ($data as $key => $value) {
            $isFolder = is_array($value);
            $result[] = [
                "Icon"   => $isFolder ? "📁" : "🔑",
                "Ident"  => (string)$key,
                "Secret" => $isFolder ? "" : (string)$value,
                "Type"   => $isFolder ? "Ordner" : "Wert"
            ];
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