<?php

namespace App\Services;

use App\Models\FusionCache;
use Illuminate\Support\Facades\DB;

/**
 * Patches FusionPBX defaults that prevent outbound caller ID from reaching
 * upstream SIP trunks.
 *
 * The stock FusionPBX OUTBOUND_CALLER_ID dialplan ends with an idempotent
 * no-op ({@code set outbound_caller_id_number=${outbound_caller_id_number}})
 * that never copies the extension's configured CID onto effective_caller_id
 * or exports it as PAI. Combined with gateways shipping with
 * {@code caller_id_in_from} unset (defaulting to false), the outbound INVITE
 * carries only the gateway auth-user in From and the extension number in
 * Remote-Party-ID — carriers then substitute their default trunk CLI.
 *
 * This runs at every deploy (via Ansible) so newly-provisioned domains get
 * patched without a manual step.
 */
class OutboundCallerIdFixer
{
    // Matches the inner action block of the `^\d{6,25}$` condition — whether
    // it's the stock no-op set OR an earlier patched form that omitted '+' on
    // the E.164 CLI. Replacing the whole inner block keeps the patcher
    // idempotent across rule shape changes.
    public const BROKEN_ACTION_PATTERN =
        '/(<condition field="\$\{outbound_caller_id_number\}" expression="\^\\\\d\{6,25\}\$" break="never">\s*\n)'
        // [^\n]* — action data attributes can contain literal '>' (e.g. <sip:…>),
        // so we must not stop at the first '>'. Each <action …/> sits on one line.
        . '((?:[ \t]*<action [^\n]*\/>\s*\n)+)'
        . '(\s*<\/condition>)/';

    // The dialplan regex (^\d{6,25}$) guarantees digits-only, so prepending
    // '+' here always produces clean E.164 — Magrathea and similar UK carriers
    // require the leading '+' or they substitute the default trunk CLI.
    public const REPLACEMENT_FORMAT = "%s%s<action application=\"set\" data=\"effective_caller_id_number=+\${outbound_caller_id_number}\" inline=\"true\"/>\n%s<action application=\"set\" data=\"effective_caller_id_name=\${outbound_caller_id_name}\" inline=\"true\"/>\n%s<action application=\"export\" data=\"sip_h_P-Asserted-Identity=<sip:+\${outbound_caller_id_number}@\${domain_name}>\"/>\n%s";

    /**
     * @return array{dialplans_patched: int, gateways_patched: int}
     */
    public function run(): array
    {
        return [
            'dialplans_patched' => $this->patchDialplans(),
            'gateways_patched' => $this->patchGateways(),
        ];
    }

    protected function patchDialplans(): int
    {
        $rows = DB::table('v_dialplans')
            ->where('dialplan_name', 'OUTBOUND_CALLER_ID')
            ->get(['dialplan_uuid', 'dialplan_xml', 'domain_uuid']);

        $patched = 0;
        $patchedDomainUuids = [];
        $patchedGlobal = false;

        foreach ($rows as $row) {
            $xml = $row->dialplan_xml;

            if ($xml === null || $xml === '') {
                continue;
            }

            // Already on the current target — '+' prefix is present on the CLI export.
            if (strpos($xml, 'sip_h_P-Asserted-Identity=<sip:+${outbound_caller_id_number}@') !== false) {
                continue;
            }

            $count = 0;
            $newXml = preg_replace_callback(
                self::BROKEN_ACTION_PATTERN,
                function ($m) {
                    // Use the indent of the first inner action for new lines.
                    $indent = '';
                    if (preg_match('/^([ \t]+)</m', $m[2], $im)) {
                        $indent = $im[1];
                    }
                    return sprintf(self::REPLACEMENT_FORMAT, $m[1], $indent, $indent, $indent, $m[3]);
                },
                $xml,
                -1,
                $count,
            );

            if ($count > 0) {
                DB::table('v_dialplans')
                    ->where('dialplan_uuid', $row->dialplan_uuid)
                    ->update([
                        'dialplan_xml' => $newXml,
                        'update_date' => date('Y-m-d H:i:s'),
                    ]);
                $patched++;
                if ($row->domain_uuid) {
                    $patchedDomainUuids[$row->domain_uuid] = true;
                } else {
                    // A global OUTBOUND_CALLER_ID rule is reused by every domain,
                    // so all per-domain dialplan caches need invalidation.
                    $patchedGlobal = true;
                }
            }
        }

        if ($patched > 0) {
            // FusionPBX renders OUTBOUND_CALLER_ID into the per-domain dialplan
            // cache file (/var/cache/fusionpbx/dialplan.<domain>), not into
            // dialplan.public.* — the cache must be cleared by domain name or
            // the stale XML keeps serving and the DB write looks like a no-op.
            $domainNames = $this->resolveDomainNames(
                $patchedGlobal ? null : array_keys($patchedDomainUuids)
            );
            foreach ($domainNames as $domainName) {
                FusionCache::clear('dialplan:' . $domainName);
            }
        }

        return $patched;
    }

    /**
     * @param array<int, string>|null $domainUuids null = all domains
     * @return array<int, string>
     */
    protected function resolveDomainNames(?array $domainUuids): array
    {
        // No enabled-filter: we're just deleting cache files. Even if a domain
        // is disabled, evicting its stale cache is safe and the rebuild lazy.
        $q = DB::table('v_domains');
        if ($domainUuids !== null) {
            $q->whereIn('domain_uuid', $domainUuids);
        }
        return $q->pluck('domain_name')->all();
    }

    protected function patchGateways(): int
    {
        return DB::table('v_gateways')
            ->where(function ($q) {
                $q->whereNull('caller_id_in_from')->orWhere('caller_id_in_from', '');
            })
            ->update([
                'caller_id_in_from' => 'true',
                'update_date' => date('Y-m-d H:i:s'),
            ]);
    }
}
