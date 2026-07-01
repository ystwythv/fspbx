<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\ReceptionAppointment;
use App\Models\ReceptionContact;
use App\Models\ReceptionInteraction;
use App\Models\ReceptionLead;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * GDPR subject access / erasure for a caller's reception memory (voxragtm#95).
 * Default exports everything held about a number; --delete erases it.
 *
 *   php artisan reception:contact-data acme.voxra.uk +447700900123
 *   php artisan reception:contact-data acme.voxra.uk +447700900123 --delete
 */
class ReceptionContactData extends Command
{
    protected $signature = 'reception:contact-data
        {domain : domain_name or domain_uuid}
        {number : the caller phone number}
        {--delete : erase all data held about this number}';

    protected $description = 'Export or erase all reception memory held about a caller (GDPR).';

    public function handle(): int
    {
        $domainArg = (string) $this->argument('domain');
        $domain = Str::isUuid($domainArg)
            ? Domain::where('domain_uuid', $domainArg)->first()
            : Domain::where('domain_name', $domainArg)->first();
        if (!$domain) {
            $this->error("Domain not found: {$domainArg}");
            return self::FAILURE;
        }

        $dom = $domain->domain_uuid;
        $number = trim((string) $this->argument('number'));

        $contact = ReceptionContact::where('domain_uuid', $dom)->where('phone_number', $number)->first();
        $leads = ReceptionLead::where('domain_uuid', $dom)->where('caller_number', $number)->get();
        $appointments = ReceptionAppointment::where('domain_uuid', $dom)->where('customer_number', $number)->get();
        $interactions = $contact
            ? ReceptionInteraction::where('domain_uuid', $dom)->where('reception_contact_uuid', $contact->reception_contact_uuid)->get()
            : collect();

        if ($this->option('delete')) {
            $interactions->each->delete();
            $appointments->each->delete();
            $leads->each->delete();
            $contact?->delete();
            $this->info(sprintf(
                'Erased: contact=%d, leads=%d, appointments=%d, interactions=%d for %s',
                $contact ? 1 : 0,
                $leads->count(),
                $appointments->count(),
                $interactions->count(),
                $number,
            ));
            return self::SUCCESS;
        }

        $this->line((string) json_encode([
            'domain_uuid'  => $dom,
            'number'       => $number,
            'contact'      => $contact,
            'leads'        => $leads,
            'appointments' => $appointments,
            'interactions' => $interactions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
