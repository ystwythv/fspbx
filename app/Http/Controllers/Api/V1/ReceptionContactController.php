<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\V1\ReceptionContactData;
use App\Data\Api\V1\ReceptionInteractionData;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ReceptionContact;
use App\Models\ReceptionInteraction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read API over per-customer contact memory (voxragtm#96) — the CRM-style
 * customer list + timeline for the web/mobile console. Domain-scoped.
 */
class ReceptionContactController extends Controller
{
    private function requireDomain(Request $request, string $domain_uuid): void
    {
        if (! $request->user()) {
            throw new ApiException(401, 'authentication_error', 'Unauthenticated.', 'unauthenticated');
        }
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid domain UUID.', 'invalid_request', 'domain_uuid');
        }
        if (! Domain::query()->where('domain_uuid', $domain_uuid)->exists()) {
            throw new ApiException(404, 'invalid_request_error', 'Domain not found.', 'resource_missing', 'domain_uuid');
        }
    }

    /**
     * List contacts
     *
     * @group Reception
     * @authenticated
     */
    public function index(Request $request, string $domain_uuid)
    {
        $this->requireDomain($request, $domain_uuid);

        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $startingAfter = (string) $request->input('starting_after', '');
        if ($startingAfter !== '' && ! preg_match('/^[0-9a-fA-F-]{36}$/', $startingAfter)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid starting_after UUID.', 'invalid_request', 'starting_after');
        }

        $query = QueryBuilder::for(ReceptionContact::class)
            ->where('domain_uuid', $domain_uuid)
            ->defaultSort('reception_contact_uuid')
            ->reorder('reception_contact_uuid')
            ->limit($limit + 1);

        if ($startingAfter !== '') {
            $query->where('reception_contact_uuid', '>', $startingAfter);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $data = $rows->map(fn ($c) => $this->toData($c));

        return response()->json([
            'object'   => 'list',
            'url'      => "/api/v1/domains/{$domain_uuid}/reception/contacts",
            'has_more' => $hasMore,
            'data'     => $data,
        ], 200);
    }

    /**
     * Retrieve a contact with its recent interaction timeline
     *
     * @group Reception
     * @authenticated
     */
    public function show(Request $request, string $domain_uuid, string $contact_uuid)
    {
        $this->requireDomain($request, $domain_uuid);
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $contact_uuid)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid contact UUID.', 'invalid_request', 'contact_uuid');
        }

        $contact = ReceptionContact::where('domain_uuid', $domain_uuid)
            ->where('reception_contact_uuid', $contact_uuid)
            ->first();
        if (! $contact) {
            throw new ApiException(404, 'invalid_request_error', 'Contact not found.', 'resource_missing', 'contact_uuid');
        }

        $interactions = ReceptionInteraction::where('domain_uuid', $domain_uuid)
            ->where('reception_contact_uuid', $contact_uuid)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn ($i) => new ReceptionInteractionData(
                reception_interaction_uuid: (string) $i->reception_interaction_uuid,
                object: 'reception_interaction',
                channel: $i->channel,
                summary: $i->summary,
                outcome: $i->outcome,
                occurred_at: $i->occurred_at ? Carbon::parse($i->occurred_at)->toIso8601String() : null,
            ))
            ->all();

        return response()->json($this->toData($contact, $interactions), 200);
    }

    private function toData(ReceptionContact $c, ?array $recent = null): ReceptionContactData
    {
        return new ReceptionContactData(
            reception_contact_uuid: (string) $c->reception_contact_uuid,
            object: 'reception_contact',
            domain_uuid: (string) $c->domain_uuid,
            phone_number: $c->phone_number,
            name: $c->name,
            total_calls: (int) $c->total_calls,
            total_bookings: (int) $c->total_bookings,
            last_seen_at: $c->last_seen_at ? Carbon::parse($c->last_seen_at)->toIso8601String() : null,
            notes: $c->notes,
            recent: $recent,
        );
    }
}
