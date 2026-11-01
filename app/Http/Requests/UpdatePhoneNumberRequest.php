<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use App\Models\Destinations;
use Illuminate\Validation\Rule;
use libphonenumber\NumberParseException;
use Propaganistas\LaravelPhone\PhoneNumber;

class UpdatePhoneNumberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        // voxragtm#259: the edit form shows Country Code and Phone Number as
        // editable fields and always sends them, but these rules used to be
        // commented out, so validated() dropped them and the save silently
        // kept the old number while the UI said it was saved. The upstream
        // unique rule ignored $this->get('destination_uuid'), which the form
        // never sends, so it would have failed on every save; that is
        // probably why it was commented out. The record comes from the
        // {phone_number} route binding instead, and the unique check only
        // runs when the number really changes, so an existing duplicate
        // elsewhere never blocks saving other fields.
        $current = $this->route('phone_number');
        $numberRules = ['sometimes', 'required', 'string', 'regex:/^\+?\d+$/'];
        if ($current instanceof Destinations
            && (string) $this->input('destination_number') !== (string) $current->destination_number) {
            $numberRules[] = Rule::unique('v_destinations', 'destination_number')
                ->ignore($current->destination_uuid, 'destination_uuid');
        }

        return [
            'destination_number' => $numberRules,
            'destination_prefix' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^\d{1,5}$/',
            ],
            'destination_accountcode' => [
                'nullable',
                'string',
            ],
            'destination_conditions' => [
                'nullable',
                'array',
            ],
            'destination_conditions.*.condition_field' => [
                'nullable',
                Rule::in('caller_id_number')
            ],
            'destination_conditions.*.condition_expression' => [
                'required_if:destination_conditions.*.condition_field,!=,""',
            ],
            'destination_conditions.*.condition_target.targetValue' => [
                'required_if:destination_conditions.*.condition_field,!=,""',
                'string',
            ],
            'destination_cid_name_prefix' => [
                'nullable',
                'string',
            ],
            'destination_caller_id_name' => [
                'nullable',
                'string',
            ],
            'destination_hold_music' => [
                'nullable',
                'string',
            ],
            'destination_description' => [
                'nullable',
                'string',
            ],
            'destination_distinctive_ring' => [
                'nullable',
                'string',
            ],
            'fax_uuid' => [
                'nullable',
                Rule::exists('v_fax', 'fax_uuid')
            ],
            'destination_enabled' => [
                'nullable',
                'string',
            ],
            'destination_record' => [
                'nullable',
                'string',
            ],
            'domain_uuid' => [
                'required',
                Rule::exists('v_domains', 'domain_uuid')
            ],
            'destination_context' => [
                'nullable',
                'string',
            ],
            'routing_options' => [
                'nullable',
                'array',
            ],
            'hold_music' => ['nullable', 'string'],
            'destination_type_fax' => 'present',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function failedValidation(Validator $validator): void
    {
        // Get the original error messages from the validator
        $errors = $validator->errors()->toArray();
        $customMessages = [];
        foreach ($errors as $field => $message) {
            if (preg_match('/destination_conditions\.(\d+)\.condition_expression/', $field, $matches)) {
                $index = (int) $matches[1]; // Add 1 to make it 1-indexed
                $customMessages[$field][] = "Please use valid US phone number on condition " . ($index + 1);
            }
            if (preg_match('/destination_conditions\.(\d+)\.condition_target.targetValue/', $field, $matches)) {
                $index = (int) $matches[1]; // Add 1 to make it 1-indexed
                $customMessages[$field][] = "Please select action on condition " . ($index + 1);
            }
        }

        $errors = array_merge($errors, $customMessages);

        $responseData = array('errors' => $errors);

        throw new HttpResponseException(response()->json($responseData, 422));
    }

    public function messages(): array
    {
        return [
            'destination_conditions.*.condition_expression' => 'Please use valid US phone number on condition',
            'destination_conditions.*.condition_target.targetValue' => 'Please select action on condition',
            'domain_uuid.required' => 'Acccount must be selected.'
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('destination_conditions')) {
            $destinationConditions = [];
            foreach ($this->get('destination_conditions') as $condition) {
                try {
                    $condition['condition_expression'] = (new PhoneNumber($condition['condition_expression'], "US"))->formatE164();
                } catch (NumberParseException $e) {
                    //
                }
                $condition['condition_expression'] = str_replace('+1', '', $condition['condition_expression']);
                $destinationConditions[] = $condition;
            }
            $this->merge(['destination_conditions' => $destinationConditions]);
        }

        if ($this->has('destination_type_fax')) {
            $this->merge([
                'destination_type_fax' => $this->destination_type_fax =='1' ? 1 : null,
            ]);
        }

        if (!$this->has('domain_uuid')) {
            $this->merge(['domain_uuid' => session('domain_uuid')]);
        }

        // Phone number and country code: drop spaces, dashes, dots and
        // brackets people type or paste ("+44 7435 845636"). The country
        // code is digits only, because the destination_number_regex built
        // from it would break on a "+".
        if ($this->has('destination_number') && $this->destination_number !== null) {
            $this->merge([
                'destination_number' => preg_replace('/[\s\-\.\(\)]+/', '', (string) $this->destination_number),
            ]);
        }
        if ($this->has('destination_prefix') && $this->destination_prefix !== null) {
            $prefix = preg_replace('/\D+/', '', (string) $this->destination_prefix);
            $this->merge(['destination_prefix' => $prefix === '' ? null : $prefix]);
        }

        // Sanitize description
        if ($this->has('destination_description') && $this->destination_description) {
            $sanitizedDescription = $this->sanitizeInput($this->destination_description);
            $this->merge(['destination_description' => $sanitizedDescription]);
        }
    }

    /**
     * Sanitize the input field to prevent XSS and remove unwanted characters.
     *
     * @param string $input
     * @return string
     */
    protected function sanitizeInput(string $input): string
    {
        // Trim whitespace
        $input = trim($input);

        // Undo HTML escaping from earlier saves ("&amp;" -> "&") before
        // stripping tags. The value is stored as plain text: Vue and the
        // FusionPBX pages escape it on output. Escaping it here too showed
        // "&amp;" in the form and added another "amp;" on every save.
        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip HTML tags
        $input = strip_tags($input);

        // Remove any non-ASCII characters if necessary (optional)
        $input = preg_replace('/[^\x20-\x7E]/', '', $input);

        return $input;
    }
}
