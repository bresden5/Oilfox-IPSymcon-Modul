<?php

class OilfoxTokenModule extends IPSModule {

    // Konstruktor
    public function __construct($InstanceID) {
        parent::__construct($InstanceID);
    }

    // Create-Methode
    public function Create() {
        parent::Create();

        // Beispiel für Konfigurationsformular (E-Mail, Passwort, Geräte-IDs als Array)
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("DeviceIDs", "[]"); // Geräteliste als JSON-Array

        // Token-Variablen registrieren
        $this->RegisterVariableString("access_token", "Access Token");
        $this->RegisterVariableString("refresh_token", "Refresh Token");

        // Variablenprofile erstellen
        $this->CreateVariableProfiles();
    }

    // ApplyChanges-Methode
    public function ApplyChanges() {
        parent::ApplyChanges();
    }
    // Methode zum Abrufen der Tokens
    public function RequestToken() {
        $email = $this->ReadPropertyString("Email");
        $password = $this->ReadPropertyString("Password");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.oilfox.io/customer-api/v1/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{\"password\":\"$password\",\"email\":\"$email\"}");
        curl_setopt($ch, CURLOPT_POST, 1);

        $headers = array();
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
            return;
        }

        curl_close($ch);

        $a = json_decode($result);
        if (isset($a->{"access_token"}) && isset($a->{"refresh_token"})) {
            $this->SetValue("access_token", $a->{"access_token"});
            $this->SetValue("refresh_token", $a->{"refresh_token"});
        } else {
            echo "Fehler beim Abrufen des Tokens.";
        }
    }

    // Methode zum Aktualisieren des Tokens mit dem Refresh Token
    public function RefreshToken() {
        $refresh_token = $this->GetValue("refresh_token");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.oilfox.io/customer-api/v1/token?refresh_token=' . $refresh_token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "-urlencode");

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
            return;
        }

        curl_close($ch);

        $a = json_decode($result);
        if (isset($a->{"access_token"}) && isset($a->{"refresh_token"})) {
            $this->SetValue("access_token", $a->{"access_token"});
            $this->SetValue("refresh_token", $a->{"refresh_token"});
        } else {
            echo "Fehler beim Aktualisieren des Tokens.";
        }
    }
    // Methode zum Abrufen der Gerätedaten für alle Geräte
    public function GetDeviceData() {
        $access_token = $this->GetValue("access_token");
        $deviceIDs = json_decode($this->ReadPropertyString("DeviceIDs"), true);

        foreach ($deviceIDs as $deviceID) {
            $this->GetSingleDeviceData($deviceID, $access_token);
        }
    }

    // Methode zum Abrufen der Daten eines einzelnen Geräts
    private function GetSingleDeviceData($deviceID, $access_token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.oilfox.io/customer-api/v1/device/' . $deviceID);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $headers = array();
        $headers[] = "Authorization: Bearer " . $access_token;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
            return;
        }

        curl_close($ch);
        $a = json_decode($result);

        $this->SetValueForDevice($deviceID, "hwid", $a->{"hwid"});
        $this->SetValueForDevice($deviceID, "currentMeteringAt", $a->{"currentMeteringAt"});
        $this->SetValueForDevice($deviceID, "fillLevelPercent", $a->{"fillLevelPercent"});
        $this->SetValueForDevice($deviceID, "batteryLevel", $a->{"batteryLevel"});
        $this->SetValueForDevice($deviceID, "daysReach", $a->{"daysReach"});
        $this->SetValueForDevice($deviceID, "fillLevelQuantity", $a->{"fillLevelQuantity"});
        $this->SetValueForDevice($deviceID, "nextMeteringAt", $a->{"nextMeteringAt"});
        $this->SetValueForDevice($deviceID, "quantityUnit", $a->{"quantityUnit"});
    }
    // Hilfsfunktion zum Setzen von Variablenwerten für ein bestimmtes Gerät
    private function SetValueForDevice($deviceID, $ident, $value) {
        $fullIdent = $ident . "_" . $deviceID;
        $id = @$this->GetIDForIdent($fullIdent);

        if ($id === false) {
            switch ($ident) {
                case "fillLevelPercent":
                case "daysReach":
                case "fillLevelQuantity":
                    $this->RegisterVariableInteger($fullIdent, ucfirst($ident) . " (" . $deviceID . ")", $this->GetProfileName($ident));
                    break;
                default:
                    $this->RegisterVariableString($fullIdent, ucfirst($ident) . " (" . $deviceID . ")");
                    break;
            }
            $id = $this->GetIDForIdent($fullIdent);
        }

        SetValue($id, $value);
    }

    // Hilfsfunktion zum Abrufen des Variablenprofils basierend auf dem Ident
    private function GetProfileName($ident) {
        switch ($ident) {
            case "fillLevelPercent":
                return "intProzent";
            case "daysReach":
                return "intTage";
            case "fillLevelQuantity":
                return "intLiter";
            default:
                return "";
        }
    }

    // Funktion zum Erstellen von Variablenprofilen
    private function CreateVariableProfiles() {
        if (!IPS_VariableProfileExists("intTage")) {
            IPS_CreateVariableProfile("intTage", 1);
            IPS_SetVariableProfileText("intTage", "", " Tage");
        }

        if (!IPS_VariableProfileExists("intProzent")) {
            IPS_CreateVariableProfile("intProzent", 1);
            IPS_SetVariableProfileText("intProzent", "", " %");
        }

        if (!IPS_VariableProfileExists("intLiter")) {
            IPS_CreateVariableProfile("intLiter", 1);
            IPS_SetVariableProfileText("intLiter", "", " L");
        }
    }
}
?>