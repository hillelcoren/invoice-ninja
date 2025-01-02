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

namespace App\Transformers;

use App\Models\Company;
use App\Models\Document;
use App\Models\ProductAllocation;
use App\Models\User;
use App\Utils\Traits\MakesHash;

class ProductAllocationTransformer extends EntityTransformer
{
    use MakesHash;

    protected array $defaultIncludes = [
        'documents',
    ];

    /**
     * @var array
     */
    protected array $availableIncludes = [
        'company',
        'user',
    ];

    /**
     * @param ProductAllocation $productAllocation
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function includeUser(ProductAllocation $productAllocation)
    {
        $transformer = new UserTransformer($this->serializer);

        return $this->includeItem($productAllocation->user, $transformer, User::class);
    }

    /**
     * @param ProductAllocation $productAllocation
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function includeCompany(ProductAllocation $productAllocation)
    {
        $transformer = new CompanyTransformer($this->serializer);

        return $this->includeItem($productAllocation->company, $transformer, Company::class);
    }

    public function includeDocuments(ProductAllocation $productAllocation)
    {
        $transformer = new DocumentTransformer($this->serializer);

        return $this->includeCollection($productAllocation->documents, $transformer, Document::class);
    }

    public function transform(ProductAllocation $productAllocation)
    {
        return [
            'id' => $this->encodePrimaryKey($productAllocation->id),
            'user_id' => $this->encodePrimaryKey($productAllocation->user_id),
            'assigned_user_id' => $this->encodePrimaryKey($productAllocation->assigned_user_id),
            'product_id' => $productAllocation->product_id ?: '',
            'client_id' => $productAllocation->client_id ?: '',
            'invoice_id' => $productAllocation->invoice_id ?: '',
            'recurring_id' => $productAllocation->recurring_id ?: '',
            'subscription_id' => $productAllocation->subscription_id ?: '',
            'quantity' => is_numeric($productAllocation->quantity) ? (float) $productAllocation->quantity : (float) 1.0, //@phpstan-ignore-line
            'created_at' => (int) $productAllocation->created_at,
            'updated_at' => (int) $productAllocation->updated_at,
            'archived_at' => (int) $productAllocation->deleted_at,
            'public_notes' => $productAllocation->public_notes ?: '',
            'private_notes' => $productAllocation->private_notes ?: '',
            'custom_value1' => $productAllocation->custom_value1 ?: '',
            'custom_value2' => $productAllocation->custom_value2 ?: '',
            'custom_value3' => $productAllocation->custom_value3 ?: '',
            'custom_value4' => $productAllocation->custom_value4 ?: '',
            'is_deleted' => (bool) $productAllocation->is_deleted,
        ];
    }
}
