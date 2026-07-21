<?php

namespace App\Console\Commands;

use App\Models\CallRate;
use App\Models\CallTariff;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Loads carrier rate decks into a tariff from CSV (issue #8).
 *
 * Expected columns (header row required, extra columns ignored):
 *   prefix,rate_per_minute[,setup_fee][,min_duration_sec]
 *   [,billing_increment_sec][,effective_from][,effective_to]
 */
class ImportCallRates extends Command
{
    protected $signature = 'tariffs:import-rates
        {tariff : Tariff UUID or name}
        {csv : Path to the CSV file}
        {--create : Create the tariff if it does not exist (by name)}
        {--currency=GBP : Currency when creating a tariff}
        {--domain= : Domain UUID when creating a tenant-specific tariff}
        {--replace : Delete the tariff\'s existing rates first}';

    protected $description = 'Import per-prefix call rates into a tariff from a CSV rate deck';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        if (! is_readable($csvPath)) {
            $this->error("Cannot read {$csvPath}.");
            return self::FAILURE;
        }

        $tariff = $this->resolveTariff();
        if (! $tariff) {
            return self::FAILURE;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error('CSV is empty.');
            fclose($handle);
            return self::FAILURE;
        }
        $columns = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
        if (! in_array('prefix', $columns, true) || ! in_array('rate_per_minute', $columns, true)) {
            $this->error('CSV must have at least "prefix" and "rate_per_minute" columns.');
            fclose($handle);
            return self::FAILURE;
        }

        if ($this->option('replace')) {
            $deleted = CallRate::where('tariff_uuid', $tariff->tariff_uuid)->delete();
            $this->warn("Deleted {$deleted} existing rates from {$tariff->tariff_name}.");
        }

        $imported = 0;
        $errors = 0;
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $data = [];
            foreach ($columns as $i => $name) {
                $data[$name] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $prefix = preg_replace('/[^0-9]/', '', $data['prefix']);
            if ($prefix === '' || ! is_numeric($data['rate_per_minute'])) {
                $this->warn("line {$line}: skipped (bad prefix or rate)");
                $errors++;
                continue;
            }

            try {
                CallRate::create([
                    'tariff_uuid' => $tariff->tariff_uuid,
                    'destination_prefix' => substr($prefix, 0, 24),
                    'rate_per_minute' => (float) $data['rate_per_minute'],
                    'setup_fee' => is_numeric($data['setup_fee'] ?? '') ? (float) $data['setup_fee'] : 0,
                    'min_duration_sec' => is_numeric($data['min_duration_sec'] ?? '') ? (int) $data['min_duration_sec'] : 0,
                    'billing_increment_sec' => is_numeric($data['billing_increment_sec'] ?? '') ? (int) $data['billing_increment_sec'] : 1,
                    'effective_from' => ! empty($data['effective_from']) ? Carbon::parse($data['effective_from']) : null,
                    'effective_to' => ! empty($data['effective_to']) ? Carbon::parse($data['effective_to']) : null,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $this->warn("line {$line}: " . $e->getMessage());
                $errors++;
            }
        }
        fclose($handle);

        $this->info("Imported {$imported} rates into \"{$tariff->tariff_name}\" ({$tariff->currency}), {$errors} rows skipped.");
        $this->line('Rate new calls with: php artisan cdr:rate — backfill with --from/--to.');

        return $errors > 0 && $imported === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveTariff(): ?CallTariff
    {
        $key = trim((string) $this->argument('tariff'));

        $tariff = preg_match('/^[0-9a-fA-F-]{36}$/', $key)
            ? CallTariff::find($key)
            : CallTariff::where('tariff_name', $key)->first();

        if ($tariff) {
            return $tariff;
        }

        if (! $this->option('create')) {
            $this->error("Tariff \"{$key}\" not found. Pass --create to create it.");
            return null;
        }

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $key)) {
            $this->error('--create needs a tariff name, not a UUID.');
            return null;
        }

        return CallTariff::create([
            'tariff_name' => $key,
            'currency' => strtoupper((string) $this->option('currency')),
            'domain_uuid' => $this->option('domain') ?: null,
            'enabled' => true,
        ]);
    }
}
