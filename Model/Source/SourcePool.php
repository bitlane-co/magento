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

use Bitlane\Search\Api\SourceInterface;

/**
 * The sources of documents by id, as etc/di.xml lists them: products, categories, CMS pages, and modules' own.
 */
class SourcePool
{
    /**
     * @param array<string,SourceInterface> $sources
     */
    public function __construct(private readonly array $sources = [])
    {
    }

    /**
     * Every source whose id may name an index, by id.
     *
     * @return array<string,SourceInterface>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->sources as $source) {
            if ($source instanceof SourceInterface && preg_match('/^[a-z0-9_-]{1,64}$/', $source->id())) {
                $all[$source->id()] = $source;
            }
        }

        return $all;
    }

    /**
     * A source by id; null for one that isn't there.
     *
     * @param string $id
     * @return SourceInterface|null
     */
    public function get(string $id): ?SourceInterface
    {
        return $this->all()[$id] ?? null;
    }
}
