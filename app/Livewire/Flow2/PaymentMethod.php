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

namespace App\Livewire\Flow2;

use Livewire\Component;
use App\Libraries\MultiDB;

class PaymentMethod extends Component
{
    public $invoice;

    public $context;

    public $variables;

    public $methods = [];

    public $isLoading = true;

    public $amount = 0;

     public function placeholder()
    {
        return <<<'HTML'
        <div  class="flex items-center justify-center min-h-screen">
        <svg class="animate-spin h-10 w-10 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>         
        </div>
        HTML;
    }

    public function mount()
    {

        $this->variables = $this->context['variables'];
        $this->amount = array_sum(array_column($this->context['payable_invoices'], 'amount'));

        MultiDB::setDb($this->context['db']);

        $this->methods = $this->context['invitation']->contact->client->service()->getPaymentMethods($this->amount);

        if(count($this->methods) == 1) {
            $this->dispatch('singlePaymentMethodFound', company_gateway_id: $this->methods[0]['company_gateway_id'], gateway_type_id: $this->methods[0]['gateway_type_id'], amount: $this->amount);
        }
        else {
            $this->isLoading = false;
            $this->dispatch('loadingCompleted');
        }
    }

    public function render()
    { 
        //If there is only one payment method, skip display and push straight to the form!!
        return render('components.livewire.payment_method-flow2', ['methods' => $this->methods]);
    }
}