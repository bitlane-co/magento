<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Model\Documents;

use Bitlane\Search\Api\Context;

/**
 * What a batch of products' documents read, gathered once for the batch (ProductDocuments).
 */
class ProductBatch
{
    /**
     * @param Context $context
     * @param Catalog $catalog
     * @param array<string,int> $attributes the picked filters that are option attributes, code => id
     * @param string|null $brand the brand's attribute code, when the catalogue has one
     * @param array<int,string> $stock each product's and variant's stock status
     * @param array<int,list<string>> $categories each product's category values
     * @param array<int,int> $positions
     * @param array<int,array{rating:float,count:int}> $ratings
     * @param array<int,int> $sales
     * @param bool $showsOutOfStock
     * @param bool $includingTax whether the store view shows prices with tax
     */
    public function __construct(
        public readonly Context $context,
        public readonly Catalog $catalog,
        public readonly array $attributes,
        public readonly ?string $brand,
        public readonly array $stock,
        public readonly array $categories,
        public readonly array $positions,
        public readonly array $ratings,
        public readonly array $sales,
        public readonly bool $showsOutOfStock,
        public readonly bool $includingTax
    ) {
    }

    /**
     * A product's or variant's stock status: instock, onbackorder or outofstock.
     *
     * @param int $productId
     * @return string
     */
    public function status(int $productId): string
    {
        return $this->stock[$productId] ?? 'outofstock';
    }

    /**
     * Whether shoppers see a product or variant with that stock status.
     *
     * @param string $status
     * @return bool
     */
    public function shows(string $status): bool
    {
        return $status !== 'outofstock' || $this->showsOutOfStock;
    }
}
