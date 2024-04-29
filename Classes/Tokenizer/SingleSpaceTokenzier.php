<?php

declare(strict_types=1);


namespace Weakbit\LuceneCache\Tokenizer;

use Zend_Search_Lucene_Analysis_Analyzer_Common;
use Zend_Search_Lucene_Analysis_Token;

class SingleSpaceTokenzier extends Zend_Search_Lucene_Analysis_Analyzer_Common {
    private $_position;
    private $_tokens;

    public function reset() {
        // Assuming $this->_input is the text content to tokenize
        $terms = explode(' ', $this->_input);
        $this->_tokens = [];
        $this->_position = 0;

        foreach ($terms as $position => $term) {
            $term = trim($term);
            if ($term != '') {
                $this->_tokens[] = new Zend_Search_Lucene_Analysis_Token(
                    $term,
                    $position,
                    $position + strlen($term)
                );
            }
        }
    }

    public function nextToken() {
        if ($this->_position < count($this->_tokens)) {
            return $this->_tokens[$this->_position++];
        } else {
            return null;
        }
    }
}
