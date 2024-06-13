<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Transformer;

use MessagePack\BufferUnpacker;
use MessagePack\Extension;
use MessagePack\Packer;
use TYPO3\CMS\Core\Domain\Page;

class PageTransformer implements Extension
{
    public function __construct(private readonly int $type)
    {
    }

    public function pack(Packer $packer, $value): ?string
    {
        if (!$value instanceof Page) {
            return null;
        }

        /** @noRector \Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector */
        $result = $value->toArray(true);
        $hmm = $packer->pack($result);
        return $packer->packExt($this->type, $hmm);
    }

    public function unpackExt(BufferUnpacker $unpacker, int $extLength): Page
    {
        $arrayData = $unpacker->unpack();
        return new Page($arrayData);
    }

    public function getType(): int
    {
        return $this->type;
    }
}
