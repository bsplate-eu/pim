<#
    Subiekt GT -> PIM: wysylka stanu wskazanego magazynu.

    Czyta WYLACZNIE (ApplicationIntent=ReadOnly + READ UNCOMMITTED) i nie dotyka
    Sfery - zasada projektu zostaje nietknieta: do ERP nie zapisujemy niczym
    innym niz Sfera, a tu w ogole nie zapisujemy.

    Paczka jest MIGAWKA calego magazynu, nie roznica: czego w niej nie ma, tego
    PIM uzna za zero. Dlatego pusty wynik zapytania NIE jest wysylany - pusty
    wynik to zwykle blad (zle konto, zla baza, zly symbol), a nie pusty magazyn.

    Symbol magazynu bierzemy z PIM (odpowiedz na ping), zeby nie trzymac tej
    samej wartosci w dwoch miejscach. Mozna nadpisac parametrem -Warehouse.

    Uruchomienie:
        powershell -NoProfile -ExecutionPolicy Bypass -File .\wyslij-stan.ps1
#>

param(
    [string] $Server = 'SERWER\INSERTGT',
    [string] $Database = '',
    # Puste = logowanie zintegrowane Windows (skrypt chodzi NA maszynie z Subiektem).
    # Z innego komputera spoza tej domeny podaj konto SQL, a haslo wklej do pliku.
    [string] $SqlUser = '',
    [string] $SqlPasswordFile = "$PSScriptRoot\sql-haslo.txt",
    [string] $Warehouse = '',
    [string] $Url = 'https://pim.bsplate.eu/api/argo-bridge',
    [string] $TokenFile = "$PSScriptRoot\token.txt",
    [string] $Version = '0.1.0-pilot',
    [string] $LogFile = "$PSScriptRoot\stan.log"
)

$ErrorActionPreference = 'Stop'

function Write-Log([string] $Message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Output $line
    Add-Content -Path $LogFile -Value $line -Encoding utf8
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not (Test-Path $TokenFile)) {
    Write-Log "BLAD: brak pliku z tokenem ($TokenFile)."
    exit 1
}
$token = (Get-Content -Path $TokenFile -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($token)) {
    Write-Log "BLAD: plik z tokenem jest pusty."
    exit 1
}
$headers = @{ 'X-Argo-Token' = $token; 'Accept' = 'application/json' }

# --- 1. Ping: potwierdza token i przynosi symbol magazynu ustawiony w PIM ---
try {
    $ping = Invoke-RestMethod -Method Post -Uri "$Url/ping" -Headers $headers `
        -ContentType 'application/json' -Body (@{ version = $Version } | ConvertTo-Json) -TimeoutSec 30
}
catch {
    $status = if ($_.Exception.Response) { [int] $_.Exception.Response.StatusCode } else { 0 }
    switch ($status) {
        401 { Write-Log "BLAD 401: PIM nie uznal tokenu." }
        403 { Write-Log "BLAD 403: polaczenie wylaczone w PIM." }
        default { Write-Log ("BLAD polaczenia z PIM: {0}" -f $_.Exception.Message) }
    }
    exit 1
}

if ([string]::IsNullOrWhiteSpace($Warehouse)) {
    $Warehouse = $ping.warehouse_symbol
}
if ([string]::IsNullOrWhiteSpace($Warehouse)) {
    Write-Log "BLAD: w PIM nie ustawiono symbolu magazynu (Magazyn -> Ustawienia -> Argo Bridge)."
    exit 1
}
Write-Log "Magazyn do odczytu: $Warehouse"

# --- 2. Odczyt stanu z Subiekta ---
$auth = 'Integrated Security=SSPI'
if (-not [string]::IsNullOrWhiteSpace($SqlUser)) {
    if (-not (Test-Path $SqlPasswordFile)) {
        Write-Log "BLAD: podano -SqlUser, ale brak pliku z haslem ($SqlPasswordFile)."
        exit 1
    }
    $sqlPassword = (Get-Content -Path $SqlPasswordFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($sqlPassword)) {
        Write-Log "BLAD: plik z haslem SQL jest pusty."
        exit 1
    }
    $auth = "User ID=$SqlUser;Password=$sqlPassword"
}

$conn = New-Object System.Data.SqlClient.SqlConnection
$conn.ConnectionString = "Server=$Server;Database=$Database;$auth;ApplicationIntent=ReadOnly;Connect Timeout=15"

if ([string]::IsNullOrWhiteSpace($Database)) {
    Write-Log "BLAD: podaj baze parametrem -Database (nazwe znajdziesz skryptem sprawdz-magazyny.ps1)."
    exit 1
}

try {
    $conn.Open()

    $cmd = $conn.CreateCommand()
    $cmd.CommandTimeout = 120
    $cmd.CommandText = @"
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
SELECT t.tw_Symbol AS code, t.tw_Nazwa AS name, SUM(s.st_Stan) AS qty
FROM tw_Stan s
INNER JOIN tw__Towar t ON t.tw_Id = s.st_TowId
INNER JOIN sl_Magazyn m ON m.mag_Id = s.st_MagId
WHERE m.mag_Symbol = @symbol
GROUP BY t.tw_Symbol, t.tw_Nazwa
HAVING SUM(s.st_Stan) <> 0
ORDER BY t.tw_Symbol
"@
    [void] $cmd.Parameters.AddWithValue('@symbol', $Warehouse)

    $table = New-Object System.Data.DataTable
    $table.Load($cmd.ExecuteReader())
}
catch {
    Write-Log ("BLAD odczytu z SQL: {0}" -f $_.Exception.Message)
    Write-Log "Jesli to blad o nieznanej tabeli - uruchom sprawdz-magazyny.ps1 i wyslij wynik, poprawimy zapytanie."
    exit 1
}
finally {
    if ($conn.State -eq 'Open') { $conn.Close() }
}

$items = @()
foreach ($row in $table.Rows) {
    $items += @{
        code     = [string] $row.code
        name     = [string] $row.name
        quantity = [double] $row.qty
    }
}

Write-Log ("Odczytano pozycji: {0}" -f $items.Count)

# Bramka po naszej stronie, zanim PIM odrzuci to samo: pusty wynik nie jedzie.
if ($items.Count -eq 0) {
    Write-Log "PRZERWANE: zapytanie nie zwrocilo ani jednej pozycji. Sprawdz symbol magazynu i uprawnienia - pusty wynik to zwykle blad, nie pusty magazyn."
    exit 1
}

# --- 3. Wysylka ---
try {
    $body = @{ warehouse = $Warehouse; version = $Version; items = $items } | ConvertTo-Json -Depth 4 -Compress
    $result = Invoke-RestMethod -Method Post -Uri "$Url/stock" -Headers $headers `
        -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 120

    Write-Log ("OK - PIM przyjal {0} pozycji (zapisane: {1})." -f $result.received, $result.stored)
    exit 0
}
catch {
    $status = if ($_.Exception.Response) { [int] $_.Exception.Response.StatusCode } else { 0 }
    $detail = ''
    try {
        $reader = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
        $detail = $reader.ReadToEnd()
    } catch { }

    # Bez operatora ?: - na maszynie stoi Windows PowerShell 5.1, ktory go nie zna.
    $message = $_.Exception.Message
    if ($detail -ne '') { $message = $detail }

    Write-Log ("BLAD wysylki ({0}): {1}" -f $status, $message)
    exit 1
}
