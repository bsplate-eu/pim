<?php

namespace App\Console\Commands;

use App\Models\Connect\Order;
use App\Services\BaseLinker\CustomerResolver;
use Illuminate\Console\Command;

class ConnectRebuildCustomersCommand extends Command
{
    protected $signature = 'connect:rebuild-customers {--chunk=200 : Rozmiar paczki}';

    protected $description = 'Przebudowuje bazę klientów na podstawie istniejących zamówień.';

    public function handle(CustomerResolver $resolver): int
    {
        $chunk = (int) $this->option('chunk');
        $processed = 0;

        $this->info('Przebudowa bazy klientów z zamówień…');

        $total = Order::count();
        if ($total === 0) {
            $this->warn('Brak zamówień do przetworzenia.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Order::query()
            ->orderBy('id')
            ->chunk($chunk, function ($orders) use ($resolver, &$processed, $bar) {
                foreach ($orders as $order) {
                    $payload = $order->raw_payload ?: [];
                    if (empty($payload)) {
                        // Zrekonstruuj minimalny payload z pól zamówienia
                        $payload = [
                            'email' => $order->email,
                            'phone' => $order->phone,
                            'user_login' => $order->user_login,
                            'delivery_fullname' => $order->delivery_fullname,
                            'delivery_company' => $order->delivery_company,
                            'delivery_address' => $order->delivery_address,
                            'delivery_postcode' => $order->delivery_postcode,
                            'delivery_city' => $order->delivery_city,
                            'delivery_state' => $order->delivery_state,
                            'delivery_country' => $order->delivery_country,
                            'delivery_country_code' => $order->delivery_country_code,
                            'invoice_company' => $order->invoice_company,
                            'invoice_nip' => $order->invoice_nip,
                            'invoice_fullname' => $order->invoice_fullname,
                            'order_source' => $order->order_source,
                            'date_add' => $order->date_add?->timestamp,
                        ];
                    }

                    $customer = $resolver->resolveFromOrderPayload($payload);
                    $order->customer_id = $customer->id;
                    $order->saveQuietly();
                    $processed++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        $this->info('Przeliczanie statystyk klientów…');
        $resolver->recomputeStats();

        $this->info("Gotowe. Zaktualizowano {$processed} zamówień.");
        return self::SUCCESS;
    }
}
