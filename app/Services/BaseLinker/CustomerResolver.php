<?php

namespace App\Services\BaseLinker;

use App\Models\Connect\Customer;
use Illuminate\Support\Carbon;

class CustomerResolver
{
    /**
     * Na podstawie payloadu zamówienia BL znajduje lub tworzy klienta
     * i aktualizuje jego dane statystyczne.
     *
     * @param  array<string,mixed>  $payload
     */
    public function resolveFromOrderPayload(array $payload): Customer
    {
        $email = $this->lowerOrNull($payload['email'] ?? null);
        $phoneRaw = $payload['phone'] ?? null;
        $phone = Customer::normalizePhone($phoneRaw);

        $customer = $this->findExisting($email, $phone);

        [$first, $last] = Customer::splitFullName(
            $payload['delivery_fullname'] ?? $payload['invoice_fullname'] ?? null
        );

        $source = $payload['order_source'] ?? null;
        $orderDate = $this->unixToCarbon($payload['date_add'] ?? null);

        if ($customer === null) {
            $customer = new Customer();
            $customer->sources = [];
        }

        // Podstawowe identyfikatory (tylko gdy puste — nie nadpisujemy lepszymi)
        $customer->email = $customer->email ?: $email;
        $customer->phone = $customer->phone ?: $phone;
        $customer->phone_raw = $customer->phone_raw ?: $phoneRaw;
        $customer->user_login = $customer->user_login ?: ($payload['user_login'] ?? null);
        $customer->crm_client_id = $customer->crm_client_id ?: ($payload['crm_client_id'] ?? null);

        // Imię i nazwisko — nadpisuj jeśli pełniejsze niż dotychczas
        if ($first && ! $customer->first_name) {
            $customer->first_name = $first;
        }
        if ($last && ! $customer->last_name) {
            $customer->last_name = $last;
        }
        $customer->full_name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: null;

        // Firma / NIP — z danych fakturowych jeśli są
        $customer->company = $customer->company ?: ($payload['invoice_company'] ?? $payload['delivery_company'] ?? null);
        $customer->nip = $customer->nip ?: ($payload['invoice_nip'] ?? null);

        // Adres — zawsze najświeższy (adres dostawy z tego zamówienia)
        $customer->address = $payload['delivery_address'] ?? $customer->address;
        $customer->postcode = $payload['delivery_postcode'] ?? $customer->postcode;
        $customer->city = $payload['delivery_city'] ?? $customer->city;
        $customer->state = $payload['delivery_state'] ?? $customer->state;
        $customer->country = $payload['delivery_country'] ?? $customer->country;
        $customer->country_code = strtoupper($payload['delivery_country_code'] ?? '') ?: $customer->country_code;

        // Źródła — zbiór
        if ($source) {
            $sources = is_array($customer->sources) ? $customer->sources : [];
            if (! in_array($source, $sources, true)) {
                $sources[] = $source;
                $customer->sources = $sources;
            }
            $customer->primary_source = $customer->primary_source ?: $source;
        }

        // Daty pierwszego / ostatniego zamówienia
        if ($orderDate) {
            if (! $customer->first_order_at || $orderDate->lt($customer->first_order_at)) {
                $customer->first_order_at = $orderDate;
            }
            if (! $customer->last_order_at || $orderDate->gt($customer->last_order_at)) {
                $customer->last_order_at = $orderDate;
            }
        }

        $customer->save();
        return $customer;
    }

    /**
     * Przelicza statystyki klientów (orders_count) po masowym imporcie.
     */
    public function recomputeStats(?int $customerId = null): void
    {
        $query = Customer::query();
        if ($customerId) {
            $query->whereKey($customerId);
        }

        $query->chunkById(200, function ($customers) {
            foreach ($customers as $customer) {
                $customer->orders_count = $customer->orders()->count();
                $customer->save();
            }
        });
    }

    private function findExisting(?string $email, ?string $phone): ?Customer
    {
        if ($email) {
            $byEmail = Customer::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($byEmail) {
                return $byEmail;
            }
        }
        if ($phone) {
            $byPhone = Customer::where('phone', $phone)->first();
            if ($byPhone) {
                return $byPhone;
            }
        }
        return null;
    }

    private function lowerOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        return mb_strtolower(trim($value));
    }

    private function unixToCarbon(mixed $unix): ?Carbon
    {
        if (! $unix) {
            return null;
        }
        return Carbon::createFromTimestamp((int) $unix);
    }
}
