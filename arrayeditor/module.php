<?php

class TestModul extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Entries', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        $form = json_decode(@file_get_contents($formPath), true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => []];
        }

        $entries = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($entries)) {
            $entries = [];
        }

        // Unique Locations from Location field only
        $seen = [];
        $locationOptions = [];
        foreach ($entries as $row) {
            $loc = trim((string)($row['Location'] ?? ''));
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $locationOptions[] = ['caption' => $loc, 'value' => $loc];
        }

        // Inject into LocationPick column
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

    public function GetEntriesAssoc(): array
    {
        $list = json_decode($this->ReadPropertyString('Entries'), true);
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
            unset($row['LocationPick']); // UI helper only

            $result[$key] = $row;
        }

        return $result;
    }

    public function DebugDump()
    {
        IPS_LogMessage('TestModul', print_r($this->GetEntriesAssoc(), true));
    }
}
