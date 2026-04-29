<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use App\Models\Dialplans;
use App\Models\FusionCache;
use App\Services\ElevenLabsConvaiService;
use App\Services\Tts\ElevenLabsTtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

/**
 * Reception agent — singleton per domain. Lives in v_ai_agents with mode='reception'
 * and a feature-code dialplan (default *9) generated from the reception template.
 */
class ReceptionAgentController extends Controller
{
    protected $viewName = 'ReceptionAgent';

    public const DEFAULT_FEATURE_CODE = '*9';
    public const DEFAULT_FIRST_MESSAGE = 'Hi, how can I help with this call?';
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
You are a phone-system reception assistant who has been pulled into an active
phone call as a third participant. Speak briefly. Confirm before you transfer
or park calls. After completing a request, call complete_and_exit so you drop
out of the call cleanly — never linger silently. Use lookup_user when the
person says a name; pick the closest match and confirm if ambiguous.
PROMPT;

    public function index()
    {
        if (!userCheckPermission('reception_agent_view')) {
            return redirect('/');
        }

        return Inertia::render($this->viewName, [
            'routes' => [
                'show'   => route('reception-agent.show'),
                'update' => route('reception-agent.update'),
                'test'   => route('reception-agent.test'),
            ],
            'permissions' => [
                'view'   => userCheckPermission('reception_agent_view'),
                'update' => userCheckPermission('reception_agent_update'),
                'test'   => userCheckPermission('reception_agent_test'),
            ],
        ]);
    }

