<?php

class OilfoxIPSymconModul extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // === Properties ===
        $this->RegisterPropertyBoolean("Active", true);
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("DeviceIDs", ""); // CSV: mehrere Geräte-IDs
        $this->RegisterPropertyInteger("UpdateInterval", 3600);
        $this->RegisterPropertyBoolean("Debug", false);

        // === Attributes ===
        $this->RegisterAttributeString("PasswordEncrypted", "");
        $this->RegisterAttributeString("EncryptionKey", "OilfoxSecretKey123"); // AES-256 Schlüssel

        // === Timer ===
        $this->RegisterTimer(
            "UpdateTimer",
            0,
            'OILFOX_Update($_IPS["TARGET"]);'
        );

        // === Token Variablen ===
        $this->RegisterVariableString("access_token", "Access Token");
        $this->RegisterVariableString("refresh_token", "Refresh Token");

        // === Profiles erstellen ===
        $this->CreateProfiles();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Passwort verschlüsselt speichern
        if ($this->ReadPropertyString("Password") !== "") {
            $key = $this->ReadAttributeString("EncryptionKey");
            $password = $this->ReadPropertyString("Password");
            $encrypted = $this->EncryptPassword($password, $key);
            $this->WriteAttributeString("PasswordEncrypted", $encrypted);
            IPS_SetProperty($this->InstanceID, "Password", "");
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // Timer setzen
        $interval = max(300, $this->ReadPropertyInteger("UpdateInterval"));
        $this->SetTimerInterval("UpdateTimer", $interval * 1000);
    }

    // ===================== CONFIG FORM =====================
    public function GetConfigurationForm()
    {
        $formFile = __DIR__ . "/form.json";
        if (!file_exists($formFile)) {
            $this->LogMessage("form.json nicht gefunden: $formFile", KL_ERROR);
            return json_encode(["elements"=>[]]);
        }

        $form = json_decode(file_get_contents($formFile), true);
        if ($form === null) {
            $this->LogMessage("form.json konnte nicht decodiert werden", KL_ERROR);
            return json_encode(["elements"=>[]]);
        }

        return json_encode($form);
    }

    // ===================== PUBLIC ACTIONS =====================
    public function Update()
    {
        if (!$this->ReadPropertyBoolean("Active")) {
            $this->DebugLog("Instanz deaktiviert, Update übersprungen");
            return;
        }

        if (!$this->EnsureAccessToken()) {
            $this->SetStatus(201);
            return;
        }

        $deviceIDs = array_map('trim', explode(",", $this->ReadPropertyString("DeviceIDs")));
        foreach ($deviceIDs as $deviceID) {
            if ($deviceID === "") continue;
            $this->UpdateDevice($deviceID);
        }

        $this->SetStatus(102);
    }

    private function UpdateDevice(string $deviceID)
    {
        // Kategorie für das Gerät anlegen
        $catIdent = "Device_" . $deviceID;
        $catID = @IPS_GetObjectIDByIdent($catIdent, $this->InstanceID);
        if ($catID === false) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetName($catID, $deviceID);
            IPS_SetIdent($catID, $catIdent);
        }

        // API abrufen
        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/device/" . $deviceID,
            "GET",
            "",
            ["Authorization: Bearer " . $this->GetValue("access_token")]
        );

        if (!$result && $this->RefreshToken()) {
            return $this->UpdateDevice($deviceID);
        }

        if (!$result) {
            $this->SetStatus(202);
            return;
        }

        // Variablen für Gerät anlegen oder aktualisieren
        $this->CreateOrUpdateVariable($catID, "hwid", "Hardware ID", 3, $result->hwid);
        $this->CreateOrUpdateVariable($catID, "currentMeteringAt", "Letzte Messung", 3, $result->currentMeteringAt);
        $this->CreateOrUpdateVariable($catID, "fillLevelPercent", "Füllstand", 1, $result->fillLevelPercent, "OILFOX.Percent");
        $this->CreateOrUpdateVariable($catID, "batteryLevel", "Batterie", 3, $result->batteryLevel, "OILFOX.Battery");
        $this->CreateOrUpdateVariable($catID, "daysReach", "Reichweite", 1, $result->daysReach, "OILFOX.Days");
        $this->CreateOrUpdateVariable($catID, "fillLevelQuantity", "Füllmenge", 1, $result->fillLevelQuantity, "OILFOX.Liter");
        $this->CreateOrUpdateVariable($catID, "nextMeteringAt", "Nächste Messung", 3, $result->nextMeteringAt);
        $this->CreateOrUpdateVariable($catID, "quantityUnit", "Einheit", 3, $result->quantityUnit);
    }

    private function CreateOrUpdateVariable(int $parentID, string $ident, string $name, int $varType, $value, string $profile = "")
    {
        $varID = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($varID === false) {
            $varID = IPS_CreateVariable($varType);
            IPS_SetParent($varID, $parentID);
            IPS_SetName($varID, $name);
            IPS_SetIdent($varID, $ident);
            if ($profile !== "") {
                IPS_SetVariableCustomProfile($varID, $profile);
            }
        }
        SetValue($varID, $value);
    }

    // ===================== TOKEN HANDLING =====================
    private function EnsureAccessToken(): bool
    {
        if ($this->GetValue("access_token") === "") {
            return $this->Login();
        }
        return true;
    }

    private function Login(): bool
    {
        $key = $this->ReadAttributeString("EncryptionKey");
        $password = $this->DecryptPassword($this->ReadAttributeString("PasswordEncrypted"), $key);

        $result = $this->RequestJson(
            "https://api.oilfox.io/customer-api/v1/login",
            "POST",
            json_encode([
                "email" => $this->ReadPropertyString("Email"),
                "password" => $password
            ]),
            ["Content-Type: application/json"]
        );

        if (!$result) return false;

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

        if (!$result) return false;

        $this->SetValue("access_token", $result->access_token);
        $this->SetValue("refresh_token", $result->refresh_token);
        return true;
    }

    // ===================== API HELPER =====================
    private function RequestJson(string $url, string $method, string $payload, array $headers)
    {
        $this->DebugLog("API $method: $url");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15
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

    // ===================== ENCRYPTION =====================
    private function EncryptPassword(string $password, string $key): string
    {
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivlen);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function DecryptPassword(string $encrypted, string $key): string
    {
        $data = base64_decode($encrypted);
        $ivlen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($data, 0, $ivlen);
        $ciphertext = substr($data, $ivlen);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv);
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists("OILFOX.Percent")) {
            IPS_CreateVariableProfile("OILFOX.Percent", 1);
            IPS_SetVariableProfileText("OILFOX.Percent", "", " %");
        }

        if (!IPS_VariableProfileExists("OILFOX.Days")) {
            IPS_CreateVariableProfile("OILFOX.Days", 1);
            IPS_SetVariableProfileText("OILFOX.Days", "", " Tage");
        }

        if (!IPS_VariableProfileExists("OILFOX.Liter")) {
            IPS_CreateVariableProfile("OILFOX.Liter", 1);
            IPS_SetVariableProfileText("OILFOX.Liter", "", " L");
        }

        if (!IPS_VariableProfileExists("OILFOX.Battery")) {
            IPS_CreateVariableProfile("OILFOX.Battery", 3); // String
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "OK", "OK", "", 0x00FF00);
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "LOW", "Niedrig", "", 0xFFFF00);
            IPS_SetVariableProfileAssociation("OILFOX.Battery", "CRITICAL", "Kritisch", "", 0xFF0000);
        }
    }
}
