<?php

class OilfoxIPSymconModul extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("DeviceID", "");
        $this->RegisterPropertyInteger("UpdateInterval", 3600);
        $this->RegisterPropertyBoolean("Debug", false);

        // Attribute (verschlüsselt)
        $this->RegisterAttributeString("PasswordEncrypted", "");

        // Timer
        $this->RegisterTimer(
            "UpdateTimer",
            0,
            'OILFOX_Update($_IPS["TARGET"]);'
        );

        // Token
        $this->RegisterVariableString("access_token", "Access Token");
        $this->RegisterVariableString("refresh_token", "Refresh Token");

        // Profiles
        $this->RegisterProfileInteger("OILFOX.Percent", "", "", " %", 0, 100, 1);
        $this->RegisterProfileInteger("OILFOX.Days", "", "", " Tage", 0, 365, 1);
        $this->RegisterProfileInteger("OILFOX.Liter", "", "", " L", 0, 200000, 1);

        if (!IPS_VariableProfileExists("OILFOX.Battery")) {
            IPS_CreateVariableProfile("OILFOX.Battery", 3);
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "OK", "OK", "", 0x00FF00);
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "LOW", "Niedrig", "", 0xFFFF00);
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "CRITICAL", "Kritisch", "", 0xFF0000);
        }

        // Device Variables
        $this->RegisterVariableString("hwid", "Hardware ID");
        $this->RegisterVariableString("currentMeteringAt", "Letzte Messung");
        $this->RegisterVariableInteger("fillLevelPercent", "Füllstand", "OILFOX.Percent");
        $this->RegisterVariableString("batteryLevel", "Batterie", "OILFOX.Battery");
        $this->RegisterVariableInteger("daysReach", "Reichweite", "OILFOX.Days");
        $this->RegisterVariableInteger("fillLevelQuantity", "Füllmenge", "OILFOX.Liter");
        $this->RegisterVariableString("nextMeteringAt", "Nächste Messung");
        $this->RegisterVariableString("quantityUnit", "Einheit");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Passwort speichern
        if ($this->ReadPropertyString("Password") !== "") {
            $this->WriteAttributeString(
                "PasswordEncrypted",
                IPS_Encrypt($this->ReadPropertyString("Password"))
            );
            IPS_SetProperty($this->InstanceID, "Password", "");
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetTimerInterval(
            "UpdateTimer",
            max(300, $this->ReadPropertyInteger("UpdateInterval")) * 1000
        );
    }

    public function Update()
    {
        if (!$this->EnsureAccessToken()) {
            $this->SetStatus(201);
            return;
        }

        if ($this->ReadPropertyString("DeviceID") === "") {
            $this->DebugLog("Keine DeviceID gesetzt");
            return;
        }

        $this->ReadDeviceData();
        $this->SetStatus(102);
    }

    public function LoadDevices()
    {
        if (!$this->EnsureAccessToken()) {
            $this->SetStatus(201);
            return;
        }

        $devices = $this->GetDevices();

        // Auto-Select bei genau einem Gerät
        if (count($devices) === 1) {
            IPS_SetProperty($this->InstanceID, "DeviceID", $devices[0]["value"]);
            IPS_ApplyChanges($this->InstanceID);
        }

        $this->ReloadForm();
    }

    /* ================= TOKEN ================= */

    private function EnsureAccessToken(): bool
    {
        if ($this->GetValue("access_token") === "") {
            return $this->Login();
        }
        return true;
    }

    private function Login(): bool
    {
        $password = IPS_Decrypt($this->ReadAttributeString("PasswordEncrypted"));

        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/login",
            "POST",
            json_encode([
                "email"    => $this->ReadPropertyString("Email"),
                "password" => $password
            ]),
            ["Content-Type: application/json"]
        );

        if (!$result) {
            return false;
        }

        $this->SetValue("access_token", $result->access_token);
        $this->SetValue("refresh_token", $result->refresh_token);
        return true;
    }

    private function RefreshToken(): bool
    {
        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/token?refresh_token=" . $this->GetValue("refresh_token"),
            "POST",
            "",
            ["Content-Type: application/x-www-form-urlencoded"]
        );

        if (!$result) {
            return false;
        }

        $this->SetValue("access_token", $result->access_token);
        $this->SetValue("refresh_token", $result->refresh_token);
        return true;
    }

    /* ================= API ================= */

    private function GetDevices(): array
    {
        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/device",
            "GET",
            "",
            ["Authorization: Bearer " . $this->GetValue("access_token")]
        );

        if (!is_array($result)) {
            return [];
        }

        $list = [];
        foreach ($result as $device) {
            $list[] = [
                "caption" => $device->hwid,
                "value"   => $device->hwid
            ];
        }

        return $list;
    }

    private function ReadDeviceData()
    {
        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/device/" . $this->ReadPropertyString("DeviceID"),
            "GET",
            "",
            ["Authorization: Bearer " . $this->GetValue("access_token")]
        );

        if (!$result && $this->RefreshToken()) {
            return $this->ReadDeviceData();
        }

        if (!$result) {
            $this->SetStatus(202);
            return;
        }

        foreach ($result as $ident => $value) {
            if ($this->GetIDForIdent($ident) !== false && $value !== null) {
                $this->SetValue($ident, $value);
            }
        }
    }

    /* ================= HELPER ================= */

    private function RequestJson(string $url, string $method, string $payload, array $headers)
    {
        $this->DebugLog("API $method: $url");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->LogMessage(curl_error($ch), KL_ERROR);
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        return json_decode($response);
    }

    private function DebugLog(string $msg)
    {
        if ($this->ReadPropertyBoolean("Debug")) {
            $this->LogMessage($msg, KL_DEBUG);
        }
    }
}
