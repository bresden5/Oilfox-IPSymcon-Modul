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
        $this->RegisterPropertyString("DeviceID", "");
        $this->RegisterPropertyBoolean("Debug", false);

        // === Attributes ===
        $this->RegisterAttributeString("PasswordEncrypted", "");
        $this->RegisterAttributeString("EncryptionKey", "OilfoxAESKey");

        // === Timer ===
        $this->RegisterTimer(
            "UpdateTimer",
            0,
            'OILFOX_Update($_IPS["TARGET"]);'
        );

        // === Token ===
        $this->RegisterVariableString("access_token", "Access Token");
        $this->RegisterVariableString("refresh_token", "Refresh Token");

        $this->CreateProfiles();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Passwort verschlüsseln
        if ($this->ReadPropertyString("Password") !== "") {
            $enc = $this->Encrypt(
                $this->ReadPropertyString("Password"),
                $this->ReadAttributeString("EncryptionKey")
            );
            $this->WriteAttributeString("PasswordEncrypted", $enc);
            IPS_SetProperty($this->InstanceID, "Password", "");
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // 🔹 Initialer Start nach Übernehmen
        if ($this->ReadPropertyBoolean("Active")) {
            $this->SetTimerInterval("UpdateTimer", 10 * 1000);
            $this->Debug("Initiales Update in 10 Sekunden geplant");
        } else {
            $this->SetTimerInterval("UpdateTimer", 0);
        }
    }

    // ===================== UPDATE =====================
    public function Update()
    {
        if (!$this->ReadPropertyBoolean("Active")) {
            $this->Debug("Instanz deaktiviert");
            return;
        }

        if ($this->ReadPropertyString("DeviceID") === "") {
            $this->SetStatus(203);
            return;
        }

        if (!$this->EnsureToken()) {
            $this->SetStatus(201);
            return;
        }

        $this->UpdateDevice($this->ReadPropertyString("DeviceID"));
        $this->SetStatus(102);
    }

    // ===================== DEVICE =====================
    private function UpdateDevice(string $deviceID)
    {
        $catID = @IPS_GetObjectIDByIdent("DeviceData", $this->InstanceID);
        if ($catID === false) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, "DeviceData");
            IPS_SetName($catID, "Gerätedaten");
        }

        $data = $this->Request(
            "https://api.oilfox.io/customer-api/v1/device/" . $deviceID,
            "GET",
            "",
            ["Authorization: Bearer " . $this->GetValue("access_token")]
        );

        if (!$data && $this->RefreshToken()) {
            return $this->UpdateDevice($deviceID);
        }

        if (!$data) {
            $this->SetStatus(202);
            return;
        }

        $this->SetVar($catID, "fillLevelPercent", "Füllstand", 1, $data->fillLevelPercent, "OILFOX.Percent");
        $this->SetVar($catID, "fillLevelQuantity", "Füllmenge", 1, $data->fillLevelQuantity, "OILFOX.Liter");
        $this->SetVar($catID, "daysReach", "Reichweite", 1, $data->daysReach, "OILFOX.Days");
        $this->SetVar($catID, "batteryLevel", "Batterie", 3, $data->batteryLevel);
        $this->SetVar($catID, "nextMeteringAt", "Nächste Messung", 3, $data->nextMeteringAt);

        // ===== Timer sauber & zuverlässig setzen =====
        $nextTimestamp = null;

        if (!empty($data->nextMeteringAt)) {
            $nextTimestamp = $this->ParseNextMetering($data->nextMeteringAt);
        }

        if ($nextTimestamp !== null) {

            if ($nextTimestamp <= time()) {
                // Messung liegt in der Vergangenheit → 15 Minuten ab jetzt
                $nextTimestamp = time() + (15 * 60);
                $this->Debug("nextMeteringAt liegt in der Vergangenheit – +15 Minuten ab jetzt");
            }

            // Mindestintervall 60 Sekunden
            $delayMs = max(60 * 1000, ($nextTimestamp - time()) * 1000);
            $this->SetTimerInterval("UpdateTimer", $delayMs);

            $this->Debug(
                "Nächstes Update geplant um " .
                date("d.m.Y H:i:s", $nextTimestamp) .
                " (in " . round($delayMs / 60000, 1) . " Minuten)"
            );
        } else {
            // Fallback
            $this->SetTimerInterval("UpdateTimer", 6 * 60 * 60 * 1000);
            $this->Debug("Kein gültiges nextMeteringAt – Fallback 6 Stunden");
        }
    }

    // ===================== TIME PARSER =====================
    private function ParseNextMetering(string $value): ?int
    {
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->getTimestamp() + (15 * 60);
        } catch (Exception $e) {
            return null;
        }
    }

    // ===================== TOKEN =====================
    private function EnsureToken(): bool
    {
        return $this->GetValue("access_token") !== "" || $this->Login();
    }

    private function Login(): bool
    {
        $pw = $this->Decrypt(
            $this->ReadAttributeString("PasswordEncrypted"),
            $this->ReadAttributeString("EncryptionKey")
        );

        $res = $this->Request(
            "https://api.oilfox.io/customer-api/v1/login",
            "POST",
            json_encode([
                "email"    => $this->ReadPropertyString("Email"),
                "password" => $pw
            ]),
            ["Content-Type: application/json"]
        );

        if (!$res) return false;

        $this->SetValue("access_token", $res->access_token);
        $this->SetValue("refresh_token", $res->refresh_token);
        return true;
    }

    private function RefreshToken(): bool
    {
        $res = $this->Request(
            "https://api.oilfox.io/customer-api/v1/token?refresh_token=" . $this->GetValue("refresh_token"),
            "POST",
            "",
            ["Content-Type: application/x-www-form-urlencoded"]
        );

        if (!$res) return false;

        $this->SetValue("access_token", $res->access_token);
        $this->SetValue("refresh_token", $res->refresh_token);
        return true;
    }

    // ===================== HELPERS =====================
    private function SetVar($parent, $ident, $name, $type, $value, $profile = "")
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            if ($profile !== "") {
                IPS_SetVariableCustomProfile($id, $profile);
            }
        }
        SetValue($id, $value);
    }

    private function Request($url, $method, $payload, $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res);
    }

    private function Encrypt($text, $key)
    {
        $iv = random_bytes(16);
        return base64_encode(
            $iv . openssl_encrypt($text, 'AES-256-CBC', $key, 0, $iv)
        );
    }

    private function Decrypt($text, $key)
    {
        $data = base64_decode($text);
        return openssl_decrypt(
            substr($data, 16),
            'AES-256-CBC',
            $key,
            0,
            substr($data, 0, 16)
        );
    }

    private function Debug($msg)
    {
        if ($this->ReadPropertyBoolean("Debug")) {
            $this->LogMessage($msg, KL_DEBUG);
        }
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists("OILFOX.Percent")) {
            IPS_CreateVariableProfile("OILFOX.Percent", 1);
            IPS_SetVariableProfileText("OILFOX.Percent", "", " %");
        }
        if (!IPS_VariableProfileExists("OILFOX.Liter")) {
            IPS_CreateVariableProfile("OILFOX.Liter", 1);
            IPS_SetVariableProfileText("OILFOX.Liter", "", " L");
        }
        if (!IPS_VariableProfileExists("OILFOX.Days")) {
            IPS_CreateVariableProfile("OILFOX.Days", 1);
            IPS_SetVariableProfileText("OILFOX.Days", "", " Tage");
        }
    }
}
