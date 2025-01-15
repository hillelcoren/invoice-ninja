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

namespace App\Http\Controllers\Bank;

use App\Helpers\Bank\Nordigen\Nordigen;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Nordigen\ConfirmNordigenBankIntegrationRequest;
use App\Http\Requests\Nordigen\ConnectNordigenBankIntegrationRequest;
use App\Jobs\Bank\ProcessBankTransactionsNordigen;
use App\Models\BankIntegration;
use App\Models\Company;
use App\Utils\Ninja;
use Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nordigen\NordigenPHP\Exceptions\NordigenExceptions\NordigenException;

class NordigenController extends BaseController
{
    /**
     * Handles the initial bank connection flow
     */
    public function connect(ConnectNordigenBankIntegrationRequest $request): View|RedirectResponse
    {
        $data = $request->all();
        $context = $request->getTokenContent();

        if (!$context) {
            return $this->failed('token-invalid', ['lang' => 'en']);
        }

        $company = $request->getCompany();
        $context['redirect'] = $data['redirect'];
        $context['lang'] = $lang = substr($company->locale(), 0, 2);

        if ($context['context'] != 'nordigen' || array_key_exists('requisitionId', $context)) {
            return $this->failed('token-invalid', $context);
        }

        if (!(config('ninja.nordigen.secret_id') && config('ninja.nordigen.secret_key'))) {
            return $this->failed('account-config-invalid', $context, $company);
        }

        if (!(Ninja::isSelfHost() || (Ninja::isHosted() && $company->account->isEnterprisePaidClient()))) {
            return $this->failed('not-available', $context, $company);
        }

        $nordigen = new Nordigen();
        $institutions = $nordigen->getInstitutions();

        // show bank_selection_screen, when institution_id is not present
        if (!isset($data['institution_id'], $data['tx_days'])) {
            return view('bank.nordigen.handler', [
                'lang' => $lang,
                'company' => $company,
                'account' => $company->account,
                'institutions' => $institutions,
                'institutionId' => $data['institution_id'] ?? null,
                'redirectUrl' => $context['redirect'] . '?action=nordigen_connect&status=user-aborted'
            ]);
        }

        $institution = array_values(array_filter($institutions, function ($institution) use ($data) {
            return $institution['id'] == $data['institution_id'];
        }))[0];

        try {
            $txDays = $data['tx_days'] ?? 0;

            $agreement = $nordigen->firstValidAgreement($institution['id'], $data['access_days'] ?? 0, $txDays)
                      ?? $nordigen->createAgreement($institution, $data['access_days'] ?? 9999, $txDays);
        } catch (\Exception $e) {
            $debug = "{$e->getMessage()} ({$e->getCode()})";

            nlog("Nordigen: Could not create an agreement with ${institution['name']}: {$debug}");

            return $this->failed('eua-failure', $context, $company);
        }

        // redirect to requisition flow
        try {
            $requisition = $nordigen->createRequisition(
                config('ninja.app_url') . '/nordigen/confirm',
                $institution,
                $agreement,
                $request->token,
                $lang,
            );
        } catch (NordigenException $e) { // TODO: property_exists returns null in these cases... => why => therefore we just get unknown error everytime $responseBody is typeof GuzzleHttp\Psr7\Stream
            $responseBody = (string) $e->getResponse()->getBody();

            if (str_contains($responseBody, '"institution_id"')) {
                return $this->failed('institution-invalid', $context, $company);
            }

            // Reference invalid or already used, try a new token
            if (str_contains($responseBody, '"reference"')) {
                return $this->failed('token-invalid', $context, $company);
            }

            nlog("Unknown Error from nordigen: {$e}");
            nlog($responseBody);

            return $this->failed('unknown', $context, $company);
        }

        // save cache
        $context['requisitionId'] = $requisition['id'];
        Cache::put($request->token, $context, 3600);

        return response()->redirectTo($requisition['link']);
    }

