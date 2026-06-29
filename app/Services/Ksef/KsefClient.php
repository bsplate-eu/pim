<?php

namespace App\Services\Ksef;

use App\Models\Ksef\KsefSettings;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use N1ebieski\KSEFClient\Actions\DecryptDocument\DecryptDocumentAction;
use N1ebieski\KSEFClient\Actions\DecryptDocument\DecryptDocumentHandler;
use N1ebieski\KSEFClient\ClientBuilder;
use N1ebieski\KSEFClient\Factories\EncryptionKeyFactory;
use N1ebieski\KSEFClient\Requests\Invoices\Download\DownloadRequest;
use N1ebieski\KSEFClient\ValueObjects\EncryptionKey;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;

/**
 * Klient KSeF 2.0 dla pojedynczej firmy (Pareto / BSP). Owija SDK n1ebieski/ksef-php-client.
 *
 * Pobieranie masowe = EKSPORT ZBIORCZY (async): init → poll → paczki .zip.aes → deszyfracja →
 * ZIP → pełne XML-e faktur. Jedna operacja na okno dat → NIE wpada w limit pojedynczych pobrań,
 * a w XML mamy wszystko (pozycje P_7, termin płatności, strony, kwoty). Nazwa pliku w ZIP = numer KSeF.
 *
 * subjectType: 'Subject2' = nabywca (zakupowe, domyślne) | 'Subject1' = sprzedawca (sprzedażowe).
 * KSeF: zakres dat w 1 zapytaniu < 3 miesiące → dzielimy na okna ~miesięczne.
 */
class KsefClient
{
    private const HARD_CAP = 8000;
    private const THROTTLE_US = 300000; // 0.3s — pojedyncze pobranie (PDF)
    private const EXPORT_TIMEOUT = 240;  // s — maks. oczekiwanie na przygotowanie paczki

    private mixed $client = null;
    private ?EncryptionKey $encryptionKey = null;

    public function __construct(private readonly KsefSettings $settings)
    {
    }

    /**
     * Masowe pobranie pełnych faktur z KSeF przez eksport zbiorczy.
     *
     * @return array<int, array> każdy element = wynik KsefInvoiceParser::parse() + klucz 'ksef_ref'
     */
    public function exportInvoices(DateTimeInterface $from, DateTimeInterface $to, string $subjectType = 'Subject2', string $dateType = 'Issue'): array
    {
        $client = $this->client();
        $decrypt = new DecryptDocumentHandler();
        $result = [];

        foreach ($this->monthlyWindows($from, $to) as [$wFrom, $wTo]) {
            $init = $client->invoices()->exports()->init([
                'onlyMetadata' => false,
                'filters' => [
                    'subjectType' => $subjectType,
                    // 'Issue' = data wystawienia (backfill); 'Invoicing' = data rejestracji w KSeF (delta przyrostowa)
                    'dateRange' => ['dateType' => $dateType, 'from' => $wFrom, 'to' => $wTo],
                ],
            ])->object();

            $status = $this->awaitExport($client, $init->referenceNumber);
            $parts = $status->package->parts ?? [];
            if (! $parts) {
                continue;
            }

            usort($parts, fn ($a, $b) => ($a->ordinalNumber ?? 0) <=> ($b->ordinalNumber ?? 0));

            $zipBytes = '';
            foreach ($parts as $part) {
                $encrypted = @file_get_contents($part->url);
                if ($encrypted === false) {
                    continue;
                }
                $zipBytes .= $decrypt->handle(new DecryptDocumentAction(
                    encryptionKey: $this->encryptionKey(),
                    document: $encrypted,
                ));
            }
            if ($zipBytes === '') {
                continue;
            }

            foreach ($this->unzipXml($zipBytes) as $name => $xml) {
                $parsed = KsefInvoiceParser::parse($xml);
                $parsed['ksef_ref'] = pathinfo($name, PATHINFO_FILENAME);
                $parsed['xml'] = $xml; // pełny dokument — do PDF bez ponownego odpytania KSeF
                $result[] = $parsed;
                if (count($result) >= self::HARD_CAP) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * Pełny dokument faktury (XML FA) po numerze KSeF — pojedyncze pobranie (PDF na klik).
     * Throttle + backoff na 429.
     */
    public function downloadInvoiceXml(string $ksefNumber): string
    {
        $request = new DownloadRequest(KsefNumber::from($ksefNumber));

        for ($attempt = 0; ; $attempt++) {
            try {
                usleep(self::THROTTLE_US);

                return $this->client()->invoices()->download($request)->body();
            } catch (\Throwable $e) {
                if (! $this->isRateLimited($e) || $attempt >= 5) {
                    throw $e;
                }
                sleep(min(30, 2 ** $attempt));
            }
        }
    }

    /** Poll statusu eksportu aż „code 200" (gotowa paczka). */
    private function awaitExport(mixed $client, string $referenceNumber): object
    {
        $deadline = time() + self::EXPORT_TIMEOUT;

        while (time() < $deadline) {
            $response = $client->invoices()->exports()->status(['referenceNumber' => $referenceNumber])->object();
            $code = $response->status->code ?? 0;

            if ($code === 200) {
                return $response;
            }
            if ($code >= 400) {
                throw new \RuntimeException($response->status->description ?? 'Eksport KSeF nieudany.', $code);
            }

            sleep(3); // przetwarzanie — ponów
        }

        throw new \RuntimeException('Eksport KSeF: przekroczono czas oczekiwania na paczkę.');
    }

    /** @return array<string, string> nazwa pliku (= numer KSeF) => zawartość XML */
    private function unzipXml(string $zipBytes): array
    {
        $out = [];
        $tmp = tempnam(sys_get_temp_dir(), 'ksef_');
        file_put_contents($tmp, $zipBytes);

        $zip = new \ZipArchive();
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    $out[$name] = $content;
                }
            }
            $zip->close();
        }
        @unlink($tmp);

        return $out;
    }

    /** Buduje (i cache'uje) autoryzowaną sesję KSeF z kluczem szyfrującym (potrzebny do eksportu). */
    private function client(): mixed
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (empty($this->settings->auth_token) || empty($this->settings->nip)) {
            throw new \RuntimeException('Brak tokenu KSeF lub NIP w ustawieniach integracji.');
        }

        return $this->client = (new ClientBuilder())
            ->withMode($this->settings->environment === 'prod' ? Mode::Production : Mode::Test)
            ->withKsefToken($this->settings->auth_token)
            ->withIdentifier((string) $this->settings->nip)
            ->withEncryptionKey($this->encryptionKey())
            ->build();
    }

    private function encryptionKey(): EncryptionKey
    {
        return $this->encryptionKey ??= EncryptionKeyFactory::makeRandom();
    }

    private function isRateLimited(\Throwable $e): bool
    {
        return $e->getCode() === 429
            || str_contains($e->getMessage(), '429')
            || stripos($e->getMessage(), 'Too Many') !== false;
    }

    /**
     * Dzieli [from, to] na kolejne, nienakładające się okna ~1-miesięczne (każde < 3 mies.).
     *
     * @return array<int, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private function monthlyWindows(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $tz = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($from->format('Y-m-d') . 'T00:00:00', $tz);
        $end = new DateTimeImmutable($to->format('Y-m-d') . 'T23:59:59', $tz);

        $windows = [];
        while ($cursor <= $end) {
            $windowEnd = $cursor->modify('+1 month')->modify('-1 second');
            if ($windowEnd > $end) {
                $windowEnd = $end;
            }
            $windows[] = [$cursor, $windowEnd];
            $cursor = $cursor->modify('+1 month');
        }

        return $windows;
    }
}
