<#
    Rejestruje zadanie w Harmonogramie: odczyt stanu z Subiekta i wysylka do PIM.

    Jedno zadanie zalatwia oba obowiazki - `wyslij-stan.ps1` najpierw pinguje
    (czyli podtrzymuje zielona kropke i pobiera symbol magazynu z PIM), a potem
    wysyla stan. Osobne zadanie na sam ping jest niepotrzebne.

    Uruchamiac JAKO ADMINISTRATOR na maszynie z Subiektem - zadanie chodzi jako
    SYSTEM, zeby dzialalo takze wtedy, gdy nikt nie jest zalogowany.

    UWAGA: konto SYSTEM musi miec prawo ODCZYTU w SQL. Jesli baza puszcza tylko
    konta domenowe, uruchom skrypt z -RunAsUser <DOMENA\konto> - zadanie zapyta
    o haslo i zapamieta je w Harmonogramie.

    Powtorne uruchomienie nadpisuje zadanie.
#>

param(
    [string] $TaskName = 'ARGO Bridge - stan do PIM',
    [int]    $EveryMinutes = 15,
    [string] $Database = '',
    [string] $Server = 'SERWER\INSERTGT',
    [string] $Url = 'https://pim.bsplate.eu/api/argo-bridge',
    [string] $RunAsUser = ''
)

$ErrorActionPreference = 'Stop'

$script = Join-Path $PSScriptRoot 'wyslij-stan.ps1'

if (-not (Test-Path $script)) {
    throw "Nie znalazlem wyslij-stan.ps1 obok tego skryptu ($script)."
}

if ([string]::IsNullOrWhiteSpace($Database)) {
    throw "Podaj baze: -Database <nazwa>. Nazwe znajdziesz skryptem sprawdz-magazyny.ps1."
}

$tokenFile = Join-Path $PSScriptRoot 'token.txt'
if (-not (Test-Path $tokenFile)) {
    Write-Warning "Brak token.txt obok skryptu. Zadanie zarejestruje sie, ale bedzie zwracac blad, dopoki nie wkleisz tokenu z PIM."
}

$argument = "-NoProfile -ExecutionPolicy Bypass -File `"{0}`" -Server `"{1}`" -Database `"{2}`" -Url `"{3}`"" `
    -f $script, $Server, $Database, $Url

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argument

# Start za minute, potem co N minut bez konca. Brak -RepetitionDuration znaczy
# „w nieskonczonosc" na Windows 10 / Server 2016+.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)

if ([string]::IsNullOrWhiteSpace($RunAsUser)) {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    $principal = New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Password -RunLevel Highest
}

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

if ([string]::IsNullOrWhiteSpace($RunAsUser)) {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
        -Principal $principal -Settings $settings -Force | Out-Null
} else {
    $password = Read-Host -AsSecureString "Haslo do konta $RunAsUser"
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password))

    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
        -Settings $settings -User $RunAsUser -Password $plain -RunLevel Highest -Force | Out-Null
}

Write-Output "Zarejestrowane: '$TaskName' - co $EveryMinutes min."
Write-Output "Baza: $Database, serwer: $Server"
Write-Output "Pierwszy przebieg za minute. Podglad: Get-ScheduledTask '$TaskName' | Get-ScheduledTaskInfo"
Write-Output "Log: $(Join-Path $PSScriptRoot 'stan.log')"
