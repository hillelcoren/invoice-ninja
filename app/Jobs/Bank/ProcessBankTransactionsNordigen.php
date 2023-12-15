<?php
/**
 * Credit Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Credit Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\Bank;

use App\Helpers\Bank\Nordigen\Nordigen;
use App\Libraries\MultiDB;
use App\Models\Account;
use App\Models\BankIntegration;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Notifications\Ninja\GenericNinjaAdminNotification;
use App\Services\Bank\BankMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;

class ProcessBankTransactionsNordigen implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private BankIntegration $bank_integration;

    private ?string $from_date;

    private bool $stop_loop = true;

    private int $skip = 0;

    public Company $company;
    public Nordigen $nordigen;
    public $nordigen_account;

    /**
     * Create a new job instance.
     */
    public function __construct(BankIntegration $bank_integration)
    {
        $this->bank_integration = $bank_integration;
        $this->from_date = $bank_integration->from_date;
        $this->company = $this->bank_integration->company;
    }

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle()
    {

        if ($this->bank_integration->integration_type != BankIntegration::INTEGRATION_TYPE_NORDIGEN)
            throw new \Exception("Invalid BankIntegration Type");

        if (!(config('ninja.nordigen.secret_id') && config('ninja.nordigen.secret_key')))
            throw new \Exception("Missing credentials for bank_integration service nordigen");

        $this->nordigen = new Nordigen();

        set_time_limit(0);

        //Loop through everything until we are up to date
        $this->from_date = $this->from_date ?: '2021-01-01';

        // UPDATE ACCOUNT
        try {
            $this->updateAccount();
        } catch (\Exception $e) {
            nlog("{$this->bank_integration->account->key} - exited abnormally => " . $e->getMessage());

            $content = [
                "Processing transactions for account: {$this->bank_integration->account->key} failed",
                "Exception Details => ",
                $e->getMessage(),
            ];

            $this->bank_integration->company->notification(new GenericNinjaAdminNotification($content))->ninja();

            throw $e;
        }
        if (!$this->nordigen_account)
            return;

        // UPDATE TRANSACTIONS
        do {

            try {
                $this->processTransactions();
            } catch (\Exception $e) {
                nlog("{$this->bank_integration->account->key} - exited abnormally => " . $e->getMessage());

                $content = [
                    "Processing transactions for account: {$this->bank_integration->account->key} failed",
                    "Exception Details => ",
                    $e->getMessage(),
                ];

                $this->bank_integration->company->notification(new GenericNinjaAdminNotification($content))->ninja();

                throw $e;
            }

        }
        while ($this->stop_loop);

        BankMatchingService::dispatch($this->company->id, $this->company->db);

    }

    private function updateAccount()
    {

        Log::info("try to execute updateAccount");
        if (!$this->nordigen->isAccountActive($this->bank_integration->nordigen_account_id)) {
            $this->bank_integration->disabled_upstream = true;
            $this->bank_integration->save();
            $this->stop_loop = false;
            Log::info("account inactive");
            // @turbo124 @todo send email for expired account
            return;
        }

        $this->nordigen_account = $this->nordigen->getAccount($this->bank_integration->nordigen_account_id);

        $this->bank_integration->disabled_upstream = false;
        $this->bank_integration->bank_account_status = $this->nordigen_account['account_status'];
        $this->bank_integration->balance = $this->nordigen_account['current_balance'];

        $this->bank_integration->save();

    }

    private function processTransactions()
    {

        //Get transaction count object
        $transactions = $this->nordigen->getTransactions($this->bank_integration->nordigen_account_id, $this->from_date);

        Log::Info($transactions);

        //Get int count
        $count = sizeof($transactions);

        //if no transactions, update the from_date and move on
        if (count($transactions) == 0) {

            $this->bank_integration->from_date = now()->subDays(5);
            $this->bank_integration->disabled_upstream = false;
            $this->bank_integration->save();
            $this->stop_loop = false;
            return;
        }

        //Harvest the company

        MultiDB::setDb($this->company->db);

        /*Get the user */
        $user_id = $this->company->owner()->id;

        /* Unguard the model to perform batch inserts */
        BankTransaction::unguard();

        $now = now();


        foreach ($transactions as $transaction) {

            if (BankTransaction::where('transaction_id', $transaction['transaction_id'])->where('company_id', $this->company->id)->where('bank_integration_id', $this->bank_integration->id)->withTrashed()->exists())
                continue;

            //this should be much faster to insert than using ::create()
            \DB::table('bank_transactions')->insert(
                array_merge($transaction, [
                    'company_id' => $this->company->id,
                    'user_id' => $user_id,
                    'bank_integration_id' => $this->bank_integration->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );

        }


        // $this->skip = $this->skip + 500;

        // if ($count < 500) {
        //     $this->stop_loop = false;
        //     $this->bank_integration->from_date = now()->subDays(5);
        //     $this->bank_integration->save();

        // }

        $this->stop_loop = false;
        $this->bank_integration->from_date = now()->subDays(5);
        $this->bank_integration->save();

    }
}