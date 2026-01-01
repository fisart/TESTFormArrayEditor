<?php

declare(strict_types=1);

class AttributeVaultTest extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString("KeyFolderPath", "");
        
        // ATTRIBUTE: Disk-Clean Speicher
        $this->RegisterAttributeString("EncryptedVault", "");
        $this->RegisterAttributeString("CurrentPath", ""); // Merkt sich die Position im Explorer
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyString("KeyFolderPath") == "" ? 104 : 102);
    }

    /**
     * DER EXPLORER-EDITOR (DYNAMISCH)
     */
    public function GetConfigurationForm(): string {
        $this->LogMessage("--- Explorer: Lade Ebene ---", KL_MESSAGE);

        // 1. Daten laden und Pfad bestimmen
        $data = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");
        
        // Navigiere im Array zum aktuellen Pfad
        $displayData = $data;
        if ($currentPath !== "") {
            $parts = explode('/', $currentPath);
            foreach ($parts as $part) {
                if (isset($displayData[$part])) {
                    $displayData = $displayData[$part];
                }
            }
        }

        // 2. Liste für die aktuelle Ebene aufbereiten
        $listValues = [];
        if (is_array($displayData)) {
            foreach ($displayData as $key => $value) {
                $isFolder = is_array($value);
                $listValues[] = [
                    "Icon"   => $isFolder ? "📁" : "🔑",
                    "Name"   => $key,
                    "Type"   => $isFolder ? "Ordner" : "Wert",
                    "Value"  => $isFolder ? "(Inhalt...)" : (string)$value
                ];
            }
        }

        // 3. Formular-Struktur bauen
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
                        ["caption" => " ", "name" => "Icon", "width" => "30px", "add" => "🔑"],
                        ["caption" => "Name", "name" => "Name", "width" => "250px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                        ["caption" => "Inhalt / Passwort", "name" => "Value", "width" => "auto", "add" => "", "edit" => ["type" => "PasswordTextBox"]],
                        ["caption" => "Typ", "name" => "Type", "width" => "100px", "add" => "Wert"]
                    ],
                    "values" => $listValues
                ],
                [
                    "type" => "Button",
                    "caption" => "💾 Änderungen auf dieser Ebene speichern",
                    "onClick" => "AVT_UpdateLevel(\$id, \$ExplorerList);"
                ]
            ]
        ];

        // Button "Öffnen" nur anzeigen, wenn Zeilen vorhanden sind, die Ordner sind
        $form['actions'][] = [
            "type" => "Label",
            "caption" => "Markieren Sie einen Ordner und klicken Sie auf Öffnen, um tiefer zu gehen."
        ];
        
        $form['actions'][] = [
            "type" => "Button",
            "caption" => "📂 Ordner öffnen",
            "onClick" => "if (isset(\$ExplorerList)) { AVT_NavigateDown(\$id, \$ExplorerList['Name']); } else { echo 'Bitte erst einen Ordner wählen'; }"
        ];

        return json_encode($form);
    }

    // =========================================================================
    // NAVIGATIONSLOGIK
    // =========================================================================

    public function NavigateDown(string $Target): void {
        $current = $this->ReadAttributeString("CurrentPath");
        $newPath = ($current === "") ? $Target : $current . "/" . $Target;
        $this->WriteAttributeString("CurrentPath", $newPath);
        // UI Refresh erzwingen
        $this->UpdateForm();
    }

    public function NavigateUp(): void {
        $current = $this->ReadAttributeString("CurrentPath");
        $parts = explode('/', $current);
        array_pop($parts);
        $this->WriteAttributeString("CurrentPath", implode('/', $parts));
        $this->UpdateForm();
    }

    private function UpdateForm() {
        $this->ReloadForm();
    }

    // =========================================================================
    // SPEICHERLOGIK (LEVEL-BASIERT)
    // =========================================================================

    public function UpdateLevel($ExplorerList): void {
        $this->LogMessage("Speichere aktuelle Explorer-Ebene...", KL_MESSAGE);
        
        // 1. Gesamtdaten laden
        $masterData = $this->DecryptData($this->ReadAttributeString("EncryptedVault"));
        $currentPath = $this->ReadAttributeString("CurrentPath");

        // 2. Den Zweig im Master-Array finden und aktualisieren
        $newDataAtLevel = [];
        foreach ($ExplorerList as $row) {
            $name = (string)$row['Name'];
            $val  = (string)$row['Value'];
            if ($name === "") continue;

            // Wenn es vorher ein Ordner war, behalten wir die Struktur bei (außer der User hat es überschrieben)
            $newDataAtLevel[$name] = $val;
        }

        // 3. Den neuen Zweig ins Master-Array einweben
        if ($currentPath === "") {
            $masterData = $newDataAtLevel;
        } else {
            $parts = explode('/', $currentPath);
            $temp = &$masterData;
            foreach ($parts as $part) {
                $temp = &$temp[$part];
            }
            // Hier müssen wir vorsichtig sein: Bestehende Ordner-Strukturen, 
            // die NICHT in der Liste waren, sollen nicht gelöscht werden.
            foreach ($newDataAtLevel as $k => $v) {
                if (isset($temp[$k]) && is_array($temp[$k]) && !is_array($v)) {
                    // War ein Ordner, ist jetzt ein String -> Wert wird überschrieben
                    $temp[$k] = $v;
                } elseif (isset($temp[$k]) && is_array($temp[$k])) {
                    // Es war ein Ordner und bleibt einer -> nichts tun, Inhalt bleibt erhalten
                } else {
                    $temp[$k] = $v;
                }
            }
            // Löschen von Elementen, die nicht mehr in der Liste sind
            foreach ($temp as $k => $v) {
                $found = false;
                foreach($ExplorerList as $row) { if ($row['Name'] == $k) $found = true; }
                if (!$found) unset($temp[$k]);
            }
        }

        // 4. Verschlüsseln und Speichern
        $encrypted = $this->EncryptData($masterData);
        $this->WriteAttributeString("EncryptedVault", $encrypted);
        echo "✅ Ebene gespeichert!";
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

    private function GetMasterKey(): string {
        $folder = $this->ReadPropertyString("KeyFolderPath");
        if ($folder === "" || !is_dir($folder)) return "";
        $path = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . 'master.key';
        if (!file_exists($path)) {
            $key = bin2hex(random_bytes(16));
            file_put_contents($path, $key);
        }
        return trim((string)file_get_contents($path));
    }

    private function EncryptData(array $data): string {
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return "";
        $plain = json_encode($data);
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        return ($cipher === false) ? "" : json_encode(["iv" => bin2hex($iv), "tag" => bin2hex($tag), "data" => base64_encode($cipher)]);
    }

    private function DecryptData(string $encrypted): array {
        if ($encrypted === "" || $encrypted === "[]") return [];
        $decoded = json_decode($encrypted, true);
        if (!$decoded || !isset($decoded['data'])) return [];
        $keyHex = $this->GetMasterKey();
        if ($keyHex === "") return [];
        $dec = openssl_decrypt(base64_decode($decoded['data']), "aes-128-gcm", hex2bin($keyHex), OPENSSL_RAW_DATA, hex2bin($decoded['iv']), hex2bin($decoded['tag']), "");
        return json_decode($dec ?: '[]', true) ?: [];
    }
}