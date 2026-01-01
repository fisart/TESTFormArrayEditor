<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        $this->RegisterAttributeString("EncryptedVault", "");
        $this->RegisterAttributeString("CurrentPath", "");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    public function GetConfigurationForm(): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        
        // Navigation im Array
        $displayData = $data;
        if ($currentPath !== "") {
            $parts = explode('/', $currentPath);
            foreach ($parts as $part) {
                if (isset($displayData[$part]) && is_array($displayData[$part])) {
                    $displayData = $displayData[$part];
                } else {
                    $displayData = [];
                    break;
                }
            }
        }

        $listValues = [];
        if (is_array($displayData)) {
            foreach ($displayData as $key => $value) {
                $isFolder = is_array($value);
                $listValues[] = [
                    "Icon"   => $isFolder ? "📁" : "🔑", 
                    "Name"   => (string)$key,
                    "Type"   => $isFolder ? "Ordner" : "Wert",
                    "Value"  => $isFolder ? "" : (string)$value
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
                    "caption" => "⬅️ Eine Ebene zurück",
                    "visible" => ($currentPath !== ""),
                    "onClick" => "AVT_NavigateUp(\$id);"
                ],
                [
                    "type" => "List",
                    "name" => "ExplorerList",
                    "caption" => "Inhalt von " . ($currentPath === "" ? "root" : $currentPath),
                    "rowCount" => 10,
                    "add" => true,
                    "delete" => true,
                    "columns" => [
                        ["caption" => " ", "name" => "Icon", "width" => "40px", "add" => "🔑"],
                        ["caption" => "Name", "name" => "Name", "width" => "250px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Inhalt (Sichtbar)", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        [
                            "caption" => "Typ", "name" => "Type", "width" => "150px", "add" => "Wert", 
                            "edit" => ["type" => "Select", "options" => [
                                ["caption" => "Wert / Passwort", "value" => "Wert"],
                                ["caption" => "Ordner (Container)", "value" => "Ordner"]
                            ]]
                        ]
                    ],
                    "values" => $listValues
                ],
                [
                    "type" => "Button",
                    "caption" => "💾 Ebene speichern",
                    "onClick" => "AVT_UpdateLevel(\$id, \$ExplorerList);"
                ],
                [
                    "type" => "Button",
                    "caption" => "📂 Ordner öffnen",
                    "onClick" => "if (isset(\$ExplorerList)) { AVT_NavigateDown(\$id, \$ExplorerList['Name'], \$ExplorerList['Type']); } else { echo 'Bitte Zeile wählen'; }"
                ],
                ["type" => "Label", "caption" => "________________________________________________________________________________________________"],
                ["type" => "Label", "caption" => "📥 JSON IMPORT (Überschreibt den aktuellen Tresor!)", "bold" => true],
                [
                    "type" => "ValidationTextBox", 
                    "name" => "ImportInput", 
                    "caption" => "JSON String hier einfügen",
                    "value" => ""
                ],
                [
                    "type" => "Button",
                    "caption" => "⚠️ JSON jetzt importieren & verschlüsseln",
                    "onClick" => "AVT_ImportJson(\$id, \$ImportInput);"
                ]
            ]
        ];

        return json_encode($form);
    }

    // =========================================================================
    // IMPORT FUNKTION
    // =========================================================================

    public function ImportJson(string $ImportInput): void {
        $this->LogMessage("Import gestartet...", KL_MESSAGE);
        
        $data = json_decode($ImportInput, true);
        if ($data === null && $ImportInput !== "[]" && $ImportInput !== "{}") {
            echo "❌ Fehler: Ungültiges JSON Format!";
            return;
        }

        $encrypted = $this->EncryptData($data ?: []);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            // Pfad zurücksetzen, damit wir am Anfang der neuen Daten landen
            $this->WriteAttributeString("CurrentPath", "");
            $this->ReloadForm();
            echo "✅ Import erfolgreich! Tresor wurde neu strukturiert.";
        }
    }

    // =========================================================================
    // NAVIGATION & SPEICHERN
    // =========================================================================

    public function NavigateDown(string $Target, string $Type): void {
        if ($Type !== "Ordner") return;
        $current = $this->ReadAttributeString("CurrentPath");
        $newPath = ($current === "") ? $Target : $current . "/" . $Target;
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

    public function UpdateLevel($ExplorerList): void {
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (!isset($temp[$part])) $temp[$part] = [];
                $temp = &$temp[$part];
            }
        }

        $newList = [];
        foreach ($ExplorerList as $row) {
            $name = (string)($row['Name'] ?? '');
            if ($name === "") continue;
            if (($row['Type'] ?? 'Wert') === "Ordner") {
                $newList[$name] = (isset($temp[$name]) && is_array($temp[$name])) ? $temp[$name] : [];
            } else {
                $newList[$name] = (string)($row['Value'] ?? '');
            }
        }

        $temp = $newList;
        $this->WriteAttributeString("EncryptedVault", $this->EncryptData($masterData));
        $this->ReloadForm();
        echo "✅ Ebene gespeichert!";
    }

    // =========================================================================
    // API & KRYPTO (UNVERÄNDERT)
    // =========================================================================

    public function GetSecret(string $Path): string {
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $parts = explode('/', $Path);
        $current = $data;
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) { $current = $current[$part]; } else { return ""; }
        }
        return is_string($current) ? $current : (json_encode($current) ?: "");
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
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        $keyHex = $this->GetMasterKey();
        if (!$decoded || $keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        return json_decode($dec ?: '[]', true) ?: [];
    }
}