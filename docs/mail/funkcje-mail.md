# Argo Mail — funkcje (UI)

> Konkretna lista wszystkiego, co użytkownik może zrobić w module Argo Mail (panel + ustawienia).
> Aktualizacja: 2026-06-03. Spec techniczny: `argo-mail.md`. Changelog: `../argo-pim.md`.
> 🖥️ Te same funkcje działają w **programie Windows** (Argo Mail Desktop) — w nim lewa kolumna PIM jest ukryta (sama poczta), reszta UI identyczna. Spec: `argo-mail-desktop.md`.

---

## 1. Skrzynki (Argo Mail → Skrzynki)
- **Dodaj skrzynkę** — z presetem Gmaila (jeden klik wypełnia IMAP/SMTP) albo ręcznie.
- **Testuj połączenie** — sprawdza osobno odbiór (IMAP) i wysyłkę (SMTP) bez zapisu.
- **Edytuj skrzynkę** — zmiana danych + **stopka (podpis)** doklejana do wysyłanych maili.
- **Usuń skrzynkę.**
- **Synchronizuj teraz** — ręczne pobranie maili dla skrzynki.
- **Statystyki** — liczba maili, nieprzeczytane, status i czas ostatniej synchronizacji.

## 2. Układ panelu (Argo Mail → Skrzynka)
- **3 kolumny:** Katalogi | Lista maili | Podgląd.
- **Full size** — podgląd na cały ekran (chowa filtry/listę/katalogi); **Pomniejsz** wraca do 3 kolumn.
- **Rozszerz** — odwrotność „Full size": chowa podgląd, poszerza listę (zostają Katalogi + szeroka Lista). Klik w mail wraca do 3 kolumn.
- **Auto-odświeżanie co 60 s** — nowe maile (pobrane w tle) pojawiają się same.

## 3. Filtry (góra panelu)
- **Konta** — taby: „Wszystkie" + każda skrzynka osobno (z licznikiem nieprzeczytanych).
- **Osoby** — taby: „Wszyscy" + każda osoba obsługująca pocztę.
- **Kategorie** — taby: „Wszystkie" + każda kategoria.
- **Szukajka** — po temacie lub nadawcy; **„X"** w polu czyści wyszukiwanie.
- **Tylko nieprzeczytane** — przełącznik.
- **Ukryj maile w folderach** — pokazuje tylko maile bez przypisanego katalogu (nieposortowane). Wyklucza się z wejściem w folder: wejście w folder ją wyłącza, a jej włączenie wraca do „Wszystkie".
- **Filtr po kolorach** — kwadraciki (tylko dla kolorów w użyciu); klik = pokaż tylko maile w tym kolorze, „✕" = zdejmij filtr.
- **Sortowanie** — dropdown w pasku: Najnowsze / Najstarsze / Temat (A–Z) / Nadawca (kontrahent, A–Z).

## 4. Katalogi (kolumna lewa)
- **Wszystkie** — widok bez filtra katalogu.
- **Drzewo katalogów** — własna struktura folderów i podfolderów; klik filtruje listę.
- **Kosz** — przeniesione maile.
- **Spam** — maile od zablokowanych nadawców.
- **Liczniki maili** — przy folderze: **total**, a gdy są nieprzeczytane `total / nieprzeczytane` (np. `25 / 5`); nazwa folderu pogrubiona, gdy są nieprzeczytane. Liczone z podfolderami (folder nadrzędny sumuje całą zawartość). Kosz i Spam tak samo.
- **Drag & drop maila na katalog** — mail tam trafia, a **wszystkie maile z tego samego adresu** (np. `payments-noreply@google.com`) przechodzą do katalogu; nadawca dostaje regułę „na stałe" (nowe maile z tego adresu też tam trafią). Działa na **konkretny adres**, nie na całą domenę — regułę domenową (@domena) ustawisz w „Filtry".
- **Drag & drop na „Wszystkie"** — cofnięcie: zdejmuje katalog ze wszystkich maili tego adresu i kasuje regułę (chyba że trzyma przypisaną osobę).
- **⚙ (koło zębate)** — przejście do zarządzania katalogami (Ustawienia).

