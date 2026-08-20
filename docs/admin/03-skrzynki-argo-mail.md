# 03 — Skrzynki Argo Mail per użytkownik

## Problem

`crafter.module.mail` jest zero‑jedynkowe: kto miał wstęp do Argo Mail, widział **wszystkie** wpięte skrzynki (na produkcji jest ich 7). Czytał cudzą korespondencję, mógł wysłać maila z dowolnego adresu firmowego i skasować skrzynkę. Nie było nic pośrodku.

## Zasada

| rola ma `crafter.mail-account.all` | co widzi |
|---|---|
| **tak** | wszystkie skrzynki |
| **nie** | wyłącznie skrzynki przypisane jej imiennie |

Skrzynki przypisuje się **osobie, nie roli** — dwie osoby na tym samym stanowisku zwykle obsługują różne adresy. Wiązanie skrzynek z rolą wymuszałoby osobną rolę na każdego pracownika.

Migracja `2026_08_19_110000_create_mail_account_admin_user.php` zakłada tabelę `mail_account_admin_user` (para unikalna, kasowanie kaskadowe) oraz uprawnienie `crafter.mail-account.all`, które **nadaje wszystkim rolom poza „Guest"** — nikomu nie znika poczta w dniu wdrożenia.

## Gdzie się ustawia

**Admin → Użytkownicy i uprawnienia → Users → edycja → karta „Skrzynki Argo Mail"**

Lista wszystkich skrzynek z checkboxami (nie rozwijana — przy siedmiu pozycjach dropdown był nieczytelny). Nad listą licznik „Zaznaczone: X z Y" i skróty „Zaznacz wszystkie" / „Wyczyść". Wyłączone skrzynki mają plakietkę „nieaktywna".

Podpowiedź pod listą mówi, co realnie się stanie:

- pusto → „użytkownik nie zobaczy w Argo Mail żadnej skrzynki",
- rola ma `mail-account.all` → pomarańczowa ramka: zaznaczenia zapiszą się, ale zaczną działać dopiero po odebraniu roli tego uprawnienia (z linkiem do macierzy).

Ostrzeżenie reaguje na rolę **wybraną w formularzu**, jeszcze przed zapisem.

## Jak jest egzekwowane

### Zawężanie list

`App\Models\Mail\Account::scopeVisibleTo(?AdminUser $user)` — brak użytkownika (kolejki, CLI) traktowany jak pełny dostęp, bo tam nie ma kogo ograniczać. Użyte w:

| miejsce | co zawęża |
|---|---|
| `MailController::index` | skrzynki, listę maili, liczniki nieprzeczytanych, taby |
| `AccountController::index` | konfigurator skrzynek |
| `AiToolsMailController::administrator` | listę skrzynek dla administratora AI |
| `MobileController::mail` | skrzynki **oraz** maile — widok mobilny czytał wcześniej ze wszystkich skrzynek zupełnie bez filtra |

Pomocniczo `Account::visibleIdsFor()` i `Account::isVisibleTo()`.

### Bramka na pojedynczych żądaniach

`app/Http/Middleware/EnsureMailAccountAccess.php`, alias `mail.account`, wpięty na grupie tras `argo-mail` w `routes/crafter.php`.

Moduł ma kilkanaście endpointów operujących na jednej wiadomości (podgląd, wątek, załącznik, obrazek inline, przenoszenie do katalogu, spam, kolor, operacje zbiorcze). Zamiast wklejać ten sam warunek do każdej metody, middleware sprawdza parametry żądania:

- `{account}` → id skrzynki,
- `{message}` → `account_id` wiadomości,
- `account_id` w body → wysyłka i operacje zbiorcze.

Odczyt parametru jest odporny na kolejność middleware: jeśli `SubstituteBindings` już zadziałał, dostajemy model; jeśli nie — surowe id i dociągamy je sami.

### Wysyłka

`MailController::send` dodatkowo szuka konta przez `visibleTo()->findOrFail()` — nie da się wysłać „z cudzej" skrzynki nawet po podmianie `account_id` w żądaniu. Middleware łapie to samo wcześniej; to obrona w drugiej linii.

## Gotcha: pusta tablica ginie w FormData

Formularz użytkownika niesie avatar, więc Inertia serializuje go do `FormData`. **Pusta tablica nie zostawia tam żadnego klucza** — `mail_account_ids` po prostu znika z żądania. Bez zabezpieczenia nie dałoby się odebrać użytkownikowi *wszystkich* skrzynek: serwer nie odróżniłby „wyczyść" od „nie dotykaj".

Dlatego formularz wysyła jawny znacznik `sync_mail_accounts: true`, a kontroler robi:

```php
if ($request->boolean('sync_mail_accounts')) {
    $adminUser->mailAccounts()->sync($request->input('mail_account_ids', []));
}
```

Znacznik jest ważny też w drugą stronę: `update()` obsługuje również akcje inline z listy użytkowników (aktywacja, dezaktywacja). Te nie wysyłają znacznika, więc **nie mogą wyczyścić przypisań** tylko dlatego, że nie znały tego pola.

Sprawdzone oba przypadki:

```
ze znacznikiem, bez mail_account_ids   → przypisania wyczyszczone
bez znacznika                          → przypisania nietknięte
```

## Czego przypisanie NIE ogranicza

Kasowanie skrzynki, opróżnianie kosza i spamu dalej wchodzą w pakiecie `crafter.module.mail`. Przypisanie decyduje **które** skrzynki widać, nie **co** można z nimi zrobić. Kto ma moduł, może usunąć skrzynkę, do której ma dostęp.

## Do dokończenia

`MailController::emptyTrash()` i `emptySpam()` startują od `Account::query()->pluck('id')` bez `visibleTo()`. To metody z **trwającego portu Argo Mail**, których nie było w repo w chwili pisania tej warstwy — zawężenie trzeba dołożyć, gdy tamta praca zostanie zacommitowana. Wzór jak w pozostałych miejscach:

```php
$accountIds = Account::query()->visibleTo(auth()->user())->pluck('id');
```