    public function show(): JsonResponse
    {
        $agent = AiAgent::reception()->forDomain(session('domain_uuid'))->first();

        $voices = [];
        try {
            $voices = app(ElevenLabsTtsService::class)->getVoices();
        } catch (Throwable $e) {
            logger()->warning('reception agent: voice list unavailable: ' . $e->getMessage());
        }

        return response()->json([
            'agent'   => $agent,
            'voices'  => $voices,
            'defaults' => [
                'feature_code'   => self::DEFAULT_FEATURE_CODE,
                'first_message'  => self::DEFAULT_FIRST_MESSAGE,
                'system_prompt'  => self::DEFAULT_SYSTEM_PROMPT,
                'tools_enabled'  => [
                    'lookup_user'        => true,
                    'transfer_call'      => true,
                    'announced_transfer' => true,
                    'park_call'          => true,
                    'bring_back'         => true,
                    'three_way_add'      => true,
                    'complete_and_exit'  => true,
                    'get_time_in_city'   => true,
                    'get_weather'        => true,
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (!userCheckPermission('reception_agent_update')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $inputs = $request->validate([
            'agent_name'     => 'required|string|max:100',
            'feature_code'   => 'required|string|max:8',
            'system_prompt'  => 'nullable|string',
            'first_message'  => 'nullable|string|max:500',
            'voice_id'       => 'nullable|string|max:255',
            'language'       => 'nullable|string|max:20',
            'agent_enabled'  => 'required|in:true,false',
            'tools_enabled'  => 'array',
        ]);

        try {
            DB::beginTransaction();

            $convai = app(ElevenLabsConvaiService::class);
            $domainUuid = session('domain_uuid');

            $agent = AiAgent::reception()->forDomain($domainUuid)->first();

            if (!$agent) {
                // Provision: create the ConvAI agent on ElevenLabs first.
                $created = $convai->createAgent(
                    $inputs['agent_name'],
                    $inputs['system_prompt'] ?? self::DEFAULT_SYSTEM_PROMPT,
                    $inputs['first_message'] ?? self::DEFAULT_FIRST_MESSAGE,
                    $inputs['voice_id'] ?? null,
                    $inputs['language'] ?? 'en',
                );

                $agentExtension = $this->allocateAgentExtension($domainUuid);

                $agent = new AiAgent();
                $agent->fill([
                    'domain_uuid'         => $domainUuid,
                    'dialplan_uuid'       => Str::uuid(),
                    'agent_name'          => $inputs['agent_name'],
                    'agent_extension'     => $agentExtension,
                    'elevenlabs_agent_id' => $created['agent_id'] ?? null,
                    'system_prompt'       => $inputs['system_prompt'] ?? self::DEFAULT_SYSTEM_PROMPT,
                    'first_message'      => $inputs['first_message'] ?? self::DEFAULT_FIRST_MESSAGE,
                    'voice_id'            => $inputs['voice_id'] ?? null,
                    'language'            => $inputs['language'] ?? 'en',
                    'agent_enabled'       => $inputs['agent_enabled'],
                    'mode'                => AiAgent::MODE_RECEPTION,
                    'tools_enabled'       => $inputs['tools_enabled'] ?? [],
                    'feature_code'        => $inputs['feature_code'],
                    'description'         => 'Reception agent (*' . trim($inputs['feature_code'], '*') . ')',
                    'insert_date'         => date('Y-m-d H:i:s'),
                    'insert_user'         => session('user_uuid'),
                ]);
                $agent->save();
            } else {
                $agent->fill([
                    'agent_name'    => $inputs['agent_name'],
                    'system_prompt' => $inputs['system_prompt'] ?? $agent->system_prompt,
                    'first_message' => $inputs['first_message'] ?? $agent->first_message,
                    'voice_id'      => $inputs['voice_id'] ?? $agent->voice_id,
                    'language'      => $inputs['language'] ?? $agent->language ?? 'en',
                    'agent_enabled' => $inputs['agent_enabled'],
                    'tools_enabled' => $inputs['tools_enabled'] ?? $agent->tools_enabled,
                    'feature_code'  => $inputs['feature_code'],
                    'update_date'   => date('Y-m-d H:i:s'),
                    'update_user'   => session('user_uuid'),
                ]);
                $agent->save();

                if ($agent->elevenlabs_agent_id) {
                    $convai->updateAgent(
                        $agent->elevenlabs_agent_id,
                        $agent->agent_name,
                        $agent->system_prompt,
                        $agent->first_message,
                        $agent->voice_id,
                        $agent->language ?? 'en',
                    );
                }
            }

            // Always sync the tool surface; tool toggles can change between saves.
            if ($agent->elevenlabs_agent_id) {
                $convai->syncReceptionAgentTools($agent);
            }

            $this->generateFeatureCodeDialPlan($agent);

            DB::commit();

            return response()->json(['agent' => $agent->fresh()]);
        } catch (Throwable $e) {
            DB::rollBack();
            logger()->error('reception agent update failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function testInvoke(Request $request): JsonResponse
    {
        if (!userCheckPermission('reception_agent_test')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $request->validate(['extension' => 'required|string|max:32']);
        // Test flow: originate the agent leg directly to the requested extension.
        // Implementation deferred to a follow-up — initial validation lives here.
        return response()->json([
            'ok'      => true,
            'message' => 'Test invoke endpoint reachable; originate path TBD.',
        ]);
    }

    private function allocateAgentExtension(string $domainUuid): string
    {
        // Scan for an unused 925x extension within the existing AI-agent range.
        $existing = AiAgent::where('domain_uuid', $domainUuid)
            ->pluck('agent_extension')
            ->map(fn($v) => (string) $v)
            ->all();
        for ($n = 9250; $n <= 9299; $n++) {
            if (!in_array((string) $n, $existing, true)) {
                return (string) $n;
            }
        }
        throw new \RuntimeException('No agent extensions available in 9250-9299');
    }

    private function generateFeatureCodeDialPlan(AiAgent $agent): void
    {
        $featureCode = $agent->feature_code ?: self::DEFAULT_FEATURE_CODE;
        // Dialplan match expression — strip the leading * from the feature code
        // since we anchor the literal in the template (e.g. "*9" → "\*9").
        $expression = preg_replace('/[^\w]/', '\\\\$0', $featureCode);

        $xml = trim(view('layouts.xml.reception-agent-feature-code-template', [
            'agent'             => $agent,
            'feature_code'      => $expression,
            'dialplan_continue' => 'false',
        ])->render());

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        $dom->formatOutput = true;
        $xml = $dom->saveXML($dom->documentElement);

        $dialPlan = Dialplans::where('dialplan_uuid', $agent->dialplan_uuid)->first();

        if (!$dialPlan) {
            $dialPlan = new Dialplans();
            $dialPlan->dialplan_uuid    = $agent->dialplan_uuid;
            $dialPlan->app_uuid         = 'b2c48e1a-7f3d-4a1e-9c5b-8d6e7f1a2b3c';
            $dialPlan->domain_uuid      = $agent->domain_uuid;
            $dialPlan->dialplan_context = session('domain_name');
            $dialPlan->dialplan_name    = $agent->agent_name;
            $dialPlan->dialplan_number  = $featureCode;
            $dialPlan->dialplan_continue = 'false';
            $dialPlan->dialplan_xml     = $xml;
            $dialPlan->dialplan_order   = 102;
            $dialPlan->dialplan_enabled = $agent->agent_enabled;
            $dialPlan->dialplan_description = 'Reception Agent feature code';
            $dialPlan->insert_date      = date('Y-m-d H:i:s');
            $dialPlan->insert_user      = session('user_uuid');
        } else {
            $dialPlan->dialplan_xml     = $xml;
            $dialPlan->dialplan_name    = $agent->agent_name;
            $dialPlan->dialplan_number  = $featureCode;
            $dialPlan->dialplan_enabled = $agent->agent_enabled;
            $dialPlan->update_date      = date('Y-m-d H:i:s');
            $dialPlan->update_user      = session('user_uuid');
        }

        $dialPlan->save();

        FusionCache::clear('dialplan.' . session('domain_name'));
    }
}
