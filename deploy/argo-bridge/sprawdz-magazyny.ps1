<#
    Rozpoznanie przed pierwszym odczytem stanow — TYLKO ODCZYT.

    Nie zgadujemy nazwy bazy ani ukladu tabel: skrypt sam znajduje bazy Subiekta
    (te, ktore maja `tw__Towar`), sprawdza obecnosc tabel potrzebnych do stanow
    i wypisuje liste magazynow z ich `mag_Id`, symbolem i nazwa.

    Wynik tego skryptu rozstrzyga dwie rzeczy:
      1. z ktorej bazy czytamy,
      2. jaki DOKLADNIE symbol ma magazyn, ktory w PIM nazywamy „M3R"
         (numer w symbolu NIE odpowiada mag_Id — o to sie latwo potknac).

    Uruchomienie:
        powershell -NoProfile -ExecutionPolicy Bypass -File .\sprawdz-magazyny.ps1
#>

param(
    [string] $Server = 'SERWER\INSERTGT',
    [string] $Database = '',
    # Puste = logowanie zintegrowane Windows. Dziala, gdy skrypt chodzi NA maszynie
    # z Subiektem. Z innego komputera, spoza tej samej domeny, SQL odrzuci takie
    # logowanie ("login is from an untrusted domain") - wtedy podaj konto SQL
    # przez -SqlUser, a haslo wpisz do pliku obok skryptu.
    [string] $SqlUser = '',
    [string] $SqlPasswordFile = "$PSScriptRoot\sql-haslo.txt"
)

$ErrorActionPreference = 'Stop'

function Get-AuthPart {
    if ([string]::IsNullOrWhiteSpace($SqlUser)) {
        return 'Integrated Security=SSPI'
    }

    if (-not (Test-Path $SqlPasswordFile)) {
        throw "Podales -SqlUser, ale brak pliku z haslem ($SqlPasswordFile). Utworz go i wklej tam samo haslo."
    }

    $password = (Get-Content -Path $SqlPasswordFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($password)) {
        throw "Plik z haslem jest pusty ($SqlPasswordFile)."
    }

    return "User ID=$SqlUser;Password=$password"
}

function New-ReadOnlyConnection([string] $server, [string] $database) {
    # ApplicationIntent=ReadOnly + READ UNCOMMITTED: nie blokujemy nikomu pracy
    # w Subiekcie i sami niczego nie zapisujemy.
    $conn = New-Object System.Data.SqlClient.SqlConnection
    $conn.ConnectionString = "Server=$server;Database=$database;$(Get-AuthPart);ApplicationIntent=ReadOnly;Connect Timeout=15"
    $conn.Open()
    return $conn
}

function Invoke-Query($conn, [string] $sql, [hashtable] $parameters = @{}) {
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; $sql"
    $cmd.CommandTimeout = 60
    foreach ($key in $parameters.Keys) {
        [void] $cmd.Parameters.AddWithValue($key, $parameters[$key])
    }
    $table = New-Object System.Data.DataTable
    $table.Load($cmd.ExecuteReader())
    return $table
}

Write-Output "Serwer: $Server"

# --- 1. Ktore bazy to bazy Subiekta ---
$master = New-ReadOnlyConnection $Server 'master'
$dbs = Invoke-Query $master "SELECT name FROM sys.databases WHERE database_id > 4 ORDER BY name"

$subiektDbs = @()
foreach ($row in $dbs.Rows) {
    $name = $row.name
    try {
        $probe = Invoke-Query $master "SELECT COUNT(*) AS c FROM [$name].INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'tw__Towar'"
        if ([int] $probe.Rows[0].c -gt 0) { $subiektDbs += $name }
    } catch {
        # brak dostepu do bazy - pomijamy, to nie nasza sprawa
    }
}
$master.Close()

if ($subiektDbs.Count -eq 0) {
    Write-Output "Nie znalazlem zadnej bazy z tabela tw__Towar. Sprawdz uprawnienia konta albo nazwe instancji."
    exit 1
}

Write-Output "Bazy Subiekta: $($subiektDbs -join ', ')"

if ([string]::IsNullOrWhiteSpace($Database)) {
    $Database = $subiektDbs[0]
    Write-Output "Biore pierwsza: $Database (jesli to nie ta, uruchom z -Database <nazwa>)"
}

$conn = New-ReadOnlyConnection $Server $Database

# --- 2. Czy sa tabele potrzebne do stanow ---
$tables = Invoke-Query $conn "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME IN ('tw__Towar','tw_Stan','sl_Magazyn')"
$found = @($tables.Rows | ForEach-Object { $_.TABLE_NAME })
Write-Output "Tabele stanow: $($found -join ', ')"

foreach ($needed in @('tw__Towar', 'tw_Stan', 'sl_Magazyn')) {
    if ($found -notcontains $needed) {
        Write-Warning "BRAK tabeli $needed - uklad bazy jest inny niz zakladamy. Wyslij ten wynik, poprawimy zapytanie."
    }
}

# --- 3. Magazyny ---
if ($found -contains 'sl_Magazyn') {
    Write-Output ""
    Write-Output "MAGAZYNY (symbol jest tym, co wpisujemy w PIM):"
    $mag = Invoke-Query $conn "SELECT mag_Id, mag_Symbol, mag_Nazwa FROM sl_Magazyn ORDER BY mag_Id"
    $mag | Format-Table -AutoSize | Out-String | Write-Output
}

# --- 4. Ile pozycji ma kazdy magazyn (zeby bylo widac, ktory zyje) ---
if (($found -contains 'tw_Stan') -and ($found -contains 'sl_Magazyn')) {
    Write-Output "POZYCJE NA STANIE (ilosc <> 0):"
    $counts = Invoke-Query $conn @"
SELECT m.mag_Symbol, m.mag_Nazwa, COUNT(*) AS Pozycji, SUM(s.st_Stan) AS SumaSztuk
FROM tw_Stan s
INNER JOIN sl_Magazyn m ON m.mag_Id = s.st_MagId
WHERE s.st_Stan <> 0
GROUP BY m.mag_Symbol, m.mag_Nazwa
ORDER BY Pozycji DESC
"@
    $counts | Format-Table -AutoSize | Out-String | Write-Output
}

$conn.Close()
Write-Output "Gotowe. Nic nie zostalo zapisane - to byl wylacznie odczyt."
