<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\Easymerchant;

use App\Models\ClientGatewayToken;
use Illuminate\Support\Str;

trait Utilities
{

    public function getParent()
    {
        return static::class == \App\PaymentDrivers\EasymerchantPaymentDriver::class ? $this : $this->easymerchant;
    }

    private function getHeaders()
    {
        $input_array = [];
        $input_array['testMode'] = $this->easymerchant->company_gateway->getConfigField('testMode');
        if($input_array['testMode']){
            $input_array['api_url'] = $this->easymerchant->company_gateway->getConfigField('test_url');
            $api_key = $this->easymerchant->company_gateway->getConfigField('test_api_key');
            $api_secret = $this->easymerchant->company_gateway->getConfigField('test_api_secret');
        }else{
            $input_array['api_url'] = $this->easymerchant->company_gateway->getConfigField('production_url');
            $api_key = $this->easymerchant->company_gateway->getConfigField('api_key');
            $api_secret = $this->easymerchant->company_gateway->getConfigField('api_secret');
        }
        
        $input_array['headers'] = [ 
            'Content-Type' => 'application/json',
            'X-Api-Key' => $api_key,
            'X-Api-Secret' => $api_secret 
        ];

        return $input_array;
    }

    private function getACHPaymentDetails($data){
        $customer = $this->checkCustomerExists();
        $ach_token = $this->getACHAccount($data['payment-type']);
        $payment_data = [
            'send_now' => 'no',
            'currency' => "usd",
            'payment_mode' => "auth_and_capture",
            'start_date' => \Carbon\Carbon::now()->format('m-d-Y'), 
            'payment_method' => 'ach',
            'levelIndicator' => 1,
            'account_number' => $data['account_number'],
            'routing_number' => $data['routing_number'],
            'account_type' => 'checking',
            'business_account' => (($data->has('business_account') && $data['business_account']=='individual') || ($ach_token && $ach_token->meta->business_account == 0)) ? 'no' : 'yes',
            'account_validation' => "no",
            'entry_class_code' => "TEL",
            'payment_type' => 'one_time',
            'save_account' => $data['save_account'],
        ];

        if($customer){
            if($data['payment-type'] != 'new_account'){
                $payment_data["account_id"] = $data['payment-type'];
            }
            $payment_data["customer"] = $customer;
            $customer_data = $this->getNewCustomerAchData(false, $data);
        }else{
            $input['create_customer'] = "1";
            $payment_data["username"] = strtolower($this->removeBlankSpace($this->getFullName()));
            $customer_data = $this->getNewCustomerAchData(true, $data);
        }

        $payment_data = array_merge($payment_data, $customer_data);

        return $payment_data;
    }

    private function getCustomerCardData($data, $base_url='')
    {

        $customer = $this->checkCustomerExists();
        $payment_method = false;
        if($base_url){

            $card_data = [
                'description' => 'invoiceninja description',
                'save_card' => 1, 
                'api_url' => $base_url.'/customers'
            ];

            if($customer){
                $card_data['customer'] = $customer;
                $card_data['api_url'] = $base_url.'/card';
            }else{ 
                $card_data['username'] = strtolower($this->easymerchant->client->present()->first_name()).Str::random(10);
                $payment_method = true;

            }
            $paymentData = $this->getPaymentDetails($data, $payment_method);
            $card_data = array_merge($card_data, $paymentData);
        }else{
            $card_data = [
                "payment_mode" => "auth_and_capture",
                "currency" => "usd",
                'amount' => $this->formatAmount($this->easymerchant->payment_hash->data->amount_with_fee)
            ];

            $card_data['customer'] = ($customer) ? : null;
            if($data['payment-type'] != 'on'){
                $card_data['card_id'] = $data['payment-type'];
            }else{
                $card_data['save_card'] = $data['save_card']; 
                $paymentData = $this->getPaymentDetails($data, !$customer);
                $card_data = array_merge($card_data, $paymentData);
            }
        }

        return $card_data;
    }

    private function getACHCustomer($data, $base_url='')
    {
        $customer = $this->checkCustomerExists();

        $params = [
            'accountNumber' => $data->account_number, 
            'accountType' => 'checking', 
            'account_validation' => 'no',
            'routingNumber' => $data->routing_number,
        ];

        $params['customerId'] = ($customer) ? : null;
        $params['create_customer'] = ($customer) ? "0" : "1";

        return $params;
    }

    private function getPaymentDetails($data, $payment_method = true){

        if($payment_method){
            $card_data['name'] = $this->getFullName();
            $card_data['email'] = $this->easymerchant->client->present()->email();
            $card_data['address'] = $this->getAddress();
            $card_data['city'] = $this->easymerchant->client->city ?: '';
            $card_data['state'] = $this->easymerchant->client->state ?: '';
            $card_data['zip'] = $this->easymerchant->client->postal_code ?: '';
            $card_data['country'] = $this->easymerchant->client->country ? $this->easymerchant->client->country->iso_3166_2 : 'US';
        }
        $card_data['cardholder_name'] = $data['card-holders-name'];
        $card_data['card_number'] = $this->removeBlankSpace($data['card-number']);
        $card_data['exp_month'] = $data['expiry-month'];
        $card_data['exp_year'] = $this->formatExpiryYear($data['expiry-year']);
        $card_data['cvc'] = $data['cvc'];

        return $card_data;
    }

    private function checkCustomerExists(){
        $existing = ClientGatewayToken::query()
            ->where('company_gateway_id', $this->easymerchant->company_gateway->id)
            ->where('client_id', $this->easymerchant->client->id)
            ->whereNotNull('gateway_customer_reference')
            ->first();

        if ($existing) {
            return $existing->gateway_customer_reference;
        }

        return false;
    }

    private function getACHAccount($token){
        $meta_data = ClientGatewayToken::query()
            ->where('company_gateway_id', $this->easymerchant->company_gateway->id)
            ->where('client_id', $this->easymerchant->client->id)
            ->where('token', $token)
            ->first();
        
        return $meta_data;
    }

    function getNewCustomerAchData($ach = true, $input){
        $data['name'] = ($ach) ? $this->getFullName() : '';
        $data['email'] = ($ach) ? $this->easymerchant->client->present()->email() : '';
        $data['address'] = ($ach) ? $this->getAddress() : '';
        $data['city'] = ($ach) ? $this->easymerchant->client->city : '';
        $data['state'] = ($ach) ? $this->easymerchant->client->state : '';
        $data['zip'] = ($ach) ? $this->easymerchant->client->postal_code : '';
        $data['country'] = $this->easymerchant->client->country ? $this->easymerchant->client->country->iso_3166_2 : 'US';

        return $data;
    }

    private function getFullName(){
        return $this->easymerchant->client->present()->first_name().' '.$this->easymerchant->client->present()->last_name();
    }

    private function getAddress(){
        return $this->easymerchant->client->address1.' '.$this->easymerchant->client->address2;
    }

    private function removeBlankSpace($result = ''){
        return str_replace(' ', '', $result);
    }

    private function formatAmount($amount = 0, $digit = 2){
        return number_format((float)$amount , $digit, '.', '');
    }

    private function formatExpiryYear($expiry_year = '')
    {
        $currentyear = 20;
        if(date('y') > $expiry_year){
            return ($currentyear+1).$expiry_year;
        }
        return $currentyear.$expiry_year;
    }
}