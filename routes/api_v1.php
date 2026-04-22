<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\RingGroupController;
use App\Http\Controllers\Api\V1\VoicemailController;
use App\Http\Controllers\Api\V1\PhoneNumberController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\AiAgentController;
use App\Http\Controllers\Api\V1\CdrCallController;
use App\Http\Controllers\Api\V1\CdrStatsController;
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

        Route::middleware('throttle:cdr-stats')->group(function () {
            Route::get('/domains/{domain_uuid}/cdr/stats/summary', [CdrStatsController::class, 'summary'])
                ->name('api.v1.cdr.stats.summary');

            Route::get('/domains/{domain_uuid}/cdr/stats/by-direction', [CdrStatsController::class, 'byDirection'])
                ->name('api.v1.cdr.stats.by-direction');

            Route::get('/domains/{domain_uuid}/cdr/stats/by-hangup-cause', [CdrStatsController::class, 'byHangupCause'])
                ->name('api.v1.cdr.stats.by-hangup-cause');
        });
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
});
