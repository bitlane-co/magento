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
use Bitlane\Search\Model\Site\Settings;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Helper\ImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * Products' documents in a store view as a guest sees them (ProductDocumentV1, docs/magento-plan.md MG6–MG8).
 *
 * A configurable product is one document per variant (id
 * "<configurable>-<child>"), or one for the whole product, as the setup says;
 * any other product is one document, its id the product's. parent_id is the
 * product's id either way. A product a guest can't find has none: disabled
 * in the store view, not on its website, visible in the catalogue only or
 * not at all, or out of stock while the store hides such products. Prices are
 * NOT LOGGED IN's from Magento's price index, with the store view's tax
 * display, in its display currency. Developers change a document in the event
 * bitlane_product_document (transport's document; null leaves it out).
 */
class ProductDocuments
{
    /**
     * The availability filter's attribute: in stock, on backorder, out of stock.
     */
    public const STOCK_STATUS = 'stock_status';

    /**
     * The configurable product type, without depending on Magento_ConfigurableProduct.
     */
    private const CONFIGURABLE = 'configurable';

    /**
     * Filters that aren't one of the product's attributes.
     */
    private const BUILT_IN = ['price', 'category', self::STOCK_STATUS];

    /**
     * Each stock status's swatch.
     */
    private const STATUS_COLORS = ['instock' => '#16a34a', 'onbackorder' => '#f59e0b', 'outofstock' => '#dc2626'];

    /**
     * @param CollectionFactory $collections
     * @param CatalogFactory $catalogs
     * @param Fields $fields
     * @param EavConfig $eavConfig
     * @param ImageFactory $images
     * @param CatalogHelper $catalogHelper
     * @param TaxConfig $taxConfig
     * @param ResourceConnection $resource
     * @param ManagerInterface $events
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly CatalogFactory $catalogs,
        private readonly Fields $fields,
        private readonly EavConfig $eavConfig,
        private readonly ImageFactory $images,
        private readonly CatalogHelper $catalogHelper,
        private readonly TaxConfig $taxConfig,
        private readonly ResourceConnection $resource,
        private readonly ManagerInterface $events
    ) {
    }

    /**
     * The products' documents, by product id.
     *
     * @param list<string> $ids
     * @param Context $context
     * @return array<int|string,list<array<string,mixed>>>
     */
    public function build(array $ids, Context $context): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $documents = array_fill_keys(array_map('strval', $ids), []);
        if (!$ids) {
            return $documents;
        }
        $catalog = $this->catalogs->create(['context' => $context]);
        $attributes = $this->facetAttributes($context);
        $brand = $this->attributeCode('manufacturer');
        $codes = array_keys($attributes);
        if ($brand) {
            $codes[] = $brand;
        }

        $products = $this->load($ids, $context, $codes);
        $configurables = array_filter(
            $products,
            static fn (Product $product) => $product->getTypeId() === self::CONFIGURABLE
        );
        $variants = $this->variants(array_keys($configurables), $context, array_keys($attributes));

        // By product id: the products and their variants' children.
        $all = $products;
        foreach ($variants as $variant) {
            $all += $variant['children'];
            array_push($codes, ...array_keys($variant['axes']));
        }
        $catalog->options($this->optionIds($all, $codes));
        $parentIds = array_keys($products);
        $batch = new ProductBatch(
            $context,
            $catalog,
            $attributes,
            $brand,
            $catalog->stock(array_map(static fn (Product $product) => (string) $product->getSku(), $all)),
            in_array('category', $context->facets, true) ? $catalog->categoryValues($parentIds) : [],
            $catalog->positions($parentIds),
            $catalog->ratings($parentIds),
            $catalog->sales($parentIds),
            $catalog->showsOutOfStock(),
            $this->taxConfig->getPriceDisplayType((int) $context->storeView->getId())
                !== TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX
        );

        foreach ($products as $id => $product) {
            if (!$this->searchable($product)) {
                continue;
            }
            $documents[(string) $id] = $product->getTypeId() === self::CONFIGURABLE
                ? $this->configurable($product, $variants[$id] ?? ['children' => [], 'axes' => []], $batch)
                : $this->single($product, $batch);
        }

        return $documents;
    }

    /**
     * Whether a guest can find the product: enabled in the store view and visible in search.
     *
     * @param Product $product
     * @return bool
     */
    public function searchable(Product $product): bool
    {
        $visible = [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH];

        return (int) $product->getStatus() === Status::STATUS_ENABLED
            && in_array((int) $product->getVisibility(), $visible, true);
    }

    /**
     * A simple, virtual, downloadable, bundle or grouped product's document.
     *
     * @param Product $product
     * @param ProductBatch $batch
     * @return list<array<string,mixed>>
     */
    private function single(Product $product, ProductBatch $batch): array
    {
        $status = $batch->status((int) $product->getId());
        if (!$batch->shows($status)) {
            return [];
        }
        $facets = $this->fields->merge([$this->ownValues($product, $batch), $this->statusValue($status)]);
        // Bundles and grouped products range over their parts; another
        // product's max_price leaves out a catalogue rule.
        $parts = in_array($product->getTypeId(), ['bundle', 'grouped'], true);
        $min = $this->paid($product);
        $max = $parts ? (float) ($product->getData('max_price') ?: $min) : $min;
        $price = (float) $product->getData('price');

        $document = $this->document($product, $product, [
            'facets' => $facets,
            'all' => $facets,
            'available' => $status !== 'outofstock' ? $facets : [],
        ], [
            'final' => [$min, $max],
            'regular' => $parts ? [$min, $max] : [$price, $price],
        ], $batch);

        return $document ? [$document] : [];
    }

    /**
     * A configurable product's documents: a variant each, or one for the whole product.
     *
     * @param Product $product
     * @param array $variants its children by id, and the attributes they vary in (axes, code => id)
     * @phpstan-param array{children: array<int, Product>, axes: array<string, int>} $variants
     * @param ProductBatch $batch
     * @return list<array<string,mixed>>
     */
    private function configurable(Product $product, array $variants, ProductBatch $batch): array
    {
        $own = $this->ownValues($product, $batch);
        $axes = array_keys($variants['axes']);
        $others = array_values(array_diff(array_keys($batch->attributes), $axes));
        $choices = [];
        foreach ($variants['children'] as $childId => $child) {
            $status = $batch->status($childId);
            if ($batch->shows($status)) {
                $choices[$childId] = $this->fields->merge([
                    $this->attributeValues($child, $axes, $batch->catalog),
                    $this->attributeValues($child, $others, $batch->catalog),
                    $this->statusValue($status),
                ]);
            }
        }
        if (!$choices) {
            return [];
        }
        $all = $this->fields->merge([$own, ...array_values($choices)]);
        $inStock = array_values(array_filter(
            array_keys($choices),
            static fn (int $childId) => $batch->status($childId) !== 'outofstock'
        ));

        if ($batch->context->productDocuments === Settings::PER_PRODUCT) {
            $children = array_intersect_key($variants['children'], $choices);
            $final = array_map(fn (Product $child) => $this->paid($child), $children);
            $regular = array_map(static fn (Product $child) => (float) $child->getData('price'), $children);
            $inStockValues = array_map(static fn (int $childId) => $choices[$childId], $inStock);
            $document = $this->document($product, $product, [
                'facets' => $all,
                'all' => $all,
                'available' => $inStock ? $this->fields->merge([$own, ...$inStockValues]) : [],
            ], [
                'final' => [min($final), max($final)],
                'regular' => [min($regular), max($regular)],
            ], $batch);

            return $document ? [$document] : [];
        }

        $documents = [];
        foreach ($choices as $childId => $values) {
            $child = $variants['children'][$childId];
            $facets = $this->fields->merge([$own, $values]);
            $final = $this->paid($child);
            $regular = (float) $child->getData('price');
            $document = $this->document($product, $child, [
                'facets' => $facets,
                'all' => $all,
                'available' => in_array($childId, $inStock, true) ? $facets : [],
            ], [
                'final' => [$final, $final],
                'regular' => [$regular, $regular],
            ], $batch, ['id' => $product->getId() . '-' . $childId, 'axes' => $variants['axes']]);
            if ($document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * One document: the product's, or a variant's (its child and the configurable's axes).
     *
     * @param Product $product the product, or the configurable of a variant
     * @param Product $item the product, or the variant's child
     * @param array $values facets (the document's), all (the whole product's), available (when in stock)
     * @phpstan-param array{
     *     facets: array<string, list<string>>,
     *     all: array<string, list<string>>,
     *     available: array<string, list<string>>
     * } $values
     * @param array $ranges final (the price paid) and regular (before discounts), each min and max
     * @phpstan-param array{final: array{float, float}, regular: array{float, float}} $ranges
     * @param ProductBatch $batch
     * @param array|null $variant the variant's id and the configurable's axes
     * @phpstan-param array{id: string, axes: array<string, int>}|null $variant
     * @return array<string,mixed>|null
     */
    private function document(
        Product $product,
        Product $item,
        array $values,
        array $ranges,
        ProductBatch $batch,
        ?array $variant = null
    ): ?array {
        $id = (int) $product->getId();
        $context = $batch->context;
        $price = $this->prices($item, $ranges['final'], $batch);
        $regular = $this->prices($item, $ranges['regular'], $batch);
        $url = (string) $product->getProductUrl();
        if ($variant) {
            // Luma and Hyvä preselect a configurable's options from the address's hash.
            $selected = [];
            foreach ($variant['axes'] as $code => $attributeId) {
                $selected[$attributeId] = (string) $item->getData($code);
            }
            $url .= '#' . http_build_query($selected);
        }
        $rating = $batch->ratings[$id] ?? null;
        $updated = max((string) $product->getData('updated_at'), (string) $item->getData('updated_at'));

        $document = [
            'id' => $variant['id'] ?? (string) $id,
            'parent_id' => (string) $id,
            'title' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'variant_title' => $variant ? $this->variantTitle($item, $variant['axes'], $batch->catalog) : null,
            'variant_sku' => $variant && $item->getSku() !== $product->getSku() ? (string) $item->getSku() : null,
            'permalink' => $url,
            'image' => $this->image($item, $product),
            'brand' => $batch->brand ? $this->label($product, $batch->brand, $batch->catalog) : null,
            'price' => $price,
            'regular_price' => $regular,
            'short_description' => $this->fields->text((string) $product->getData('short_description')),
            'description' => $this->fields->text((string) $product->getData('description')),
            'facets' => (object) $values['facets'],
            'product_facets' => (object) $values['all'],
            'available' => (object) $values['available'],
            'flags' => [
                'in_stock' => (bool) $values['available'],
                'on_sale' => $price['min'] < $regular['min'],
                'featured' => false,
            ],
            'total_sales' => $batch->sales[$id] ?? 0,
            'rating' => $rating['rating'] ?? null,
            'rating_count' => $rating['count'] ?? null,
            'created_at' => (int) strtotime((string) $product->getData('created_at') . ' UTC'),
            'modified_at' => (int) strtotime($updated . ' UTC'),
            'menu_order' => $batch->positions[$id] ?? 0,
        ];
        $document = array_filter($document, static fn ($value) => $value !== null && $value !== '' && $value !== 0);

        $transport = new DataObject(['document' => $document]);
        $this->events->dispatch('bitlane_product_document', [
            'transport' => $transport,
            'product' => $product,
            'item' => $item,
            'store_view_id' => (int) $context->storeView->getId(),
        ]);
        $document = $transport->getData('document');

        return is_array($document) ? $document : null;
    }

    /**
     * What a guest pays for one: the price index's min_price (special price, catalogue rule, a tier price for one).
     *
     * @param Product $product
     * @return float
     */
    private function paid(Product $product): float
    {
        $final = (float) $product->getData('final_price');

        return (float) ($product->getData('min_price') ?: $final);
    }

    /**
     * A price range in the store view's display currency's minor units, with its tax display.
     *
     * @param Product $product
     * @param array $range min and max, in the website's base currency, as entered
     * @phpstan-param array{float, float} $range
     * @param ProductBatch $batch
     * @return array{min: int, max: int}
     */
    private function prices(Product $product, array $range, ProductBatch $batch): array
    {
        $view = $batch->context->storeView;
        $currency = $batch->context->currency;
        $convert = function (float $amount) use ($product, $batch, $view, $currency): int {
            $amount = (float) $this->catalogHelper->getTaxPrice(
                $product,
                $amount,
                $batch->includingTax,
                null,
                null,
                null,
                (int) $view->getId()
            );
            if ($view->getBaseCurrencyCode() !== $currency) {
                $amount = (float) $view->getBaseCurrency()->convert($amount, $currency);
            }

            return $this->fields->minor($amount, $currency);
        };

        return ['min' => $convert(min($range)), 'max' => $convert(max($range))];
    }

    /**
     * The variant's options in the configurable's order: "Red, XL".
     *
     * @param Product $child
     * @param array<string,int> $axes
     * @param Catalog $catalog
     * @return string
     */
    private function variantTitle(Product $child, array $axes, Catalog $catalog): string
    {
        return implode(', ', array_filter(array_map(
            fn (string $code) => $this->label($child, $code, $catalog),
            array_keys($axes)
        )));
    }

    /**
     * The card's image: the item's small image, else the product's; null without one.
     *
     * @param Product $item
     * @param Product $product
     * @return array<string,string|int>|null
     */
    private function image(Product $item, Product $product): ?array
    {
        $source = $this->hasImage($item) ? $item : ($this->hasImage($product) ? $product : null);
        if ($source === null) {
            return null;
        }
        $shown = $this->images->create()->init($source, 'category_page_grid');
        $width = (int) $shown->getWidth();
        $height = (int) $shown->getHeight();
        $src = $shown->getUrl();
        $large = $width > 0 ? $this->images->create()->init($source, 'category_page_grid')
            ->resize($width * 2, $height > 0 ? $height * 2 : null)->getUrl() : '';

        return array_filter([
            'src' => $src,
            'srcset' => $large !== '' ? $src . ' ' . $width . 'w, ' . $large . ' ' . ($width * 2) . 'w' : '',
            'sizes' => $large !== '' ? $width . 'px' : '',
            'width' => $width,
            'height' => $height,
            'alt' => (string) $product->getName(),
        ], static fn ($value) => $value !== '' && $value !== 0);
    }

    /**
     * Whether a product has a small image of its own.
     *
     * @param Product $product
     * @return bool
     */
    private function hasImage(Product $product): bool
    {
        $file = (string) $product->getData('small_image');

        return $file !== '' && $file !== 'no_selection';
    }

    /**
     * The product's own filter values: its attributes and categories.
     *
     * @param Product $product
     * @param ProductBatch $batch
     * @return array<string,list<string>>
     */
    private function ownValues(Product $product, ProductBatch $batch): array
    {
        $categories = $batch->categories[(int) $product->getId()] ?? [];

        return $this->fields->merge([
            $this->attributeValues($product, array_keys($batch->attributes), $batch->catalog),
            $categories ? ['category' => $categories] : [],
        ]);
    }

    /**
     * A product's values of option attributes (select, multiselect, boolean), by attribute code.
     *
     * @param Product $product
     * @param list<string> $codes
     * @param Catalog $catalog
     * @return array<string,list<string>>
     */
    private function attributeValues(Product $product, array $codes, Catalog $catalog): array
    {
        $values = [];
        foreach ($codes as $code) {
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === false) {
                continue;
            }
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if ($attribute->getFrontendInput() === 'boolean') {
                $yes = (bool) $raw;
                $label = (string) ($yes ? __('Yes') : __('No'));
                $values[$code][] = $this->fields->value($label, $yes ? 'yes' : 'no', $yes ? 1 : 0);
                continue;
            }
            $options = $catalog->options(array_map('intval', explode(',', (string) $raw)));
            foreach ($options as $optionId => $option) {
                if ($option['label'] !== '') {
                    $values[$code][] = $this->fields->value($option['label'], '', $optionId, $option['swatch']);
                }
            }
        }

        return $values;
    }

    /**
     * A product's option label for an attribute ("Red"); empty without one.
     *
     * @param Product $product
     * @param string $code
     * @param Catalog $catalog
     * @return string
     */
    private function label(Product $product, string $code, Catalog $catalog): string
    {
        $raw = (string) $product->getData($code);
        $options = $raw !== '' ? $catalog->options([(int) $raw]) : [];

        return (string) ($options[(int) $raw]['label'] ?? '');
    }

    /**
     * A stock status as a filter value, named in the store view's language.
     *
     * @param string $status
     * @return array<string,list<string>>
     */
    private function statusValue(string $status): array
    {
        $names = [
            'instock' => (string) __('In stock'),
            'onbackorder' => (string) __('On backorder'),
            'outofstock' => (string) __('Out of stock'),
        ];
        $value = $this->fields->value($names[$status], $status, $status, self::STATUS_COLORS[$status]);

        return [self::STOCK_STATUS => [$value]];
    }

    /**
     * The picked filters that are option attributes, code => attribute id.
     *
     * @param Context $context
     * @return array<string,int>
     */
    private function facetAttributes(Context $context): array
    {
        $attributes = [];
        foreach (array_diff($context->facets, self::BUILT_IN) as $code) {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if ($attribute->getId()
                && in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)
            ) {
                $attributes[$code] = (int) $attribute->getId();
            }
        }

        return $attributes;
    }

    /**
     * An attribute's code when the catalogue has it; null otherwise.
     *
     * @param string $code
     * @return string|null
     */
    private function attributeCode(string $code): ?string
    {
        return $this->eavConfig->getAttribute('catalog_product', $code)->getId() ? $code : null;
    }

    /**
     * The products a guest might find, with what documents need, by id.
     *
     * NOT LOGGED IN's prices from the price index, which leaves out products
     * without one (disabled, not on the website).
     *
     * @param list<int> $ids
     * @param Context $context
     * @param list<string> $attributes
     * @return array<int,Product>
     */
    private function load(array $ids, Context $context, array $attributes): array
    {
        $websiteId = (int) $context->storeView->getWebsiteId();
        $select = ['name', 'sku', 'url_key', 'small_image', 'short_description', 'description', 'status'];
        array_push($select, 'visibility', 'tax_class_id', ...$attributes);
        $collection = $this->collections->create()
            ->setStoreId((int) $context->storeView->getId())
            ->addIdFilter($ids)
            ->addWebsiteFilter([$websiteId])
            ->addAttributeToSelect($select)
            ->addPriceData(0, $websiteId)
            ->addUrlRewrite();
        $products = [];
        foreach ($collection as $product) {
            $products[(int) $product->getId()] = $product;
        }

        return $products;
    }

    /**
     * Configurable products' enabled children and the attributes they vary in (code => attribute id, in order).
     *
     * @param list<int> $parentIds
     * @param Context $context
     * @param list<string> $attributes the picked filters
     * @return array<int,array{children: array<int,Product>, axes: array<string,int>}>
     */
    private function variants(array $parentIds, Context $context, array $attributes): array
    {
        if (!$parentIds) {
            return [];
        }
        $db = $this->resource->getConnection();
        $links = $db->fetchAll(
            $db->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['parent_id', 'product_id'])
                ->where('parent_id IN (?)', $parentIds)
        );
        $axes = [];
        foreach ($db->fetchAll(
            $db->select()
                ->from(['sa' => $this->resource->getTableName('catalog_product_super_attribute')], ['product_id'])
                ->join(
                    ['a' => $this->resource->getTableName('eav_attribute')],
                    'a.attribute_id = sa.attribute_id',
                    ['attribute_id', 'attribute_code']
                )
                ->where('sa.product_id IN (?)', $parentIds)->order(['sa.product_id', 'sa.position'])
        ) as $row) {
            $axes[(int) $row['product_id']][(string) $row['attribute_code']] = (int) $row['attribute_id'];
        }

        $codes = $attributes;
        foreach ($axes as $parentAxes) {
            array_push($codes, ...array_keys($parentAxes));
        }
        $children = $this->children(array_map('intval', array_column($links, 'product_id')), $context, $codes);

        $variants = [];
        foreach ($parentIds as $parentId) {
            $variants[$parentId] = ['children' => [], 'axes' => $axes[$parentId] ?? []];
        }
        foreach ($links as $link) {
            $child = $children[(int) $link['product_id']] ?? null;
            if ($child) {
                $variants[(int) $link['parent_id']]['children'][(int) $link['product_id']] = $child;
            }
        }

        return $variants;
    }

    /**
     * Configurables' children enabled in the store view and on its website, with their prices, by id.
     *
     * @param list<int> $ids
     * @param Context $context
     * @param list<string> $codes the attributes to read
     * @return array<int,Product>
     */
    private function children(array $ids, Context $context, array $codes): array
    {
        if (!$ids) {
            return [];
        }
        $websiteId = (int) $context->storeView->getWebsiteId();
        $collection = $this->collections->create()
            ->setStoreId((int) $context->storeView->getId())
            ->addIdFilter(array_values(array_unique($ids)))
            ->addWebsiteFilter([$websiteId])
            ->addAttributeToSelect(array_values(array_unique(array_merge(
                ['name', 'sku', 'small_image', 'status', 'tax_class_id'],
                $codes
            ))))
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addPriceData(0, $websiteId);
        $children = [];
        foreach ($collection as $child) {
            $children[(int) $child->getId()] = $child;
        }

        return $children;
    }

    /**
     * The option ids the products have in the given attributes.
     *
     * @param array<int,Product> $products
     * @param list<string> $codes
     * @return list<int>
     */
    private function optionIds(array $products, array $codes): array
    {
        $ids = [];
        foreach ($products as $product) {
            foreach (array_unique($codes) as $code) {
                $raw = (string) $product->getData($code);
                if ($raw !== '' && preg_match('/^[\d,]+$/', $raw)) {
                    foreach (explode(',', $raw) as $id) {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