    /**
     * Handles the OAuth redirect and account setup after bank authentication
     */
    public function confirm(ConfirmNordigenBankIntegrationRequest $request): View|RedirectResponse
    {
        $data = $request->all();
        $company = $request->getCompany();
        $lang = substr($company->locale(), 0, 2);

        /** @var array $context */
        $context = $request->getTokenContent();
        if (!array_key_exists('lang', $data) && $context['lang'] != 'en') {
            return redirect()->route('nordigen.confirm', array_merge(['lang' => $context['lang']], $request->query()));
        }

        if (!$context || $context['context'] != 'nordigen' || !array_key_exists('requisitionId', $context)) {
            return $this->failed('ref-invalid', $context);
        }

        if (!config('ninja.nordigen.secret_id') || !config('ninja.nordigen.secret_key')) {
            return $this->failed('account-config-invalid', $context, $company);
        }

        if (!(Ninja::isSelfHost() || (Ninja::isHosted() && $company->account->isEnterprisePaidClient()))) {
            return $this->failed('not-available', $context, $company);
        }

        // fetch requisition
        $nordigen = new Nordigen();
        $requisition = $nordigen->getRequisition($context['requisitionId']);

        // check validity of requisition
        if (!$requisition) {
            return $this->failed('requisition-not-found', $context, $company);
        }
        if ($requisition['status'] != 'LN') {
            return $this->failed('requisition-invalid-status&status=' . $requisition['status'], $context, $company);
        }
        if (sizeof($requisition['accounts']) == 0) {
            return $this->failed('requisition-no-accounts', $context, $company);
        }

        // connect new accounts
        $bank_integration_ids = [];
        foreach ($requisition['accounts'] as $nordigenAccountId) {
            $nordigen_account = $nordigen->getAccount($nordigenAccountId);

            if (isset($nordigen_account['error'])) {
                continue;
            }

            try {
                $bank_integration = $this->findIntegrationBy('account', $nordigen_account, $company);

                $bank_integration->deleted_at = null;
            } catch (ModelNotFoundException $e) {
                $bank_integration = new BankIntegration();

                $bank_integration->integration_type = BankIntegration::INTEGRATION_TYPE_NORDIGEN;
                $bank_integration->company_id = $company->id;
                $bank_integration->account_id = $company->account_id;
                $bank_integration->user_id = $company->owner()->id;
                $bank_integration->nordigen_account_id = $nordigen_account['id'];
                $bank_integration->bank_account_type = $nordigen_account['account_type'];
                $bank_integration->bank_account_name = $nordigen_account['account_name'];
                $bank_integration->bank_account_number = $nordigen_account['account_number'];
                $bank_integration->nordigen_institution_id = $nordigen_account['provider_id'];
                $bank_integration->provider_name = $nordigen_account['provider_name'];
                $bank_integration->nickname = $nordigen_account['nickname'];
                $bank_integration->currency = $nordigen_account['account_currency'];
            } finally {
                $bank_integration->auto_sync = true;
                $bank_integration->disabled_upstream = false;
                $bank_integration->balance = $nordigen_account['current_balance'];
                $bank_integration->bank_account_status = $nordigen_account['account_status'];
                $bank_integration->from_date = now()->subDays($nordigen_account['provider_history']);

                $bank_integration->save();

                array_push($bank_integration_ids, $bank_integration->id);
            }
        }

        // perform update in background
        $company->account->bank_integrations
            ->where('integration_type', BankIntegration::INTEGRATION_TYPE_NORDIGEN)
            ->where('auto_sync', true)
            ->each(function ($bank_integration) {
                ProcessBankTransactionsNordigen::dispatch($bank_integration);
            });

        // prevent rerun of this method with same ref
        Cache::delete($data['ref']);

        // Successfull Response => Redirect
        return response()->redirectTo($context['redirect'] . '?action=nordigen_connect&status=success&bank_integrations=' . implode(',', $bank_integration_ids));
    }

    /**
     * Handles failure scenarios for Nordigen bank integrations
     *
     * @param array{lang: string, redirect?: string}|null $context
     * @param array{account: array}|null $company
     */
    private function failed(string $reason, array $context, $company = null): View
    {
        $companyData = $company ? [
            'company' => $company,
            'account' => $company->account,
        ] : [];

        $url = $context['redirect'] ?? config('ninja.app_url');

        return view('bank.nordigen.handler', [
            ...$companyData,
            'lang' => $context['lang'],
            'failed_reason' => explode('&', $reason)[0],
            'redirectUrl' => $url . '?action=nordigen_connect&status=failed&reason=' . $reason,
        ]);
    }

    /**
     * Find the first available Bank Integration from its Nordigen account or institution.
     *
     * @param 'account'|'institution' $key
     * @param array{id: string} $accountOrInstitution
     */
    private function findIntegrationBy(
        string $key,
        array $accountOrInstitution,
        Company $company,
    ): BankIntegration {
        return BankIntegration::withTrashed()
            ->where($key == 'id' ? 'id' : "nordigen_{$key}_id", $accountOrInstitution['id'])
            ->where('company_id', $company->id)
            ->where('is_deleted', 0)
            ->firstOrFail();
    }

    /**
     * Returns list of available banking institutions from Nordigen
     *
     * @OA\Post(
     *      path="/api/v1/nordigen/institutions",
     *      operationId="nordigenRefreshWebhook",
     *      tags={"nordigen"},
     *      summary="Getting available institutions from nordigen",
     *      description="Used to determine the available institutions for sending and creating a new connect-link",
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Token"),
     *      @OA\Parameter(ref="#/components/parameters/X-Requested-With"),
     *      @OA\Parameter(ref="#/components/parameters/include"),
     *      @OA\Response(
     *          response=200,
     *          description="",
     *          @OA\Header(header="X-MINIMUM-CLIENT-VERSION", ref="#/components/headers/X-MINIMUM-CLIENT-VERSION"),
     *          @OA\Header(header="X-RateLimit-Remaining", ref="#/components/headers/X-RateLimit-Remaining"),
     *          @OA\Header(header="X-RateLimit-Limit", ref="#/components/headers/X-RateLimit-Limit"),
     *          @OA\JsonContent(ref="#/components/schemas/Credit"),
     *       ),
     *       @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(ref="#/components/schemas/ValidationError"),
     *
     *       ),
     *       @OA\Response(
     *           response="default",
     *           description="Unexpected Error",
     *           @OA\JsonContent(ref="#/components/schemas/Error"),
     *       ),
     *     )
     */
    public function institutions(Request $request): JsonResponse
    {
        if (!(config('ninja.nordigen.secret_id') && config('ninja.nordigen.secret_key'))) {
            return response()->json(['message' => 'Not yet authenticated with Nordigen Bank Integration service'], 400);
        }

        $nordigen = new Nordigen();

        return response()->json($nordigen->getInstitutions());
    }

}