## 5. Lista maili (kolumna środkowa)
- **Wątki (jak Outlook)** — maile od tej samej osoby w tym samym temacie (też Twoje odpowiedzi „Re: …") zwinięte w jeden wiersz z licznikiem `(N)` i **strzałką ▸**.
- **Strzałka ▸ przy wątku** — klik rozwija wątek **w liście** (w dół, wcięte wiersze, chronologicznie).
- **Klik w mail** (nagłówek wątku lub wcięty wiersz) — otwiera **pojedynczy** mail w podglądzie; oznacza go jako przeczytany.
- **Dwuklik w mail** — otwiera go od razu na **Full size** (lista schowana).
- **Zaznaczanie** — checkbox przy każdym mailu + nagłówek listy **„Zaznacz / odznacz wszystkie"** (cała bieżąca strona). Dodatkowo Ctrl+klik (pojedynczo) i Shift+klik (zakres). Zmiana widoku/sortu czyści zaznaczenie.
- **Przeciąganie (drag)** — chwyć mail i upuść na katalog (patrz pkt 4).
- **Nieprzeczytane** — pogrubione i nieco większe; niebieska kropka.
- **Kolor** — pokoloruje cały wiersz (patrz pkt 10).
- **Chipy** pod tematem — osoba / kategoria / katalog.
- **Spinacz** — oznacza, że mail ma załączniki.
- **Prawy klik** — menu kontekstowe (pkt 7).
- **Paginacja** — Nowsze / Starsze.

## 6. Zaznaczanie i akcje masowe (po zaznaczeniu kilku maili)
- **Do kosza.**
- **Przeczytane / Nieprzeczytane.**
- **Spam** — przenosi nadawców zaznaczonych maili do spamu (wszystkie ich maile znikają ze skrzynki).
- **Katalog** — przenieś zaznaczone (dropdown).
- **Kategoria** — przypisz (dropdown).
- **Osoba** — przypisz (dropdown).
- **Kolory** — kolorowe próbki + „bez koloru".
- **Wyczyść** — odznacz wszystko.

## 7. Menu prawego kliku (na mailu)
- **Działania:** Oznacz jako nieodczytany / Oznacz jako przeczytany.
- **Przypisz osobę** — z opcją **„na stałe"** (tworzy regułę dla nadawcy).
- **Kategoria** — przypisz / usuń.
- **Katalog** — przenieś do wybranego.
- **Oznacz jako SPAM (nadawca)** — nadawca do spamu (jego maile znikają z listy); w widoku Spam zamiast tego **Nie spam (przywróć nadawcę)**.
- **Do kosza.**

## 8. Podgląd maila (kolumna prawa)
- **Odpowiedz** / **Przekaż** (duże przyciski u góry i na dole).
- **Full size** — pełny ekran.
- **Filtr (lejek)** — utwórz regułę z tego maila (popup: nadawca + „tytuł zawiera" + folder docelowy — jak zakładka „Filtry").
- **SPAM** / **Nie spam** — szybki przycisk.
- **Do kosza** / **Przywróć** (w koszu).
- **Katalog** — przenieś mail (dropdown).
- **Adres nadawcy** — czarny, z **ikonką kopiowania** (klik = kopiuje email do schowka).
- **Treść** — w bezpiecznej ramce; **linki otwierają się w nowej karcie**, skrypty zablokowane. Daty pokazują rok (np. `15.05.2026`).
- **Załączniki** — lista z rozmiarem, klik = pobierz.
- **Chipy** — osoba / kategoria / katalog.

## 9. Pisanie / odpowiadanie (kompozytor)
- **Nowa wiadomość** — przycisk u góry panelu.
- **Odpowiedz / Przekaż** — z otwartego maila (z cytatem oryginału).
- **Od** — wybór skrzynki nadawcy.
- **Do / DW / Temat.**
- **Edytor HTML** (pogrubienia, linki itd.) — domyślnie włączony; przełącznik na zwykły tekst.
- **Dodaj załącznik** — wiele plików, każdy z możliwością usunięcia.
- **Full size** — większe okno pisania.
- **Stopka** skrzynki doklejana automatycznie.
- **Wyślij / Anuluj.**
- Kopia wysłanego trafia do katalogu **SEND → [nazwa skrzynki]**.

## 10. Kolory (flagi — jak tagi w Thunderbirdzie)
- Klawisze na zaznaczonym/otwartym mailu: **1 = czerwony, 2 = zielony, 3 = niebieski, 4 = pomarańczowy, 0 = bez koloru.**
- **Toggle** — ten sam klawisz drugi raz zdejmuje kolor.
- Kolor **tintuje cały wiersz** (tekst).
- Kolorować można też z paska akcji masowych i filtrować po kolorze (pkt 3).

## 11. Przypomnienie (prawy dolny róg)
- Pływająca karta **„Nieprzeczytane wiadomości: N"**.
- **Pokaż nieprzeczytane** — przeskakuje do widoku nieprzeczytanych.
- **Ukryj (×)** — chowa; wraca, gdy przybędą nowe.

## 12. Skróty klawiszowe
- **Del** — do kosza (w koszu: przywróć).
- **1 / 2 / 3 / 4** — kolory; **0** — bez koloru.
- **Esc** — zamyka menu kontekstowe.

## 13. Ustawienia (Argo Mail → Ustawienia)
- **Katalogi** — dodaj / podkatalog / zmień nazwę / usuń / **przenieś do innego folderu** (dropdown „Przenieś do…", z ochroną przed cyklem) / zmień kolejność (uchwyt ☰, drzewo).
- **Kategorie** — dodaj (z kolorem) / usuń; widać liczbę maili.
- **Osoby** — dodaj osobę z systemu PIM (+ kolor etykiety) / usuń.
- **Spam** — lista zablokowanych nadawców (z liczbą maili), ręczne dodanie po adresie, **Przywróć (nie spam)**.

## 14. AI (Narzędzia AI → Mail → Administrator)
- **Kategoryzuj AI** — automatyczne przypisanie kategorii do nieskategoryzowanych maili.

## 15. Automatyzacje w tle (efekt widać w UI)
- **Synchronizacja co minutę** — nowe maile dociągane same (`mail-sync-loop.bat` lokalnie / cron na produkcji).
- **Reguły nadawcy** — przypisanie osoby/katalogu stosowane do nowych maili (tworzone przez „na stałe" lub drag&drop).
- **Auto-spam** — maile od nadawców z listy spamu od razu trafiają do Spamu.
- **Auto-odświeżanie panelu co 60 s.**
