<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2024. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Requests\Nordigen;

use App\Http\Requests\Request;
use App\Libraries\MultiDB;
use App\Models\Company;
use Cache;

class ConfirmNordigenBankIntegrationRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ref' => 'required|string', // nordigen redirects only with the ref-property
            'lang' => 'string',
        ];
    }

    /**
     * @return array{
     *   user_id: int,
     *   company_key: string,
     *   context: string,
     *   is_react: bool,
     *   institution_id: string,
     *   lang: string,
     *   redirect: string,
     *   requisitionId: string
     * }
     */
    public function getTokenContent(): array
    {
        $input = $this->all();

        $data = Cache::get($input['ref']);

        return $data;
    }

    public function getCompany(): Company
    {
        $key = $this->getTokenContent()['company_key'];

        MultiDB::findAndSetDbByCompanyKey($key);

        return Company::where('company_key', $key)->firstOrFail();
    }
}
