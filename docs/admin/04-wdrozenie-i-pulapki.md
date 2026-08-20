# 04 — Wdrożenie i pułapki

## Log wdrożeń (2026‑08‑19 / 20)

| commit | zawartość |
|---|---|
| `63b440e` | migracja 9 uprawnień `crafter.module.*` — naprawa zniknniętych zakładek |
| `e81a63a` | wymóg logowania na trzech grupach tras |
| `ed5e74f` | grupa menu Admin, `EnsureModuleAccess`, CRUD ról, etykiety PL, bezpiecznik, trzy załatane dziury |
| `176fd82` | przypisywanie skrzynek Argo Mail |
| `d15dc79` | skrzynki jako lista z checkboxami zamiast rozwijanej listy |

Wdrożenie idzie automatem: `git push` → cron co 5 min → `wdroz-pim.sh` (pull + lint + `migrate` + cache). Realny czas od pusha do produkcji: 2–4 min.

---

## Incydent: częściowy deploy warstwy frontowej

**Objaw.** Po wdrożeniu z menu produkcji zniknęły Argo HQ, Connect, Scope, Task, Mail i Tłumaczenia. Zostały Dashboard, Argo PIM, AI Tools i sekcje administracyjne.

**Przyczyna.** Commit `f0f2136` („Sidebar Argo Connect: trzy podgrupy zamiast płaskiej listy") powstał w **równoległej sesji** pracującej w tym samym katalogu roboczym. Zabrał ze sobą `Sidebar.vue` z 22 bramkami `v-can="crafter.module.*"` oraz przebudowany `public/build` — ale **bez backendu, który te uprawnienia zakłada**. Migracja, config i middleware zostały niezacommitowane na dysku.

Skutek: uprawnienia `crafter.module.*` nie istniały w bazie produkcyjnej, `can()` zwracał `false`, a dyrektywa `v-can` usuwała pozycje z menu.

**Ważne:** nic nie było zablokowane. Middleware egzekwujący też nie pojechał, więc strony dalej otwierały się z adresu — zniknęły wyłącznie pozycje w menu.

**Naprawa.** `63b440e` — sama migracja, 88 linii, jeden plik, zero zależności. Nadaje uprawnienia wszystkim rolom poza „Guest", czyli odtwarza stan sprzed zmiany.

---

## Dziura: trzy grupy tras bez logowania

Przy okazji audytu wyszło, że trzy grupy w `routes/crafter.php` miały tylko `crafter.base` — bez `auth` i bez `crafter.verified`.

Zmierzone na produkcji **przed** poprawką:

```
GET /admin/translation-phrases   → 200 bez logowania
GET /admin/translation-logs      → 200 bez logowania
GET /admin/translation-settings  → 200 bez logowania
GET /admin/categories            → 403
GET /admin/ai-tools              → 403
```

Cała matryca tłumaczeń — nazwy produktów, logi, ustawienia — była czytelna dla każdego, kto znał adres. Kontrolery tego modułu nie mają klas Request z `Gate`, więc nic tego nie zatrzymywało; `categories` i `ai-tools` oddawały 403 wyłącznie dlatego, że **ich** Requesty sprawdzają uprawnienia.

Endpointy POST (`auto-translate-bulk`, `approve-bulk`, `translate-missing`) wymagały tokenu CSRF — ale ten był do wyjęcia z tej samej otwartej strony, bo `HandleInertiaRequests` wysyła `csrf_token` w propsach.

Naprawione w `e81a63a`, po wdrożeniu wszystkie trzy oddają 302 na logowanie.

---

## Pułapka: równoległe sesje dzielą katalog roboczy

`D:\laragon\www\PIM` bywa jednocześnie edytowany przez więcej niż jedną sesję. Konsekwencje:

**`public/build` to mina.** Vite kompiluje **cały** katalog roboczy, więc build niesie też cudze niedokończone Vue. Wysłanie samego JS bez odpowiadającego mu PHP daje dokładnie tę samą awarię co wyżej, tylko w cudzym module.

**Przed commitem sprawdź, czy zmiany są wyłącznie Twoje:**

```bash
git diff --numstat <plik>
```

Podejrzanie duży diff w pliku, którego ledwo dotknąłeś, oznacza, że ktoś w nim siedzi. W tej warstwie tak było z `MailController.php` (+376/−26, z czego moje było 8 linii) i `routes/crafter.php`.

### Chirurgiczny commit z dzielonego pliku

Sposób użyty trzykrotnie, sprawdzony:

```bash
cp <plik> <scratchpad>/kopia          # 1. odłóż wersję roboczą
git checkout -- <plik>                # 2. wróć do wersji z repo
# 3. nanieś TYLKO swoje zmiany
git add <plik> && git commit          # 4. commit
cp <scratchpad>/kopia <plik>          # 5. przywróć wersję roboczą
```

Po kroku 5 git widzi jako niezacommitowane wyłącznie zmiany drugiej sesji. To samo dotyczy plików `.vue` przed `npm run build` — odłóż cudze, zbuduj, zacommituj, przywróć. Wtedy build zawiera tylko Twoje zmiany skompilowane na stanie z repo.

Po każdym takim manewrze **zweryfikuj, że cudza praca wróciła** (`git diff --numstat`, obecność ich tras/metod).

---

## Pułapka: panel to SPA — samo klikanie nie odświeży kodu

Po wdrożeniu zmian we froncie użytkownik z otwartą od dawna zakładką **dalej wykonuje bundle sprzed deployu**. Inertia robi przejścia po XHR i nie pobiera JavaScriptu ponownie — klikanie po menu, wchodzenie w ekrany, nic z tego nie pomoże.

Potrzebne jest **prawdziwe przeładowanie strony (F5)**. Samo F5 wystarczy, bo HTML leci z `cache-control: no-cache, private`; Ctrl+Shift+R ani czyszczenie cache nie są potrzebne.

Jak sprawdzić, że serwer podaje dobrą wersję (bez logowania):

```bash
# 1. jaki chunk wejściowy jest w HTML
curl -s https://pim.bsplate.eu/admin/login | grep -o 'assets/index-[a-z0-9]*\.js'

# 2. co mówi manifest
php -r '$m=json_decode(file_get_contents("public/build/manifest.json"),true);
        echo $m["resources/js/crafter/index.ts"]["file"];'

# 3. czy docelowy chunk ma nową treść
curl -s https://pim.bsplate.eu/build/assets/<chunk>.js | grep -c '<nowy napis>'
```

Zgodność 1 = 2 i trafienie w 3 oznaczają, że problem jest po stronie przeglądarki, nie serwera.

---

## Diagnostyka produkcji

Shell `admin` **nie ma dostępu do MySQL** (bazy tylko przez panel DirectAdmin / phpMyAdmin), ale ma PHP 8.3 i `artisan`, więc stan bazy czyta się tinkerem:

```bash
ssh -i /d/laragon/www/SSH/bsp-auto admin@5.196.81.23 'bash -s' <<'R'
cd ~/domains/pim.bsplate.eu/PIM
/usr/local/php83/bin/php artisan tinker --execute='
foreach (App\Models\AdminUser::all() as $u) {
    echo $u->email . " -> " . implode(",", App\Models\Mail\Account::visibleIdsFor($u)) . "\n";
}
'
R
```

Heredoc z `<<'R'` (w apostrofach) i `--execute='...'` w apostrofach ratują przed piekłem escapowania backslashy w namespace'ach — bez tego `tinker` wywala `T_NS_SEPARATOR`.

Sprawdzenie, czy deploy wszedł:

```bash
ssh ... "cd ~/domains/pim.bsplate.eu/PIM && git log --oneline -1"
/usr/local/php83/bin/php artisan migrate:status | tail -3
```

## Znane błędy w logu, które NIE pochodzą z tej warstwy

Przy weryfikacji wdrożeń w `storage/logs/laravel-*.log` widać:

- **BaseLinker** — `Odwołanie do pliku z nieprawidłową akcją: ProductsQuantity` (`BaselinkerController.php:51`) plus ostrzeżenie o przestarzałym uwierzytelnianiu md5. Ok. 50 wpisów dziennie, tak samo 17 i 18 sierpnia. BaseLinker woła nasze API z akcją, której kontroler nie obsługuje. Osobny temat.
- **CallMeBot WhatsApp** — `HTTP 208` przy `ksef:signal-due` i `connect:sales-report`, co godzinę o pełnej. Powiadomienia nie dochodzą. Osobny temat.
- **`strlen(): Passing null`** — kilka tysięcy linii z crona o 03:00, deprecation.
