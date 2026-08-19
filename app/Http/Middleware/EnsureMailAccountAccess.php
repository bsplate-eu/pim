<?php

namespace App\Http\Middleware;

use App\Models\Mail\Account;
use App\Models\Mail\Message;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pilnuje, żeby użytkownik nie dobrał się do skrzynki, której nie ma przypisanej.
 *
 * Argo Mail ma kilkanaście endpointów operujących na pojedynczej wiadomości
 * (podgląd, wątek, załącznik, przenoszenie, spam, kolor…). Zamiast wklejać ten
 * sam warunek do każdej metody kontrolera, sprawdzamy tu parametr trasy:
 *
 *   {account}  → sprawdzamy id skrzynki,
 *   {message}  → sprawdzamy account_id wiadomości,
 *   account_id w body → dotyczy wysyłki (send) i operacji zbiorczych.
 *
 * Odczyt parametru jest odporny na kolejność middleware: jeśli SubstituteBindings
 * już zadziałał, dostajemy model; jeśli nie — surowe id i dociągamy je sami.
 */
class EnsureMailAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->can(Account::PERMISSION_ALL)) {
            return $next($request);
        }

        $allowed = Account::visibleIdsFor($user);

        foreach ($this->requestedAccountIds($request) as $accountId) {
            if (! in_array($accountId, $allowed, true)) {
                abort(403, 'Brak dostępu do tej skrzynki pocztowej.');
            }
        }

        return $next($request);
    }

    /**
     * Wszystkie identyfikatory skrzynek, których dotyka to żądanie.
     *
     * @return array<int, int>
     */
    protected function requestedAccountIds(Request $request): array
    {
        $ids = [];

        $account = $request->route('account');

        if ($account instanceof Account) {
            $ids[] = (int) $account->id;
        } elseif (is_numeric($account)) {
            $ids[] = (int) $account;
        }

        $message = $request->route('message');

        if ($message instanceof Message) {
            $ids[] = (int) $message->account_id;
        } elseif (is_numeric($message)) {
            $found = Message::query()->whereKey((int) $message)->value('account_id');

            if ($found !== null) {
                $ids[] = (int) $found;
            }
        }

        if ($request->filled('account_id')) {
            $ids[] = (int) $request->input('account_id');
        }

        return array_values(array_unique($ids));
    }
}
