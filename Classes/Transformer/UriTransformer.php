<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Transformer;

use MessagePack\BufferUnpacker;
use MessagePack\Extension;
use MessagePack\Packer;
use TYPO3\CMS\Core\Http\Uri;

class UriTransformer implements Extension
{
    public function __construct(private readonly int $type)
    {
    }

    public function pack(Packer $packer, $value): ?string
    {
        if (!$value instanceof Uri) {
            return null;
        }

        $intermediate = $packer->packStr((string)$value);
        return $packer->packExt($this->type, $intermediate);
    }

    public function unpackExt(BufferUnpacker $unpacker, int $extLength): Uri
    {
        $intermediate = $unpacker->unpackStr();
        return new Uri($intermediate);
    }

    public function getType(): int
    {
        return $this->type;
    }
}
