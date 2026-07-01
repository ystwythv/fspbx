<?php

namespace Tests\Feature\Api\V1\Reception;

use Tests\TestCase;

/** Auth-chain smoke test for the reception contacts read API (voxragtm#96). */
class ReceptionContactsRouteProtectionTest extends TestCase
{
    private const DOMAIN = '00000000-0000-0000-0000-000000000001';

    public function test_contacts_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/reception/contacts')
            ->assertStatus(401);
    }

    public function test_contact_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/reception/contacts/00000000-0000-0000-0000-000000000002')
            ->assertStatus(401);
    }
}
