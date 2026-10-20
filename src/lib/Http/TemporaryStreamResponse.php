<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Http;

use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\StreamResponse;

/**
 * Streamuje dočasný exportní soubor a po dokončení jej vždy odstraní.
 *
 * Vstup: cesta k dočasnému souboru, HTTP stav a hlavičky odpovědi.
 * Výstup: streamovaná HTTP odpověď bez načtení celého ZIPu do paměti PHP.
 */
final class TemporaryStreamResponse extends StreamResponse {
    private string $temporaryPath;

    /**
     * Připraví streamovanou odpověď nad dočasným souborem.
     * Vstup: `$temporaryPath`, HTTP `$status` a `$headers`.
     * Výstup: inicializovaný objekt odpovědi.
     *
     * @param array<string,mixed> $headers
     */
    public function __construct(string $temporaryPath, int $status = 200, array $headers = []) {
        $this->temporaryPath = $temporaryPath;
        parent::__construct($temporaryPath, $status, $headers);
    }

    /**
     * Odešle dočasný soubor klientovi a následně jej odstraní z disku.
     * Vstup: výstupní adaptér Nextcloudu `$output`.
     * Výstup: bez návratové hodnoty; data jsou zapsána do HTTP odpovědi.
     */
    #[\Override]
    public function callback(IOutput $output) {
        try {
            parent::callback($output);
        } finally {
            @unlink($this->temporaryPath);
        }
    }

    /**
     * Pojistně odstraní dočasný soubor, pokud by odpověď nebyla standardně odeslána.
     * Vstup: žádný přímý parametr.
     * Výstup: bez návratové hodnoty; případný zbylý dočasný soubor je odstraněn.
     */
    public function __destruct() {
        if (is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
