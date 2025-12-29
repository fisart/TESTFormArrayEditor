<?php

class TestModul extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Stores the List content as JSON string
        $this->RegisterPropertyString('Entries', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /**
     * Dynamically inject options into the LocationPick dropdown:
     * unique values from the Location field across ALL entries.
     */
    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        $form = json_decode(@file_get_contents($formPath), true);
        if (!is_array($form)) {
            // fallback minimal form if file missing/broken
            $form = ['elements' => [], 'actions' => []];
        }

        $entries = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($entries)) {
            $entries = [];
        }

        // Collect unique locations from Location field ONLY
        $seen = [];
        $locationOptions = [];
        foreach ($entries as $row) {
            $loc = trim((string)($row['Location'] ?? ''));
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $locationOptions[] = [
                'caption' => $loc,
                'value'   => $loc
            ];
        }

        // Inject options into the LocationPick column
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'List' && ($el['name'] ?? '') === 'Entries') {
                foreach ($el['columns'] as &$col) {
                    if (($col['name'] ?? '') === 'LocationPick') {
                        $col['edit']['options'] = $locationOptions;
                    }
                }
            }
        }

        return json_encode($form);
    }

    /**
     * Copy LocationPick -> Location for all rows that have a pick selected.
     * Called via Button in the form.
     */
    public function ApplyLocationPicks()
    {
        $entries = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as &$row) {
            $pick = trim((string)($row['LocationPick'] ?? ''));
            if ($pick !== '') {
                $row['Location'] = $pick;
                $row['LocationPick'] = '';
            }
        }

        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Returns associative array: [Key => [User,PW,URL,Location,IP], ...]
     */
    public function GetEntriesAssoc(): array
    {
        $json = $this->ReadPropertyString('Entries');
        $list = json_decode($json, true);
        if (!is_array($list)) {
            return [];
        }

        $result = [];
        foreach ($list as $row) {
            $key = trim((string)($row['Key'] ?? ''));
            if ($key === '') {
                continue;
            }

            unset($row['Key']);
            unset($row['LocationPick']); // helper UI field, not part of the real data

            $result[$key] = $row;
        }

        return $result;
    }

    public function DebugDump()
    {
        IPS_LogMessage('TestModul', print_r($this->GetEntriesAssoc(), true));
    }
}

