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

namespace Bitlane\Search\Model\Source;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;

/**
 * An "other" document (widget_public.OtherDocumentV1) as it's sent, after developers had their say.
 *
 * The event bitlane_other_document: the transport's document, null leaves it out.
 */
class OtherDocuments
{
    /**
     * @param ManagerInterface $events
     */
    public function __construct(private readonly ManagerInterface $events)
    {
    }

    /**
     * The document without empty fields, as the event leaves it; null to leave it out.
     *
     * @param string $source
     * @param string $id
     * @param array<string,mixed> $document
     * @param int $storeViewId
     * @return array<string,mixed>|null
     */
    public function finish(string $source, string $id, array $document, int $storeViewId): ?array
    {
        $document = array_filter(
            ['id' => $id] + $document,
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );
        $transport = new DataObject(['document' => $document]);
        $this->events->dispatch('bitlane_other_document', [
            'transport' => $transport,
            'source' => $source,
            'id' => $id,
            'store_view_id' => $storeViewId,
        ]);
        $document = $transport->getData('document');

        return is_array($document) ? $document : null;
    }
}
