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
        $this->LogMessage("--- Akkordeon-Editor: Lade Panels ---", KL_MESSAGE);

        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $form = [
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "KeyFolderPath", "caption" => "Ordner für master.key"]
            ],
            "actions" => []
        ];

        ksort($data); 
        foreach ($data as $category => $content) {
            
            $flatValues = [];
            if (is_array($content)) {
                $this->FlattenArray($content, "", $flatValues);
            } else {
                $flatValues[] = ["Ident" => ".", "Secret" => (string)$content];
            }

            // Wir nutzen einen Hash für den Listennamen (sicher gegen Sonderzeichen)
            $hash = md5($category);
            $listName = "List_" . $hash;
            
            // WICHTIG: Kategoriename für den Button-Befehl Base64 kodieren
            $b64Cat = base64_encode($category);

            $form['actions'][] = [
                "type" => "ExpansionPanel",
                "caption" => "📁 " . $category . " (" . count($flatValues) . " Einträge)",
                "items" => [
                    [
                        "type" => "List",
                        "name" => $listName,
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
                        // Wir übergeben den Namen Base64-kodiert an PHP
                        "onClick" => "AVT_UpdateCategory(\$id, '$b64Cat', \$$listName);"
                    ],
                    [
                        "type" => "Button",
                        "caption" => "🗑️ Gesamte Kategorie löschen",
                        "onClick" => "AVT_DeleteCategory(\$id, '$b64Cat');"
                    ]
                ]
            ];
        }

        $form['actions'][] = ["type" => "Label", "caption" => "________________________________________________________________________________________________"];
        $form['actions'][] = ["type" => "Label", "caption" => "➕ Neue Hauptkategorie anlegen", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "NewCategoryName", "caption" => "Name der neuen Gruppe", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "Gruppe erstellen", "onClick" => "AVT_AddCategory(\$id, \$NewCategoryName);"];

        $form['actions'][] = ["type" => "Label", "caption" => "📥 JSON IMPORT", "bold" => true];
        $form['actions'][] = ["type" => "ValidationTextBox", "name" => "ImportInput", "caption" => "JSON String hier einfügen", "value" => ""];
        $form['actions'][] = ["type" => "Button", "caption" => "JSON importieren", "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"];

        return json_encode($form);
    }

    // =========================================================================
    // SPEICHER-AKTIONEN (JETZT MIT BASE64 DECODE)
    // =========================================================================

    public function UpdateCategory(string $CategoryBase64, $ListData): void {
        $category = base64_decode($CategoryBase64);
        $this->LogMessage("Update für Kategorie: " . $category, KL_MESSAGE);

        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        
        $newCategoryContent = [];
        foreach ($ListData as $row) {
            $path = (string)($row['Ident'] ?? '');
            $val  = (string)($row['Secret'] ?? '');
            if ($path === "") continue;

            if ($path === ".") {
                $newCategoryContent = $val;
            } else {
                $parts = explode('/', $path);
                $temp = &$newCategoryContent;
                foreach ($parts as $part) {
                    if (!isset($temp[$part]) || !is_array($temp[$part])) { $temp[$part] = []; }
                    $temp = &$temp[$part];
                }
                $temp = $val;
            }
        }

        $masterData[$category] = $newCategoryContent;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ '$category' gespeichert!";
    }

    public function DeleteCategory(string $CategoryBase64): void {
        $category = base64_decode($CategoryBase64);
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        if (isset($masterData[$category])) {
            unset($masterData[$category]);
            $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
            $this->ReloadForm();
            echo "🗑️ '$category' gelöscht.";
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
    // API & KRYPTO (BEWÄHRT)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        $current = $data;
        foreach ($parts as $part) {
            if (isset($current[$part])) { $current = $current[$part]; } 
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