<?php

namespace Tests\Unit\Voxra;

use App\Http\Requests\StorePhoneNumberRequest;
use App\Http\Requests\UpdatePhoneNumberRequest;
use App\Models\Destinations;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

/**
 * voxragtm#259: editing a number under Phone Numbers said "saved" but the
 * Country Code and Phone Number came back unchanged, because the web
 * UpdatePhoneNumberRequest had no rules for them and validated() dropped
 * them. Descriptions with "&" also grew an extra "amp;" on every save.
 */
class PhoneNumberEditSaveTest extends TestCase
{
    private const FIELDS = ['destination_number', 'destination_prefix', 'destination_description'];

    private function updateRequest(array $input, ?Destinations $bound = null): UpdatePhoneNumberRequest
    {
        $req = UpdatePhoneNumberRequest::create('/api/phone-numbers/x', 'PUT', $input + [
            'domain_uuid' => '2deaadef-3b95-4b1b-b1c8-ac66eb1c6710',
        ]);

        if ($bound) {
            $route = new Route('PUT', 'api/phone-numbers/{phone_number}', []);
            $route->bind($req);
            $route->setParameter('phone_number', $bound);
            $req->setRouteResolver(fn () => $route);
        }

        $req->prepareForValidation();

        return $req;
    }

    /** Validate with the rules for the fields under test only (the others need the DB). */
    private function validator(UpdatePhoneNumberRequest $req): \Illuminate\Validation\Validator
    {
        return Validator::make($req->all(), array_intersect_key($req->rules(), array_flip(self::FIELDS)));
    }

    private function destination(string $number, ?string $prefix = '44'): Destinations
    {
        $d = new Destinations();
        $d->setRawAttributes([
            'destination_uuid' => 'ad285942-1416-4ab6-b49e-4666b8b393dc',
            'destination_number' => $number,
            'destination_prefix' => $prefix,
        ], true);

        return $d;
    }

    public function test_number_and_country_code_reach_validated_data(): void
    {
        $v = $this->validator($this->updateRequest([
            'destination_prefix' => '44',
            'destination_number' => '7435845636',
        ]));

        $this->assertFalse($v->fails(), json_encode($v->errors()->all()));
        $this->assertSame('7435845636', $v->validated()['destination_number']);
        $this->assertSame('44', $v->validated()['destination_prefix']);
    }

    public function test_number_and_country_code_are_normalised(): void
    {
        $req = $this->updateRequest([
            'destination_prefix' => '+44',
            'destination_number' => '+44 (0) 7435-845.636',
        ]);

        $this->assertSame('44', $req->input('destination_prefix'));
        $this->assertSame('+4407435845636', $req->input('destination_number'));
        $this->assertFalse($this->validator($req)->fails());
    }

    public function test_blank_country_code_becomes_null(): void
    {
        $req = $this->updateRequest(['destination_prefix' => '', 'destination_number' => '+447396054197']);

        $this->assertNull($req->input('destination_prefix'));
        $this->assertFalse($this->validator($req)->fails());
    }

    public function test_bad_or_blank_number_is_rejected(): void
    {
        $this->assertTrue($this->validator($this->updateRequest(['destination_number' => '74358abc']))->fails());
        $this->assertTrue($this->validator($this->updateRequest(['destination_number' => '']))->fails());
    }

    public function test_number_is_optional(): void
    {
        $v = $this->validator($this->updateRequest(['destination_description' => 'Main line']));

        $this->assertFalse($v->fails());
        $this->assertArrayNotHasKey('destination_number', $v->validated());
    }

    public function test_unique_check_only_when_the_number_changes(): void
    {
        $unchanged = $this->updateRequest(['destination_number' => '7435845636'], $this->destination('7435845636'));
        $this->assertEmpty(array_filter($unchanged->rules()['destination_number'], fn ($r) => $r instanceof Unique));

        $changed = $this->updateRequest(['destination_number' => '7435845637'], $this->destination('7435845636'));
        $this->assertCount(1, array_filter($changed->rules()['destination_number'], fn ($r) => $r instanceof Unique));
    }

    public function test_description_is_stored_as_plain_text(): void
    {
        $this->assertSame('Smith & Sons', $this->updateRequest(['destination_description' => 'Smith & Sons'])->input('destination_description'));
        // Heals values escaped by earlier saves.
        $this->assertSame('Smith & Sons', $this->updateRequest(['destination_description' => 'Smith &amp; Sons'])->input('destination_description'));
        $this->assertSame("O'Brien", $this->updateRequest(['destination_description' => 'O&#039;Brien'])->input('destination_description'));
        $this->assertSame('Post-port alert(1)', $this->updateRequest(['destination_description' => 'Post-port <script>alert(1)</script>'])->input('destination_description'));
    }

    public function test_store_description_is_stored_as_plain_text(): void
    {
        $req = StorePhoneNumberRequest::create('/api/phone-numbers', 'POST', [
            'domain_uuid' => '2deaadef-3b95-4b1b-b1c8-ac66eb1c6710',
            'destination_description' => 'Smith & Sons',
        ]);
        $req->prepareForValidation();

        $this->assertSame('Smith & Sons', $req->input('destination_description'));
    }
}
