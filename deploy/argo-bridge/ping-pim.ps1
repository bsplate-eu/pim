<#
    ARGO Bridge -> PIM: zgloszenie obecnosci ("ping").

    Uruchamiane cyklicznie na maszynie, na ktorej stoi Bridge i Subiekt GT.
    Nic nie czyta z Subiekta i nic nie zapisuje - melduje tylko, ze maszyna
    zyje i ma lacznosc z PIM. Dzieki temu kropka w PIM (Magazyn -> Ustawienia
    -> Argo Bridge) mowi prawde, zanim powstanie wysylka stanow.

    Token trzymamy w osobnym pliku obok skryptu, a nie w kodzie - zeby dalo sie
    go wymienic bez ruszania skryptu i zeby nie wedrowal razem z nim po historii.

    Wyjscie: kod 0 = zgloszono, 1 = nie udalo sie. Log dopisywany do ping.log.
#>

param(
    [string] $Url = 'https://pim.bsplate.eu/api/argo-bridge/ping',
    [string] $TokenFile = "$PSScriptRoot\token.txt",
    [string] $Version = '0.1.0-pilot',
    [string] $LogFile = "$PSScriptRoot\ping.log"
)

$ErrorActionPreference = 'Stop'

function Write-Log([string] $Message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Output $line
    Add-Content -Path $LogFile -Value $line -Encoding utf8
}

# Windows Server bez tego potrafi probowac TLS 1.0 i dostac zerwane polaczenie.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not (Test-Path $TokenFile)) {
    Write-Log "BLAD: brak pliku z tokenem ($TokenFile). Wygeneruj token w PIM i wklej go do tego pliku."
    exit 1
}

$token = (Get-Content -Path $TokenFile -Raw).Trim()

if ([string]::IsNullOrWhiteSpace($token)) {
    Write-Log "BLAD: plik z tokenem jest pusty ($TokenFile)."
    exit 1
}

try {
    $response = Invoke-RestMethod -Method Post -Uri $Url `
        -Headers @{ 'X-Argo-Token' = $token; 'Accept' = 'application/json' } `
        -ContentType 'application/json' `
        -Body (@{ version = $Version } | ConvertTo-Json) `
        -TimeoutSec 30

    Write-Log ("OK - PIM przyjal zgloszenie. Magazyn: {0}" -f $response.warehouse_symbol)
    exit 0
}
catch {
    # Kody z PIM sa rozroznialne i warto je zapisac wprost, bo mowia co zrobic:
    # 401 = zly albo zaden token, 403 = polaczenie wylaczone w PIM.
    $status = $null
    if ($_.Exception.Response -ne $null) {
        $status = [int] $_.Exception.Response.StatusCode
    }

    switch ($status) {
        401 { Write-Log "BLAD 401: PIM nie uznal tokenu. Sprawdz, czy token.txt zgadza sie z tym w Ustawieniach." }
        403 { Write-Log "BLAD 403: token dobry, ale polaczenie jest wylaczone w PIM (przelacznik 'Polaczenie aktywne')." }
        default { Write-Log ("BLAD: {0}" -f $_.Exception.Message) }
    }

    exit 1
}
