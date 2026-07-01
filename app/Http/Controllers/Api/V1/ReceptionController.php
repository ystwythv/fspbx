<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\V1\ReceptionAppointmentData;
use App\Data\Api\V1\ReceptionLeadData;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ReceptionAppointment;
use App\Models\ReceptionLead;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read API over what the reception agent captured — leads (voxragtm#28) and
 * booked appointments (voxragtm#29) — for the Voxra web console inbox (#50) and
 * mobile feed (#60). Domain-scoped, cursor-paginated, mirrors AiAgentController.
 */
class ReceptionController extends Controller
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

    private function cursor(Request $request, string $pkColumn): array
    {
        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(100, $limit));

        $startingAfter = (string) $request->input('starting_after', '');
        if ($startingAfter !== '' && ! preg_match('/^[0-9a-fA-F-]{36}$/', $startingAfter)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid starting_after UUID.', 'invalid_request', 'starting_after');
        }

        return [$limit, $startingAfter];
    }

    /**
     * List reception leads
     *
     * @group Reception
     * @authenticated
     *
     * @urlParam domain_uuid string required The domain UUID. Example: 4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b
     * @queryParam limit integer Optional. 1–100, default 50. Example: 50
     * @queryParam starting_after string Optional. Cursor: last reception_lead_uuid from the previous page.
     */
    public function leads(Request $request, string $domain_uuid)
    {
        $this->requireDomain($request, $domain_uuid);
        [$limit, $startingAfter] = $this->cursor($request, 'reception_lead_uuid');

        $query = QueryBuilder::for(ReceptionLead::class)
            ->where('domain_uuid', $domain_uuid)
            ->defaultSort('reception_lead_uuid')
            ->reorder('reception_lead_uuid')
            ->limit($limit + 1);

        if ($startingAfter !== '') {
            $query->where('reception_lead_uuid', '>', $startingAfter);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $data = $rows->map(fn ($l) => new ReceptionLeadData(
            reception_lead_uuid: (string) $l->reception_lead_uuid,
            object: 'reception_lead',
            domain_uuid: (string) $l->domain_uuid,
            conversation_id: $l->conversation_id,
            caller_number: $l->caller_number,
            name: $l->name,
            postcode: $l->postcode,
            job_description: $l->job_description,
            urgency: $l->urgency,
            status: $l->status,
            created_at: $l->insert_date ? Carbon::parse($l->insert_date)->toIso8601String() : null,
        ));

        return response()->json([
            'object'   => 'list',
            'url'      => "/api/v1/domains/{$domain_uuid}/reception/leads",
            'has_more' => $hasMore,
            'data'     => $data,
        ], 200);
    }

    /**
     * List reception appointments
     *
     * @group Reception
     * @authenticated
     *
     * @urlParam domain_uuid string required The domain UUID. Example: 4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b
     * @queryParam limit integer Optional. 1–100, default 50. Example: 50
     * @queryParam starting_after string Optional. Cursor: last reception_appointment_uuid from the previous page.
     * @queryParam from string Optional. Only appointments starting on/after this date-time. Example: 2026-07-01
     * @queryParam to string Optional. Only appointments starting before this date-time. Example: 2026-07-02
     */
    public function appointments(Request $request, string $domain_uuid)
    {
        $this->requireDomain($request, $domain_uuid);
        [$limit, $startingAfter] = $this->cursor($request, 'reception_appointment_uuid');

        $query = QueryBuilder::for(ReceptionAppointment::class)
            ->where('domain_uuid', $domain_uuid)
            ->defaultSort('reception_appointment_uuid')
            ->reorder('reception_appointment_uuid')
            ->limit($limit + 1);

        if ($startingAfter !== '') {
            $query->where('reception_appointment_uuid', '>', $startingAfter);
        }

        foreach (['from' => '>=', 'to' => '<'] as $param => $op) {
            $value = (string) $request->input($param, '');
            if ($value !== '') {
                try {
                    $query->where('starts_at', $op, Carbon::parse($value));
                } catch (\Throwable $e) {
                    throw new ApiException(400, 'invalid_request_error', "Invalid {$param} date.", 'invalid_request', $param);
                }
            }
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $data = $rows->map(fn ($a) => new ReceptionAppointmentData(
            reception_appointment_uuid: (string) $a->reception_appointment_uuid,
            object: 'reception_appointment',
            domain_uuid: (string) $a->domain_uuid,
            reception_lead_uuid: $a->reception_lead_uuid,
            conversation_id: $a->conversation_id,
            customer_name: $a->customer_name,
            customer_number: $a->customer_number,
            service: $a->service,
            starts_at: $a->starts_at ? Carbon::parse($a->starts_at)->toIso8601String() : null,
            ends_at: $a->ends_at ? Carbon::parse($a->ends_at)->toIso8601String() : null,
            deposit_amount: $a->deposit_amount !== null ? (string) $a->deposit_amount : null,
            status: $a->status,
        ));

        return response()->json([
            'object'   => 'list',
            'url'      => "/api/v1/domains/{$domain_uuid}/reception/appointments",
            'has_more' => $hasMore,
            'data'     => $data,
        ], 200);
    }
}
