<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2024. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 *
 * Documentation of Api-Usage: https://developer.gocardless.com/bank-account-data/overview
 *
 * Institutions: Are Banks or Payment-Providers, which manages bankaccounts.
 *
 * Accounts: Accounts are existing bank_accounts at a specific institution.
 *
 * Requisitions: Are registered/active user-flows to authenticate one or many accounts. After completition, the accoundId could be used to fetch data for this account. After the access expires, the user could create a new requisition to connect accounts again.
 */

namespace App\Helpers\Bank\Nordigen;

use App\Models\Company;
use App\Services\Email\Email;
use App\Models\BankIntegration;
use App\Services\Email\EmailObject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Mail\Mailables\Address;
use App\Helpers\Bank\Nordigen\Transformer\AccountTransformer;
use App\Helpers\Bank\Nordigen\Transformer\TransactionTransformer;

class Nordigen
{
    public bool $test_mode; // https://developer.gocardless.com/bank-account-data/sandbox

    public string $sandbox_institutionId = 'SANDBOXFINANCE_SFIN0000';

    protected \Nordigen\NordigenPHP\API\NordigenClient $client;

    public function __construct()
    {
        $this->test_mode = config('ninja.nordigen.test_mode');

        if (!(config('ninja.nordigen.secret_id') && config('ninja.nordigen.secret_key'))) {
            throw new \Exception('missing nordigen credentials');
        }

        $this->client = new \Nordigen\NordigenPHP\API\NordigenClient(config('ninja.nordigen.secret_id'), config('ninja.nordigen.secret_key'));

        $this->client->createAccessToken();
    }

    // metadata-section for frontend
    public function getInstitutions()
    {
        if ($this->test_mode) {
            return [$this->client->institution->getInstitution($this->sandbox_institutionId)];
        }

        return $this->client->institution->getInstitutions();
    }

    /**
     * Get end user agreement details by ID.
     *
     * @return array{
     *   id: string,
     *   created: string,
     *   institution_id: string,
     *   max_historical_days: int,
     *   access_valid_for_days: int,
     *   access_scope: string[],
     *   accepted: string
     * } Agreement details
     */
    public function getAgreement(string $euaId): array
    {
        return $this->client->endUserAgreement->getEndUserAgreement($euaId);
    }

    /**
     * Get a list of end user agreements
     *
     * @return array{
     *   id: string,
     *   created: string,
     *   institution_id: string,
     *   max_historical_days: int,
     *   access_valid_for_days: int,
     *   access_scope: string[],
     *   accepted: ?string,
     * }[] EndUserAgreement list
     */
    public function firstValidAgreement(string $institutionId, int $accessDays, int $txDays): ?array
    {
        $requiredScopes = ['balances', 'details', 'transactions'];

        try {
            return Arr::first(
                $this->client->endUserAgreement->getEndUserAgreements()['results'],
                function (array $eua) use ($institutionId, $requiredScopes, $accessDays, $txDays): bool {
                    return $eua['institution_id'] === $institutionId
                        && $eua['accepted'] === null
                        && $eua['max_historical_days'] >= $txDays
                        && $eua['access_valid_for_days'] >= $accessDays
                        && !array_diff($requiredScopes, $eua['access_scope'] ?? []);
                },
                null
            );
        } catch (\Exception $e) {
            $debug = "{$e->getMessage()} ({$e->getCode()})";

            nlog("Nordigen: Unable to fetch End User Agreements for institution '{$institutionId}': {$debug}");

            return null;
        }
    }

    /**
     * Create a new End User Agreement with the given parameters
     *
     * @param array{id: string, transaction_total_days: int} $institution
     *
     * @throws \Nordigen\NordigenPHP\Exceptions\NordigenExceptions\NordigenException
     *
     * @return array{
     *   id: string,
     *   created: string,
     *   institution_id: string,
     *   max_historical_days: int,
     *   access_valid_for_days: int,
     *   access_scope: string[],
     *   accepted: string
     * }|null Agreement details
     */
    public function createAgreement(array $institution, int $accessDays, int $transactionDays): array
    {
        $txDays = $transactionDays < 30 ? 30 : $transactionDays;
        $maxAccess = $institution['max_access_valid_for_days'];
        $maxTx = $institution['transaction_total_days'];

        return $this->client->endUserAgreement->createEndUserAgreement(
            accessValidForDays: $accessDays > $maxAccess ? $maxAccess : $accessDays,
            maxHistoricalDays: $txDays > $maxTx ? $maxTx : $txDays,
            institutionId: $institution['id'],
        );
    }

