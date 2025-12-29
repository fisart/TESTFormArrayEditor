<?php

class ArrayEditorTest extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // List content is stored as JSON string
        $this->RegisterPropertyString('Entries', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // For testing, you could log the converted array:
        // $entries = $this->GetEntriesAssoc();
        // IPS_LogMessage('ArrayEditorTest', print_r($entries, true));
    }

    /**
     * Returns the entries as an associative array:
     * [ 'Key' => [User, PW, URL, Location, IP], ... ]
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
            if (!isset($row['Key']) || $row['Key'] === '') {
                // Ignore rows without a key
                continue;
            }
            $key = $row['Key'];
            unset($row['Key']);
            $result[$key] = $row;
        }

        return $result;
    }

    /**
     * (Optional) Simple test function you can call from a script.
     */
    public function DebugDump()
    {
        IPS_LogMessage('ArrayEditorTest', print_r($this->GetEntriesAssoc(), true));
    }
}
