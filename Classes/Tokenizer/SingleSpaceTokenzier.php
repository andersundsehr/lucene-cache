<?php

declare(strict_types=1);

namespace Weakbit\LuceneCache\Tokenizer;

use Zend_Search_Lucene_Analysis_Analyzer_Common;
use Zend_Search_Lucene_Analysis_Token;

class SingleSpaceTokenzier extends Zend_Search_Lucene_Analysis_Analyzer_Common
{
    protected int $position = 0;

    /**
     * @var array<Zend_Search_Lucene_Analysis_Token>
     */
    protected array $tokens = [];

    public function reset(): void
    {
        // Assuming $this->_input is the text content to tokenize
        $terms = explode(' ', $this->_input);

        $this->tokens = [];
        $this->position = 0;

        foreach ($terms as $position => $term) {
            $term = trim($term);
            if ($term != '') {
                $this->tokens[] = new Zend_Search_Lucene_Analysis_Token(
                    $term,
                    $position,
                    $position + strlen($term)
                );
            }
        }
    }

    public function nextToken()
    {
        if ($this->position < count($this->tokens)) {
            return $this->tokens[$this->position++];
        }

        return null;
    }
}
