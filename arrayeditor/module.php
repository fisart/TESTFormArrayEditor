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
        
        // Navigation zum aktuellen Pfad
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
                        ["caption" => " ", "name" => "Icon", "width" => "35px", "add" => "🔑"],
                        ["caption" => "Name", "name" => "Name", "width" => "250px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        [
                            "caption" => "Inhalt / Passwort", 
                            "name" => "Value", 
                            "width" => "auto", 
                            "add" => "", 
                            "edit" => ["type" => "ValidationTextBox"] // Geändert von Password zu Validation für Sichtbarkeit
                        ],
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
                    "values" => $listValues
                ],
                [
                    "type" => "Button",
                    "caption" => "💾 Änderungen auf dieser Ebene speichern",
                    "onClick" => "AVT_UpdateLevel(\$id, \$ExplorerList);"
                ],
                [
                    "type" => "Label",
                    "caption" => "Markieren Sie einen Ordner (📁) und klicken Sie auf 'Ordner öffnen'."
                ],
                [
                    "type" => "Button",
                    "caption" => "📂 Ordner öffnen",
                    "onClick" => "if (isset(\$ExplorerList)) { AVT_NavigateDown(\$id, \$ExplorerList['Name'], \$ExplorerList['Type']); } else { echo 'Bitte erst einen Ordner wählen'; }"
                ]
            ]
        ];

        return json_encode($form);
    }

    // =========================================================================
    // NAVIGATION
    // =========================================================================

    public function NavigateDown(string $Target, string $Type): void {
        if ($Type !== "Ordner") {
            echo "Dies ist kein Ordner und kann nicht geöffnet werden.";
            return;
        }
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

    // =========================================================================
    // SPEICHERN
    // =========================================================================

    public function UpdateLevel($ExplorerList): void {
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        // Pointer auf die aktuelle Ebene setzen
        $temp = &$masterData;
        if ($currentPath !== "") {
            foreach (explode('/', $currentPath) as $part) {
                if (!isset($temp[$part]) || !is_array($temp[$part])) {
                    $temp[$part] = [];
                }
                $temp = &$temp[$part];
            }
        }

        $newList = [];
        foreach ($ExplorerList as $row) {
            $name = (string)($row['Name'] ?? '');
            if ($name === "") continue;

            $type = (string)($row['Type'] ?? 'Wert');
            $val  = (string)($row['Value'] ?? '');

            if ($type === "Ordner") {
                // Bestehenden Inhalt behalten, falls es schon ein Ordner war, sonst leeres Array
                $newList[$name] = (isset($temp[$name]) && is_array($temp[$name])) ? $temp[$name] : [];
            } else {
                $newList[$name] = $val;
            }
        }

        // Ebene im Master-Array ersetzen
        $temp = $newList;

        // --- DEBUG AUSGABE ---
        $jsonCheck = json_encode($masterData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->LogMessage("--- TRESOR STRUKTUR VOR SPEICHERUNG ---", KL_MESSAGE);
        $this->LogMessage($jsonCheck, KL_MESSAGE);

        // Verschlüsseln und Speichern
        $encrypted = $this->EncryptData($masterData);
        if ($encrypted !== "") {
            $this->WriteAttributeString("EncryptedVault", $encrypted);
            $this->ReloadForm();
            echo "✅ Ebene gespeichert!";
        }
    }

    // =========================================================================
    // KRYPTO (BEWÄHRT)
    // =========================================================================

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            file_put_contents($path, bin2hex(random_bytes(16)));
        }
        return trim((string)file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $key = $this->GetMasterKey();
        if ($key === "") return "";
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt(json_encode($data), "aes-128-gcm", hex2bin($key), OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        return json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        $key = $this->GetMasterKey();
        if (!$decoded || $key === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($key), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        return json_decode($dec ?: '[]', true) ?: [];
    }
}