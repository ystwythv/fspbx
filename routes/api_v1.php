<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\RingGroupController;
use App\Http\Controllers\Api\V1\VoicemailController;
use App\Http\Controllers\Api\V1\PhoneNumberController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\AiAgentController;
use App\Http\Controllers\Api\V1\CallFlowSimulationController;
use App\Http\Controllers\Api\V1\CdrCallController;
use App\Http\Controllers\Api\V1\CdrStatsController;
use App\Http\Controllers\Api\V1\TenantApiTokenController;
use App\Http\Controllers\Api\V1\Admin\ApiTokenController;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
*/

Route::middleware(['auth:sanctum', 'api.token.auth', 'throttle:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    */

    Route::get('/domains', [DomainController::class, 'index'])
        ->middleware('user.authorize:domain_select');

    Route::get('/domains/{domain_uuid}', [DomainController::class, 'show'])
        ->middleware('user.authorize:domain_view');

    Route::post('/domains', [DomainController::class, 'store'])
        ->middleware('user.authorize:domain_add');

    Route::patch('/domains/{domain_uuid}', [DomainController::class, 'update'])
        ->middleware('user.authorize:domain_edit');

    Route::delete('/domains/{domain_uuid}', [DomainController::class, 'destroy'])
        ->middleware('user.authorize:domain_delete');

    /*
    |--------------------------------------------------------------------------
    | Extensions (domain-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/extensions', [ExtensionController::class, 'index'])
        ->middleware('user.authorize:extension_view');

    Route::get('/domains/{domain_uuid}/extensions/{extension_uuid}', [ExtensionController::class, 'show'])
        ->middleware('user.authorize:extension_view');

    Route::post('/domains/{domain_uuid}/extensions', [ExtensionController::class, 'store'])
        ->middleware('user.authorize:extension_add');

    Route::patch('/domains/{domain_uuid}/extensions/{extension_uuid}', [ExtensionController::class, 'update'])
        ->middleware('user.authorize:extension_edit');

    Route::delete('/domains/{domain_uuid}/extensions/{extension_uuid}', [ExtensionController::class, 'destroy'])
        ->middleware('user.authorize:extension_delete');

    /*
    |--------------------------------------------------------------------------
    | Voicemails (domain-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/voicemails', [VoicemailController::class, 'index'])
        ->middleware('user.authorize:voicemail_domain');

    Route::get('/domains/{domain_uuid}/voicemails/{voicemail_uuid}', [VoicemailController::class, 'show'])
        ->middleware('user.authorize:voicemail_view');

    Route::post('/domains/{domain_uuid}/voicemails', [VoicemailController::class, 'store'])
        ->middleware('user.authorize:voicemail_add');

    Route::patch('/domains/{domain_uuid}/voicemails/{voicemail_uuid}', [VoicemailController::class, 'update'])
        ->middleware('user.authorize:voicemail_edit');

    Route::delete('/domains/{domain_uuid}/voicemails/{voicemail_uuid}', [VoicemailController::class, 'destroy'])
        ->middleware('user.authorize:voicemail_delete');

    /*
    |--------------------------------------------------------------------------
    | Ring Groups (domain-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/ring-groups', [RingGroupController::class, 'index'])
        ->middleware('user.authorize:ring_group_domain');

    Route::get('/domains/{domain_uuid}/ring-groups/{ring_group_uuid}', [RingGroupController::class, 'show'])
        ->middleware('user.authorize:ring_group_view');

    Route::post('/domains/{domain_uuid}/ring-groups', [RingGroupController::class, 'store'])
        ->middleware('user.authorize:ring_group_add');

    Route::patch('/domains/{domain_uuid}/ring-groups/{ring_group_uuid}', [RingGroupController::class, 'update'])
        ->middleware('user.authorize:ring_group_edit');

    Route::delete('/domains/{domain_uuid}/ring-groups/{ring_group_uuid}', [RingGroupController::class, 'destroy'])
        ->middleware('user.authorize:ring_group_delete');

    /*
    |--------------------------------------------------------------------------
    | Phone Numbers (domain-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/phone-numbers', [PhoneNumberController::class, 'index'])
        ->middleware('user.authorize:ring_group_domain');

    Route::get('/domains/{domain_uuid}/phone-numbers/{destination_uuid}', [PhoneNumberController::class, 'show'])
        ->middleware('user.authorize:ring_group_view');

    Route::post('/domains/{domain_uuid}/phone-numbers', [PhoneNumberController::class, 'store'])
        ->middleware('user.authorize:ring_group_add');

    Route::patch('/domains/{domain_uuid}/phone-numbers/{destination_uuid}', [PhoneNumberController::class, 'update'])
        ->middleware('user.authorize:ring_group_edit');

    Route::delete('/domains/{domain_uuid}/phone-numbers/{destination_uuid}', [PhoneNumberController::class, 'destroy'])
        ->middleware('user.authorize:ring_group_delete');

    /*
    |--------------------------------------------------------------------------
    | Users (domain-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/users', [UserController::class, 'index'])
        ->middleware('user.authorize:user_view')->name('api.v1.users.index');

    Route::get('/domains/{domain_uuid}/users/{user_uuid}', [UserController::class, 'show'])
        ->middleware('user.authorize:user_view')->name('api.v1.users.show');

    Route::post('/domains/{domain_uuid}/users', [UserController::class, 'store'])
        ->middleware('user.authorize:user_add')->name('api.v1.users.store');

    Route::patch('/domains/{domain_uuid}/users/{user_uuid}', [UserController::class, 'update'])
        ->middleware('user.authorize:user_edit')->name('api.v1.users.update');

    Route::delete('/domains/{domain_uuid}/users/{user_uuid}', [UserController::class, 'destroy'])
        ->middleware('user.authorize:user_delete')->name('api.v1.users.destroy');

    /*
    |--------------------------------------------------------------------------
    | AI Agents (domain-scoped, read-only)
    |--------------------------------------------------------------------------
    */
    Route::get('/domains/{domain_uuid}/ai-agents', [AiAgentController::class, 'index'])
        ->middleware('user.authorize:ai_agent_view')->name('api.v1.ai-agents.index');

    Route::get('/domains/{domain_uuid}/ai-agents/{ai_agent_uuid}', [AiAgentController::class, 'show'])
        ->middleware('user.authorize:ai_agent_view')->name('api.v1.ai-agents.show');

    /*
    |--------------------------------------------------------------------------
    | CDR (Call Detail Records) — domain-scoped, read-only
    |--------------------------------------------------------------------------
    | All routes enforce tenant-vs-global token scope via `cdr.scope`. Stats
    | endpoints use a stricter rate limiter.
    */
    Route::middleware(['cdr.scope:tenant', 'user.authorize:cdr_api_read'])->group(function () {
        Route::get('/domains/{domain_uuid}/cdr/calls', [CdrCallController::class, 'index'])
            ->name('api.v1.cdr.calls.index');

        Route::get('/domains/{domain_uuid}/cdr/calls/{xml_cdr_uuid}', [CdrCallController::class, 'show'])
            ->name('api.v1.cdr.calls.show');

        Route::get('/domains/{domain_uuid}/cdr/calls.csv', [CdrCallController::class, 'exportCsv'])
            ->middleware('throttle:cdr-export')
            ->name('api.v1.cdr.calls.export');

        Route::middleware('throttle:cdr-stats')->group(function () {
            Route::get('/domains/{domain_uuid}/cdr/stats/summary', [CdrStatsController::class, 'summary'])
                ->name('api.v1.cdr.stats.summary');

            Route::get('/domains/{domain_uuid}/cdr/stats/by-direction', [CdrStatsController::class, 'byDirection'])
                ->name('api.v1.cdr.stats.by-direction');

            Route::get('/domains/{domain_uuid}/cdr/stats/by-hangup-cause', [CdrStatsController::class, 'byHangupCause'])
                ->name('api.v1.cdr.stats.by-hangup-cause');

            Route::get('/domains/{domain_uuid}/cdr/stats/by-extension', [CdrStatsController::class, 'byExtension'])
                ->name('api.v1.cdr.stats.by-extension');

            Route::get('/domains/{domain_uuid}/cdr/stats/timeseries', [CdrStatsController::class, 'timeseries'])
                ->name('api.v1.cdr.stats.timeseries');

            Route::get('/domains/{domain_uuid}/cdr/stats/quality', [CdrStatsController::class, 'quality'])
                ->name('api.v1.cdr.stats.quality');

            Route::get('/domains/{domain_uuid}/cdr/stats/top-destinations', [CdrStatsController::class, 'topDestinations'])
                ->name('api.v1.cdr.stats.top-destinations');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | CDR — global (cross-domain) admin endpoints
    |--------------------------------------------------------------------------
    | `cdr.scope:global` rejects tenant tokens. `cdr_api_read_all_domains`
    | permission is also required.
    */
    Route::middleware(['cdr.scope:global', 'user.authorize:cdr_api_read_all_domains'])->group(function () {
        Route::get('/cdr/calls', [CdrCallController::class, 'globalIndex'])
            ->name('api.v1.cdr.global.calls.index');

        Route::get('/cdr/calls.csv', [CdrCallController::class, 'globalExportCsv'])
            ->middleware('throttle:cdr-export')
            ->name('api.v1.cdr.global.calls.export');

        Route::middleware('throttle:cdr-stats')->group(function () {
            Route::get('/cdr/stats/summary', [CdrStatsController::class, 'globalSummary'])
                ->name('api.v1.cdr.global.stats.summary');

            Route::get('/cdr/stats/by-direction', [CdrStatsController::class, 'globalByDirection'])
                ->name('api.v1.cdr.global.stats.by-direction');

            Route::get('/cdr/stats/by-hangup-cause', [CdrStatsController::class, 'globalByHangupCause'])
                ->name('api.v1.cdr.global.stats.by-hangup-cause');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Call Flow Simulation — domain-scoped, read-only
    |--------------------------------------------------------------------------
    | Reuses the CDR scope middleware (it enforces tenant-vs-global scope on
    | any {domain_uuid}-bearing route; name is historical). Separate limiter
    | because simulation walks several tables per call.
    */
    Route::middleware(['cdr.scope:tenant', 'user.authorize:call_flow_simulate', 'throttle:call-flow-simulate'])->group(function () {
        Route::get('/domains/{domain_uuid}/call-flow/simulate', [CallFlowSimulationController::class, 'simulate'])
            ->name('api.v1.call-flow.simulate');
    });

    /*
    |--------------------------------------------------------------------------
    | Call Flow Simulation — global (cross-domain)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['cdr.scope:global', 'user.authorize:call_flow_simulate_all_domains', 'throttle:call-flow-simulate'])->group(function () {
        Route::get('/call-flow/simulate', [CallFlowSimulationController::class, 'globalSimulate'])
            ->name('api.v1.call-flow.global.simulate');
    });

    /*
    |--------------------------------------------------------------------------
    | Admin — API token management
    |--------------------------------------------------------------------------
    | Tokens are minted by an internal admin. Tenant self-service is deferred.
    */
    Route::middleware('user.authorize:api_token_manage')->group(function () {
        Route::get('/admin/api-tokens', [ApiTokenController::class, 'index'])
            ->name('api.v1.admin.api-tokens.index');

        Route::post('/admin/api-tokens', [ApiTokenController::class, 'store'])
            ->name('api.v1.admin.api-tokens.store');

        Route::delete('/admin/api-tokens/{token_id}', [ApiTokenController::class, 'destroy'])
            ->name('api.v1.admin.api-tokens.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Tenant self-service API tokens
    |--------------------------------------------------------------------------
    | Tenant users can mint / list / revoke tokens for their own domain. The
    | `cdr.scope:tenant` middleware locks a tenant token to the URL's
    | {domain_uuid}. `api_token_self_manage` gates who on that domain can do it.
    */
    Route::middleware(['cdr.scope:tenant', 'user.authorize:api_token_self_manage'])->group(function () {
        Route::get('/domains/{domain_uuid}/api-tokens', [TenantApiTokenController::class, 'index'])
            ->name('api.v1.tenant.api-tokens.index');

        Route::post('/domains/{domain_uuid}/api-tokens', [TenantApiTokenController::class, 'store'])
            ->name('api.v1.tenant.api-tokens.store');

        Route::delete('/domains/{domain_uuid}/api-tokens/{token_id}', [TenantApiTokenController::class, 'destroy'])
            ->name('api.v1.tenant.api-tokens.destroy');
    });
});