    /**
     * Create a new Bank Requisition
     *
     * @param array{id: string} $institution,
     * @param array{id: string, transaction_total_days: int} $agreement
     */
    public function createRequisition(
        string $redirect,
        array $institution,
        array $agreement,
        string $reference,
        string $userLanguage,
    ): array {
        if ($this->test_mode && $institution['id'] != $this->sandbox_institutionId) {
            throw new \Exception('invalid institutionId while in test-mode');
        }

        return $this->client->requisition->createRequisition(
            $redirect,
            $institution['id'],
            $agreement['id'] ?? null,
            $reference,
            $userLanguage
        );
    }

    public function getRequisition(string $requisitionId)
    {
        try {
            return $this->client->requisition->getRequisition($requisitionId);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Invalid Requisition ID') !== false) {
                return false;
            }

            throw $e;
        }
    }

    // TODO: return null on not found
    public function getAccount(string $account_id)
    {
        try {
            $out = new \stdClass();

            $out->data = $this->client->account($account_id)->getAccountDetails()['account'];
            $out->metadata = $this->client->account($account_id)->getAccountMetaData();
            $out->balances = $this->client->account($account_id)->getAccountBalances()['balances'];
            $out->institution = $this->client->institution->getInstitution($out->metadata['institution_id']);

            $it = new AccountTransformer();
            return $it->transform($out);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                nlog("Nordigen Rate Limit hit for account {$account_id}");
                return ['error' => 'Nordigen Institution Rate Limit Reached'];
            }
        } catch (\Exception $e) {

            nlog("Nordigen getAccount() failed => {$account_id} => " . $e->getMessage());
            return ['error' => $e->getMessage(), 'requisition' => true];

        }
    }

    /**
     * isAccountActive
     *
     * @param  string $account_id
     * @return bool
     */
    public function isAccountActive(string $account_id): bool
    {
        try {
            $account = $this->client->account($account_id)->getAccountMetaData();

            if ($account['status'] != 'READY') {
                nlog("Nordigen account '{$account_id}' is not ready (status={$account['status']})");

                return false;
            }

            return true;
        } catch (\Exception $e) {

            nlog("Nordigen:: AccountActiveStatus:: {$e->getMessage()} {$e->getCode()}");

            if (strpos($e->getMessage(), 'Invalid Account ID') !== false) {
                return false;
            }

            throw $e;
        }
    }


    /**
     * getTransactions
     *
     * @param  string $accountId
     * @param  string $dateFrom
     * @return array
     */
    public function getTransactions(Company $company, string $accountId, string $dateFrom = null): array
    {
        $transactionResponse = $this->client->account($accountId)->getAccountTransactions($dateFrom);

        $it = new TransactionTransformer($company);
        return $it->transform($transactionResponse);
    }

    public function disabledAccountEmail(BankIntegration $bank_integration): void
    {
        $cache_key = "email_quota:{$bank_integration->company->company_key}:{$bank_integration->id}";

        if (Cache::has($cache_key)) {
            return;
        }

        App::setLocale($bank_integration->company->getLocale());

        $mo = new EmailObject();
        $mo->subject = ctrans('texts.nordigen_requisition_subject');
        $mo->body = ctrans('texts.nordigen_requisition_body');
        $mo->text_body = ctrans('texts.nordigen_requisition_body');
        $mo->company_key = $bank_integration->company->company_key;
        $mo->html_template = 'email.template.generic';
        $mo->to = [new Address($bank_integration->company->owner()->email, $bank_integration->company->owner()->present()->name())];
        $mo->email_template_body = 'nordigen_requisition_body';
        $mo->email_template_subject = 'nordigen_requisition_subject';

        Email::dispatch($mo, $bank_integration->company);

        Cache::put($cache_key, true, 60 * 60 * 24);

    }

}
