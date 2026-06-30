# Powiadomienia WhatsApp + Raport sprzedaży — 2026‑06‑30

Dzień pracy: dashboardowy kafelek „Do zapłaty", powiadomienia WhatsApp o fakturach KSeF do zapłaty oraz dzienny raport sprzedaży z Argo Connect. Wszystko spięte w jednym miejscu: **Argo Connect → Integracja chatboot**.

---

## 1. Kafelek „Do zapłaty" (dashboard)
- Pierwszy kafelek na ekranie startowym (`Home.vue`), gradient jak liczniki.
- Domyślnie **Dziś**, przełącznik **Tydzień / Miesiąc** (client‑side; dane 3 okresów lecą z serwera).
- Kwoty **osobno PARETO i BSP**, po walutach. Tylko `status=unpaid` i FV z **ustawionym terminem** (`due_date`≠null); bez terminu = gotówka → pomijane. „Dziś" = ściśle dzisiejsza data.
- Logika: `App\Services\Ksef\DuePaymentsService` (`forDashboard/totals/sumInRange`) → `HomeController::dashboard` jako prop `duePayments`.

## 2. Powiadomienia KSeF „Do zapłaty" (WhatsApp)
- Kanał: **CallMeBot WhatsApp** (`https://api.callmebot.com/whatsapp.php`) — zwykły HTTPS, **zero instalacji na serwerze** (shared hosting OVH nie uniósłby signal‑cli/Javy).
- CallMeBot **nie przyjmuje polskich znaków/emoji** → `SignalSender::toAscii()` transliteruje (ł→l, ś→s…) przed wysyłką, zostawia nowe linie.
- Cron `ksef:signal-due` o godzinie z ustawień (domyślnie **07:00**), anty‑duplikat przez `last_sent_date`.
- Treść z **edytowalnego szablonu**, placeholdery: `{pareto}`, `{bsp}`, `{data}`, **`{przeterminowane}`**, **`{przeterminowane_razem}`**.
- **Lista opóźnionych płatności**: niezapłacone FV z minionym terminem, **tylko od `OVERDUE_SINCE` = 2026‑06‑01** (stare zaległości pominięte). Format `- {dni} dni / {kontrahent} / {kwota}`, najstarsze pierwsze, `Razem` per waluta, limit 40 pozycji. Po oznaczeniu „opłacone" pozycja znika. Nazwa kontrahenta z KSeF bywa wielolinijkowa (z adresem) → zwijana do 1 linii i przycinana do 45 znaków (`cleanContractor`).

## 3. Raport sprzedaży (Argo Connect → Integracja chatboot)
- Dzienny raport z tabeli `orders` (BaseLinker), wysyłany na WhatsApp.
- **Za dzień poprzedni** (`now()->subDay()`) — cron o 00:01 zamyka miniony dzień (raport „dziś" o północy = zero).
- Per kraj (`delivery_country_code`, ISO; brak → „??"), kwota w walucie zamówienia **+ przeliczenie na PLN** (kurs EBC, `CurrencyConverter::toPln` — dodane).
- „W tym tygodniu/miesiącu" = **bieżące** względem `now()` (tydzień pon–niedz, miesiąc kalendarzowy), niezależnie od dnia raportu.
- Placeholdery: `{sprzedaz_per_kraj}`, `{razem_dzis}`, `{obrot_tydzien}`, `{obrot_miesiac}`, `{data}`.
- „Sprzedaż" = wszystkie zamówienia po `date_add` (bez filtra statusu).
- Logika: `App\Services\Connect\SalesReportService`; cron `connect:sales-report`; model `App\Models\Connect\ChatbotReport` (tabela `chatbot_reports`).

## 4. Konsolidacja UI
- Nowa pozycja menu **Argo Connect → Integracja chatboot** z dwoma tabami: **Raport sprzedaży** + **Powiadomienia KSeF**.
- Ustawienia powiadomień KSeF **przeniesione** ze strony KSeF→Ustawienia do tabu (zapis/test dalej przez trasy `ksef.signal.*`). Na stronie KSeF→Ustawienia została tylko karta Kategorie.
- Numer/apikey współdzielone: raport sprzedaży z pustymi polami numer/apikey używa konfiguracji KSeF.
- Tabela faktur KSeF: naprzemienne paski (białe / `bg-gray-50`, hover `bg-gray-100`).

---

## Transport (wspólny)
`App\Services\Ksef\SignalSender::sendTo($msg, $phone, $apiKey)` — HTTPS GET do CallMeBot, błąd gdy odpowiedź nie‑2xx lub body zawiera „error". Konfiguracja w `ksef_signal_settings` (singleton); raporty Connect mogą mieć własny numer/apikey w `chatbot_reports` lub dziedziczyć z KSeF.

## Baza (nowe tabele)
- `ksef_signal_settings` — konfiguracja powiadomień KSeF (enabled, phone, api_key, template, send_time, last_sent_date).
- `chatbot_reports` — konfiguracja raportów chatbota (report_key='sales', …).

## Wdrożenie (prod pim.bsplate.eu — shared hosting OVH)
- SQL przez phpMyAdmin: `ksef_signal_settings` + `chatbot_reports` (CREATE TABLE IF NOT EXISTS + rejestracja migracji).
- Kod + `public/build` wgrywane ręcznie (prod bez composer/npm). Po wgraniu: `php83 artisan optimize:clear` + Ctrl+Shift+R.
- Cron `schedule:run` już działał (KSeF) — nowe komendy wpinają się same.
- Konfiguracja CallMeBot: na telefonie opt‑in u bota → apikey; wpisać numer+apikey w panelu, włączyć, test.

## Otwarte / do obserwacji
- **Limit darmowego CallMeBota** („Too many requests") przy intensywnym testowaniu — przy 2 wysyłkach/dobę nie problem; ewentualnie plan płatny lub **Telegram** (bez limitów).
- Korekty (ujemne kwoty) w liście opóźnionych — obecnie wchodzą i netują się w `Razem` (do ew. wykluczenia).
- Limit 40 pozycji listy opóźnionych (potem „…i jeszcze N FV").

---

## Git — jak szybko znaleźć tę pracę
- **Tag:** `chatbot-ksef-2026-06-30` → commit `58a5d31` (repo github.com/bsplate-eu/pim).
- To podsumowanie (100% po ludzku):
  `git show chatbot-ksef-2026-06-30:docs/2026-06-30-powiadomienia-whatsapp-raport-sprzedazy.md`
- Najnowsze zmiany: `git show chatbot-ksef-2026-06-30 --stat` (lista) · `git show chatbot-ksef-2026-06-30` (pełny diff).
- Szukanie po treści/nazwie: `git log -S "chatbot_reports"` · `git grep "OVERDUE_SINCE"` · `git log --oneline -- app/Services/Ksef/DuePaymentsService.php`.
- GitHub: [commit 58a5d31](https://github.com/bsplate-eu/pim/commit/58a5d31) · [ten plik na tagu](https://github.com/bsplate-eu/pim/blob/chatbot-ksef-2026-06-30/docs/2026-06-30-powiadomienia-whatsapp-raport-sprzedazy.md).

> UWAGA: większość NOWYCH plików sesji wpadła do dużego „Initial commit" `f212a95` (cały projekt), a `58a5d31`/tag = najnowsze poprawki + WIP eBay. Nie ma jednego czystego diffa „cała sesja" — **to podsumowanie spina całość** (lista plików w sekcjach wyżej).

