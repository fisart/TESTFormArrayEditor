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

    /**
     * DYNAMISCHES FORMULAR FÜR KONZEPT 2 (Akkordeon)
     */
    public function GetConfigurationForm(): string {
        $this->LogMessage("--- Akkordeon-Editor: Lade Panels ---", KL_MESSAGE);

        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        // Grundgerüst
        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => []
        ];

        // 1. Kategorien (Top-Level) als Panels aufbauen
        ksort($data); // Alphabetisch sortieren
        foreach ($data as $category => $content) {
            
            // Unterelemente für diese Kategorie flachklopfen (Pfade innerhalb der Kategorie)
            $flatValues = [];
            if (is_array($content)) {
                $this->FlattenArray($content, "", $flatValues);
            } else {
                // Falls es ein direkter Wert im Root war
                $flatValues[] = ["Ident" => ".", "Secret" => (string)$content];
            }

            // Panel für die Kategorie erstellen
            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📁 " . $category . " (" . count($flatValues) . " Einträge)",
                "items" => [
                    [
                        "type" => "List",
                        "name" => "List_" . md5($category), // Eindeutiger Name für die Liste
                        "rowCount" => 6,
                        "add" => true,
                        "delete" => true,
                        "columns" => [
                            ["caption" => "Unterpfad / Feld", "name" => "Ident", "width" => "300px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Wert", "name" => "Secret", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]]
                        ],
                        "values" => $flatValues
                    ],
                    [
                        "type" => "Button",
                        "caption" => "💾 Änderungen für '" . $category . "' speichern",
                        "onClick" => "AVT_UpdateCategory(\$id, '$category', \${'List_' . md5($category)});"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Gesamte Kategorie '" . $category . "' löschen",
                        "onClick" => "AVT_DeleteCategory(\$id, '$category');"
                    ]
                ]
            ];
        }

        // 2. Bereich zum Hinzufügen einer neuen Kategorie
        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neue Hauptkategorie anlegen", "bold" => true];
        $form['actions'][] = [
            "type" => "ValidationTextBox",
            "name" => "NewCategoryName",
            "caption" => "Name der neuen Gruppe (z.B. FB-DSL)",
            "value" => ""
        ];
        $form['actions'][] = [
            "type" => "Button",
            "caption" => "Gruppe erstellen",
            "onClick" => "AVT_AddCategory(\$id, \$NewCategoryName);"
        ];

        // 3. Import Bereich (wie zuvor)
        $form['actions'][] = ["type" => "Label", "caption" => "📥 JSON IMPORT", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON String hier einfügen", "value" => ""];
        $form['actions'][] = [
            "type" => "Button",
            "caption" => "JSON importieren & überschreiben",
            "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"
        ];

        return json_encode($form);
    }

    // =========================================================================
    // SPEICHER-AKTIONEN
    // =========================================================================

    public function UpdateCategory(string $Category, $ListData): void {
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $newCategoryContent = [];
        foreach ($ListData as $row) {
            $path = (string)($row['Ident'] ?? '');
            $val  = (string)($row['Secret'] ?? '');
            if ($path === "") continue;

            if ($path === ".") {
                $newCategoryContent = $val;
            } else {
                // Pfad-Logik für Unter-Verschachtelung
                $parts = explode('/', $path);
                $temp = &$newCategoryContent;
                foreach ($parts as $part) {
                    if (!isset($temp[$part]) || !is_array($temp[$part])) { $temp[$part] = []; }
                    $temp = &$temp[$part];
                }
                $temp = $val;
            }
        }

        $masterData[$Category] = $newCategoryContent;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ Gruppe '$Category' gespeichert!";
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

    public function DeleteCategory(string $Name): void {
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        if (isset($masterData[$Name])) {
            unset($masterData[$Name]);
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
    // API & KRYPTO (BEWÄHRT)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) { $current = $current[$part]; } 
            else { return ""; }
        }
        return is_string($current) ? $current : json_encode($current);
    }

    private function FlattenArray($array, $prefix, &$result) {
        if (!is_array($array)) return;
        foreach ($array as $key => $value) {
            $fullKey = ($prefix === "") ? (string)$key : $prefix . "/" . $key;
            if (is_array($value)) { $this->FlattenArray($value, $fullKey, $result); }
            else { $result[] = ["Ident" => $fullKey, "Secret" => $value]; }
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