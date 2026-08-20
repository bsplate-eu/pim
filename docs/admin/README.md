# Warstwa Admin — uprawnienia, role, dostęp do modułów

Wdrożona 2026‑08‑20. Tag: `admin-2026-08-20`.

Do tej pory PIM miał kontrolę dostępu tylko w starych modułach Craftera (produkty, cenniki, integracje, atrybuty, źródła, szablony, media) — tam każda akcja ma uprawnienie `crafter.<zasób>.<akcja>` sprawdzane w klasie Request. **Wszystko, co dobudowano później** (Argo HQ, Connect, Scope, Task, Mail, Tłumaczenia v2) **nie miało żadnej kontroli** — wystarczyło być zalogowanym. Ekrany do zarządzania rolami istniały, ale były odcięte od menu i niekompletne.

Ta warstwa domyka temat: uprawnienia modułowe egzekwowane po stronie serwera, pełne zarządzanie rolami z panelu, jedna grupa menu **Admin** na wszystko administracyjne oraz przypisywanie skrzynek Argo Mail do konkretnych osób.

---

## Spis

| dokument | zawartość |
|---|---|
| [01‑uprawnienia‑modulowe.md](01-uprawnienia-modulowe.md) | 9 uprawnień `crafter.module.*`, mapa tras, middleware `EnsureModuleAccess` |
| [02‑role‑i‑uprawnienia.md](02-role-i-uprawnienia.md) | CRUD ról, macierz uprawnień, etykiety PL, bezpiecznik przed samo‑zablokowaniem, menu Admin |
| [03‑skrzynki‑argo‑mail.md](03-skrzynki-argo-mail.md) | przypisywanie skrzynek per użytkownik, `scopeVisibleTo`, middleware `mail.account` |
| [04‑wdrozenie‑i‑pulapki.md](04-wdrozenie-i-pulapki.md) | log wdrożeń, incydent z częściowym deployem, pułapki przy równoległych sesjach |

---

## Model uprawnień w skrócie

```
crafter                        wstęp do panelu
crafter.<zasób>.<akcja>        stare moduły Craftera — bramka w klasie Request
crafter.module.<moduł>         moduły Argo — bramka w middleware EnsureModuleAccess
crafter.mail-account.all       omija imienne przypisanie skrzynek Argo Mail
```

Uprawnienia wiszą na **rolach** (Spatie, guard `crafter`). Użytkownik ma dokładnie jedną rolę. Wyjątkiem są **skrzynki Argo Mail**, które przypisuje się imiennie osobie, nie roli — dwie osoby na tym samym stanowisku zwykle obsługują różne adresy.

## Gdzie się co ustawia

| chcesz | ekran |
|---|---|
| nadać/odebrać uprawnienia roli | Admin → Użytkownicy i uprawnienia → **Uprawnienia (macierz)** |
| założyć, przemianować, usunąć rolę | Admin → Użytkownicy i uprawnienia → **Role** |
| edytować uprawnienia jednej roli w drzewku | **Role** → ołówek |
| przypisać osobę do roli | Admin → Użytkownicy i uprawnienia → **Users** → edycja |
| wybrać skrzynki Argo Mail dla osoby | **Users** → edycja → karta **Skrzynki Argo Mail** |

## Stan produkcji (2026‑08‑20)

Role: `Administrator` (komplet uprawnień), `Guest`.

| konto | rola | moduły | skrzynki |
|---|---|---|---|
| info@bsplate.eu | Administrator | wszystkie | wszystkie (przez `mail-account.all`) |
| m.kowalik@b2bpareto.pl | Administrator | wszystkie | wszystkie (przez `mail-account.all`) |
| m.zajac@oslonypareto.pl | Guest | wg macierzy | wg imiennych przypisań |

## Do dokończenia

- `MailController::emptyTrash()` i `emptySpam()` nie mają zawężenia `visibleTo()` — to metody z trwającego portu Argo Mail, których nie było w repo w chwili pisania tej warstwy. Dodać, gdy tamta praca wejdzie. Szczegóły w [03‑skrzynki‑argo‑mail.md](03-skrzynki-argo-mail.md).
- Nazwa podgrupy menu **„Poczty (SMTP)"** — do potwierdzenia, czy nie miało być „Poczta (SMTP)".
