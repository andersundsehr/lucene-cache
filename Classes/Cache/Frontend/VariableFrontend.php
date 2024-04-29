<?php

declare(strict_types=1);


namespace Weakbit\LuceneCache\Cache\Frontend;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use TYPO3\CMS\Core\Cache\Backend\TransientBackendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;

class VariableFrontend extends \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
{
    protected bool $messagePack = false;
    protected bool $igBinary = false;

    public function __construct($identifier, BackendInterface $backend)
    {
        parent::__construct($identifier, $backend);
        $this->messagePack = class_exists(Packer::class);
        $this->igBinary = class_exists('igbinary_serialize');
    }

    public function set($entryIdentifier, $variable, array $tags = [], $lifetime = null)
    {
        if (!$this->isValidEntryIdentifier($entryIdentifier)) {
            throw new \InvalidArgumentException(
                '"' . $entryIdentifier . '" is not a valid cache entry identifier.',
                1233058264
            );
        }
        foreach ($tags as $tag) {
            if (!$this->isValidTag($tag)) {
                throw new \InvalidArgumentException('"' . $tag . '" is not a valid tag for a cache entry.', 1233058269);
            }
        }

        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/cache/frontend/class.t3lib_cache_frontend_variablefrontend.php']['set'] ?? [] as $_funcRef) {
            $params = [
                'entryIdentifier' => &$entryIdentifier,
                'variable' => &$variable,
                'tags' => &$tags,
                'lifetime' => &$lifetime,
            ];
            GeneralUtility::callUserFunction($_funcRef, $params, $this);
        }
        if (!$this->backend instanceof TransientBackendInterface) {
            $variable = $this->serialize($variable);
        }
        $this->backend->set($entryIdentifier, $variable, $tags, $lifetime);
    }

    /**
     * Finds and returns a variable value from the cache.
     *
     * @param string $entryIdentifier Identifier of the cache entry to fetch
     *
     * @return mixed The value
     * @throws \InvalidArgumentException if the identifier is not valid
     */
    public function get($entryIdentifier)
    {
        if (!$this->isValidEntryIdentifier($entryIdentifier)) {
            throw new \InvalidArgumentException(
                '"' . $entryIdentifier . '" is not a valid cache entry identifier.',
                1233058294
            );
        }
        $rawResult = $this->backend->get($entryIdentifier);
        if ($rawResult === false) {
            return false;
        }
        return $this->backend instanceof TransientBackendInterface ? $rawResult : $this->unserialize($rawResult);
    }

    protected function serialize(mixed $variable)
    {
        if ($this->igBinary) {
            return igbinary_serialize($variable);
        }
        if ($this->messagePack) {
            $packer = GeneralUtility::makeInstance(Packer::class);
            return $packer->pack($variable);
        }
        return serialize($variable);
    }

    protected function unserialize(mixed $rawResult)
    {
        if ($this->igBinary) {
            return igbinary_unserialize($rawResult);
        }
        if ($this->messagePack) {
            $unpacker = GeneralUtility::makeInstance(BufferUnpacker::class, $rawResult);
            return $unpacker->unpack();
        }
        return unserialize($rawResult);
    }
}
