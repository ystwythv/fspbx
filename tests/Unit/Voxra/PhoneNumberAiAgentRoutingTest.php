<?php

namespace Tests\Unit\Voxra;

use App\Http\Requests\Api\V1\StorePhoneNumberRequest;
use App\Http\Requests\Api\V1\UpdatePhoneNumberRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * voxragtm#133: iqportal routes a Voxra DDI straight to the tenant's AI
 * reception agent with routing_options [{type: "ai_agent", extension: "9250"}].
 * The V1 phone-number API used to reject that type, so a Pro/Complete tenant
 * without an eSIM could not be numbered "AI first".
 */
class PhoneNumberAiAgentRoutingTest extends TestCase
{
    private function routingValidator($request): \Illuminate\Validation\Validator
    {
        $request->prepareForValidation();
        $rules = array_intersect_key($request->rules(), array_flip([
            'routing_options', 'routing_options.*.type', 'routing_options.*.extension',
        ]));

        return Validator::make($request->all(), $rules);
    }

    public function test_update_accepts_ai_agent_and_normalises_to_ai_agents(): void
    {
        foreach (['ai_agent', 'ai_agents'] as $type) {
            $req = UpdatePhoneNumberRequest::create('/x', 'PATCH', [
                'routing_options' => [['type' => $type, 'extension' => '9250']],
            ]);
            $v = $this->routingValidator($req);

            $this->assertFalse($v->fails(), $type . ': ' . json_encode($v->errors()->all()));
            $this->assertSame('ai_agents', $req->input('routing_options.0.type'));
        }
    }

    public function test_store_accepts_ai_agent(): void
    {
        $req = StorePhoneNumberRequest::create('/x', 'POST', [
            'routing_options' => [['type' => 'ai_agent', 'extension' => '9250']],
        ]);
        $v = $this->routingValidator($req);

        $this->assertFalse($v->fails(), json_encode($v->errors()->all()));
        $this->assertSame('ai_agents', $req->input('routing_options.0.type'));
    }

    public function test_unknown_type_still_rejected(): void
    {
        $req = UpdatePhoneNumberRequest::create('/x', 'PATCH', [
            'routing_options' => [['type' => 'robot', 'extension' => '9250']],
        ]);

        $this->assertTrue($this->routingValidator($req)->fails());
    }

    public function test_ai_agents_builds_a_transfer_to_the_agent_extension(): void
    {
        $this->assertSame(
            ['destination_app' => 'transfer', 'destination_data' => '9250 XML acme.voxra.uk'],
            buildDestinationAction(['type' => 'ai_agents', 'extension' => '9250'], 'acme.voxra.uk'),
        );
    }
}
