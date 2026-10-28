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

namespace Bitlane\Search\Api;

use Magento\Store\Model\Group;

/**
 * A kind of content whose items become search documents, each with its own index per store view.
 *
 * Products, categories and CMS pages are built in (docs/magento-plan.md MG5);
 * a module adds its own in its etc/di.xml:
 *
 *     <type name="Bitlane\Search\Model\Source\SourcePool">
 *         <arguments>
 *             <argument name="sources" xsi:type="array">
 *                 <item name="store" xsi:type="object">Vendor\Module\Model\StoreSource</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * and reports its items' changes with ChangesInterface::add().
 */
interface SourceInterface
{
    public const PRODUCTS = 'products';
    public const OTHER = 'other';

    /**
     * Lowercase letters, digits, - and _ ("store"): in index names.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Its name on the setup screen ("Stores"), in the admin's language.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Its documents' schema: PRODUCTS (ProductDocumentV1) or OTHER (OtherDocumentV1).
     *
     * @return string
     */
    public function kind(): string;

    /**
     * How many items a shopper can find in the store (the setup screen, a full sync's progress).
     *
     * @param Group $store
     * @return int
     */
    public function count(Group $store): int;

    /**
     * The filters its documents can have, attribute => its name in a store view's language.
     *
     * Each document's facets.<attribute>; the merchant picks some on the
     * setup screen. A filter another source has too is one filter of both.
     *
     * @param int $storeViewId
     * @return array<string,string>
     */
    public function facets(int $storeViewId): array;

    /**
     * The store's item ids in a stable order: the next $limit after $after ("" for the first).
     *
     * For the full sync and the daily check. Letters, digits, - and _, up to
     * 128 (they're document ids, or part of them).
     *
     * @param Group $store
     * @param string $after
     * @param int $limit
     * @return list<string>
     */
    public function ids(Group $store, string $after, int $limit): array;

    /**
     * Each item's documents in a store view, by item id; built inside an emulation of that store view.
     *
     * An item that's gone or that a guest can't find has none, and what it
     * had is deleted. A products source may give several per item (a
     * configurable's variants, sharing parent_id); any other gives at most
     * one, whose id is the item's.
     *
     * @param list<string> $ids
     * @param Context $context
     * @return array<int|string,list<array<string,mixed>>>
     */
    public function documents(array $ids, Context $context): array;

    /**
     * When each item last changed (Unix seconds), for the daily check; empty when the source can't tell.
     *
     * @param Group $store
     * @param list<string> $ids
     * @return array<int|string,int>
     */
    public function modified(Group $store, array $ids): array;
}
