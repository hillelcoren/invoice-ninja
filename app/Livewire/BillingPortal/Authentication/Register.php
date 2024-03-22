<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2022. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Livewire\BillingPortal\Authentication;

use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use App\Models\Subscription;
use App\Models\ClientContact;

class Register extends Component
{
    public Subscription $subscription;

    public array $context;

    public ?string $email;

    public ?string $password;

    public ?int $otp;

    public array $state = [
        'initial_completed' => false,
        'register_form' => false,
    ];

    public function initial(): void
    {
        $this->validateOnly('email', ['email' => 'required|bail|email:rfc']);

        $contact = ClientContact::where('email', $this->email)
            ->where('company_id', $this->subscription->company_id)
            ->first();

        if ($contact) {
            $this->addError('email', ctrans('texts.checkout_only_for_new_customers'));

            return;
        }

        $this->state['initial_completed'] = true;

        $this->registerForm();
    }

    public function register(array $data)
    {
        $service = new ClientRegisterService(
            company: $this->subscription->company,
        );

        $rules = $service->rules();

        $data = Validator::make($data, $rules)->validate();

        $client = $service->createClient($data);
        $contact = $service->createClientContact($data, $client);

        auth()->guard('contact')->loginUsingId($contact->id, true);

        $this->dispatch('purchase.context', property: 'contact', value: $contact);
        $this->dispatch('purchase.next');
    }

    public function registerForm()
    {
        $count = collect($this->subscription->company->client_registration_fields ?? [])
            ->filter(fn($field) => $field['required'] === true || $field['visible'] === true)
            ->count();

        if ($count === 0) {
            $service = new ClientRegisterService(
                company: $this->subscription->company,
            );

            $client = $service->createClient([]);
            $contact = $service->createClientContact(['email' => $this->email], $client);

            auth()->guard('contact')->loginUsingId($contact->id, true);

            $this->dispatch('purchase.context', property: 'contact', value: $contact);
            $this->dispatch('purchase.next');

            return;
        }

        return $this->state['register_form'] = true;
    }

    public function mount()
    {
        if (auth()->guard('contact')->check()) {
            $this->dispatch('purchase.context', property: 'contact', value: auth()->guard('contact')->user());
            $this->dispatch('purchase.next');
        }
    }

    public function render()
    {
        return view('billing-portal.v3.authentication.register');
    }
}