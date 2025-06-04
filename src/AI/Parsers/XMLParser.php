<?php

namespace Kargnas\LaravelAiTranslator\AI\Parsers;

use Illuminate\Support\Facades\Log;

class XMLParser
{
    // Store full XML response
    private string $fullResponse = '';

    // Parsed data storage
    private array $parsedData = [];

    // Debug mode flag
    private bool $debug = false;

    // Node completion callback
    private $nodeCompleteCallback = null;

    // CDATA content cache (for complete CDATA extraction)
    private string $cdataCache = '';

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Set node completion callback
     */
    public function onNodeComplete(callable $callback): void
    {
        $this->nodeCompleteCallback = $callback;
    }

    /**
     * Reset parser state
     */
    public function reset(): void
    {
        $this->fullResponse = '';
        $this->parsedData = [];
        $this->cdataCache = '';
    }

    /**
     * Add chunk data and accumulate full response
     */
    public function addChunk(string $chunk): void
    {
        $this->fullResponse .= $chunk;
    }

    /**
     * Parse complete XML string (full string processing instead of streaming)
     */
    public function parse(string $xml): void
    {
        $this->reset();
        $this->fullResponse = $xml;
        $this->processFullResponse();
    }

    /**
     * Process full response (using standard XML parser first)
     */
    private function processFullResponse(): void
    {
        // Clean up XML response
        $xml = $this->prepareXmlForParsing($this->fullResponse);

        // Skip if XML is empty or incomplete
        if (empty($xml)) {
            if ($this->debug) {
                Log::debug('XMLParser: Empty XML response');
            }

            return;
        }

        // Process each <item> tag individually
        if (preg_match_all('/<item>(.*?)<\/item>/s', $xml, $matches)) {
            foreach ($matches[1] as $itemContent) {
                $this->processItem($itemContent);
            }
        }
    }

    /**
     * Process single item tag
     */
    private function processItem(string $itemContent): void
    {
        // Extract key and trx
        if (
            preg_match('/<key>(.*?)<\/key>/s', $itemContent, $keyMatch) &&
            preg_match('/<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $itemContent, $trxMatch)
        ) {
            $key = $this->cleanContent($keyMatch[1]);
            $trx = $this->cleanContent($trxMatch[1]);

            // Extract comment if exists
            $comment = null;
            if (preg_match('/<comment><!\[CDATA\[(.*?)\]\]><\/comment>/s', $itemContent, $commentMatch)) {
                $comment = $this->cleanContent($commentMatch[1]);
            }

            // Store parsed data
            if (! isset($this->parsedData['key'])) {
                $this->parsedData['key'] = [];
            }
            if (! isset($this->parsedData['trx'])) {
                $this->parsedData['trx'] = [];
            }
            if ($comment !== null && ! isset($this->parsedData['comment'])) {
                $this->parsedData['comment'] = [];
            }

            $this->parsedData['key'][] = ['content' => $key];
            $this->parsedData['trx'][] = ['content' => $trx];
            if ($comment !== null) {
                $this->parsedData['comment'][] = ['content' => $comment];
            }

            // Call node completion callback
            if ($this->nodeCompleteCallback) {
                call_user_func($this->nodeCompleteCallback, 'item', $itemContent, []);
            }

            if ($this->debug) {
                $debugInfo = [
                    'key' => $key,
                    'trx_length' => strlen($trx),
                    'trx_preview' => mb_substr($trx, 0, 30),
                ];
                if ($comment !== null) {
                    $debugInfo['comment'] = $comment;
                }
                Log::debug('XMLParser: Processed item', $debugInfo);
            }
        }
    }

    /**
     * Clean up XML response for standard parsing
     */
    private function prepareXmlForParsing(string $xml): string
    {
        // Remove content before actual XML tag start
        $firstTagPos = strpos($xml, '<');
        if ($firstTagPos > 0) {
            $xml = substr($xml, $firstTagPos);
        }

        // Remove content after last XML tag
        $lastTagPos = strrpos($xml, '>');
        if ($lastTagPos !== false && $lastTagPos < strlen($xml) - 1) {
            $xml = substr($xml, 0, $lastTagPos + 1);
        }

        // Handle special characters
        $xml = $this->unescapeSpecialChars($xml);

        // Add root tag if missing
        if (! preg_match('/^\s*<\?xml|^\s*<translations/i', $xml)) {
            $xml = '<translations>'.$xml.'</translations>';
        }

        // Add XML declaration if missing
        if (strpos($xml, '<?xml') === false) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;
        }

        return $xml;
    }

    /**
     * Extract complete <item> tags (handle multiple items)
     */
    private function extractCompleteItems(): void
    {
        // Try multiple patterns for <item> tags
        $patterns = [
            // Standard pattern (with line breaks)
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)<\/trx>\s*<\/item>/s',

            // Single line pattern
            '/<item><key>(.*?)<\/key><trx>(.*?)<\/trx><\/item>/s',

            // Pattern with spaces between tags
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)<\/trx>\s*<\/item>/s',

            // Handle missing closing tag
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)(?:<\/trx>|<item>)/s',

            // Pattern for direct CDATA search
            '/<key>(.*?)<\/key>\s*<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s',

            // Simplified pattern
            '/<key>(.*?)<\/key>.*?<trx>.*?\[CDATA\[(.*?)\]\]>.*?<\/trx>/s',
        ];

        foreach ($patterns as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $this->fullResponse, $matches, PREG_SET_ORDER) && count($matches) > 0) {
                if ($this->debug) {
                    Log::debug('XMLParser: Found items with pattern', [
                        'pattern' => $pattern,
                        'count' => count($matches),
                    ]);
                }

                // Process each item
                foreach ($matches as $i => $match) {
                    if (count($match) < 3) {
                        continue; // Pattern match failed
                    }

                    $key = $this->cleanContent($match[1]);
                    $trxContent = $match[2];

                    // Check if key already processed
                    $keyExists = false;
                    if (isset($this->parsedData['key'])) {
                        foreach ($this->parsedData['key'] as $existingKeyData) {
                            if ($existingKeyData['content'] === $key) {
                                $keyExists = true;
                                break;
                            }
                        }
                    }

                    if ($keyExists) {
                        continue; // Skip already processed key
                    }

                    // Extract CDATA content
                    $trxProcessed = $this->processTrxContent($trxContent);

                    // Add to parsed data
                    if (! isset($this->parsedData['key'])) {
                        $this->parsedData['key'] = [];
                    }
                    if (! isset($this->parsedData['trx'])) {
                        $this->parsedData['trx'] = [];
                    }

                    $this->parsedData['key'][] = ['content' => $key];
                    $this->parsedData['trx'][] = ['content' => $trxProcessed];

                    if ($this->debug) {
                        Log::debug('XMLParser: Extracted item', [
                            'pattern' => $pattern,
                            'index' => $i,
                            'key' => $key,
                            'trx_length' => strlen($trxProcessed),
                            'trx_preview' => substr($trxProcessed, 0, 50),
                        ]);
                    }
                }
            }
        }

        // Additional: Special case - Direct CDATA extraction attempt
        if (preg_match_all('/<key>(.*?)<\/key>.*?<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $this->fullResponse, $matches, PREG_SET_ORDER)) {
            if ($this->debug) {
                Log::debug('XMLParser: Direct CDATA extraction attempt', [
                    'found' => count($matches),
                ]);
            }

            foreach ($matches as $i => $match) {
                $key = $this->cleanContent($match[1]);
                $cdata = $match[2];

                // Check if key already exists
                $keyExists = false;
                if (isset($this->parsedData['key'])) {
                    foreach ($this->parsedData['key'] as $existingKeyData) {
                        if ($existingKeyData['content'] === $key) {
                            $keyExists = true;
                            break;
                        }
                    }
                }

                if ($keyExists) {
                    continue; // Skip already processed key
                }

                // Add to parsed data
                if (! isset($this->parsedData['key'])) {
                    $this->parsedData['key'] = [];
                }
                if (! isset($this->parsedData['trx'])) {
                    $this->parsedData['trx'] = [];
                }

                $this->parsedData['key'][] = ['content' => $key];
                $this->parsedData['trx'][] = ['content' => $this->unescapeSpecialChars($cdata)];

                if ($this->debug) {
                    Log::debug('XMLParser: Extracted CDATA directly', [
                        'key' => $key,
                        'cdata_preview' => substr($cdata, 0, 50),
                    ]);
                }
            }
        }
    }

    /**
     * Extract <key> tags from full XML
     */
    private function extractKeyItems(): void
    {
        if (preg_match_all('/<key>(.*?)<\/key>/s', $this->fullResponse, $matches)) {
            $this->parsedData['key'] = [];

            foreach ($matches[1] as $keyContent) {
                $content = $this->cleanContent($keyContent);
                $this->parsedData['key'][] = ['content' => $content];
            }
        }
    }

    /**
     * Extract <trx> tags and CDATA content from full XML
     */
    private function extractTrxItems(): void
    {
        // Extract <trx> tag content including CDATA (using greedy pattern)
        $pattern = '/<trx>(.*?)<\/trx>/s';

        if (preg_match_all($pattern, $this->fullResponse, $matches)) {
            $this->parsedData['trx'] = [];

            foreach ($matches[1] as $trxContent) {
                // Extract and process CDATA
                $processedContent = $this->processTrxContent($trxContent);
                $this->parsedData['trx'][] = ['content' => $processedContent];

                // Store CDATA content in cache (for post-processing)
                $this->cdataCache = $processedContent;
            }
        }
    }

    /**
     * Process <trx> tag content and extract CDATA
     */
    private function processTrxContent(string $content): string
    {
        // Extract CDATA content
        if (preg_match('/<!\[CDATA\[(.*)\]\]>/s', $content, $cdataMatches)) {
            $cdataContent = $cdataMatches[1];

            // Handle special character escaping
            $processedContent = $this->unescapeSpecialChars($cdataContent);

            return $processedContent;
        }

        // Return original content if no CDATA
        return $this->unescapeSpecialChars($content);
    }

    /**
     * Unescape special characters (backslashes, quotes, etc.)
     */
    private function unescapeSpecialChars(string $content): string
    {
        // Restore escaped quotes and backslashes
        $unescaped = str_replace(
            ['\\"', "\\'", '\\\\'],
            ['"', "'", '\\'],
            $content
        );

        return $unescaped;
    }

    /**
     * Clean tag content (whitespace, HTML entities, etc.)
     */
    private function cleanContent(string $content): string
    {
        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_XML1);

        // Remove leading and trailing whitespace
        return trim($content);
    }

    /**
     * Call callback for all processed items
     */
    private function notifyAllProcessedItems(): void
    {
        if (! $this->nodeCompleteCallback) {
            return;
        }

        // Process if <item> tags exist
        if (preg_match_all('/<item>(.*?)<\/item>/s', $this->fullResponse, $itemMatches)) {
            foreach ($itemMatches[1] as $itemContent) {
                // Extract <key> and <trx> from each <item>
                if (
                    preg_match('/<key>(.*?)<\/key>/s', $itemContent, $keyMatch) &&
                    preg_match('/<trx>(.*?)<\/trx>/s', $itemContent, $trxMatch)
                ) {

                    $key = $this->cleanContent($keyMatch[1]);
                    $trxContent = $this->processTrxContent($trxMatch[1]);

                    // Call callback
                    call_user_func($this->nodeCompleteCallback, 'item', $itemContent, []);
                }
            }
        }

        // Process if <key> tags exist
        if (! empty($this->parsedData['key'])) {
            foreach ($this->parsedData['key'] as $keyData) {
                call_user_func($this->nodeCompleteCallback, 'key', $keyData['content'], []);
            }
        }

        // Process if <trx> tags exist
        if (! empty($this->parsedData['trx'])) {
            foreach ($this->parsedData['trx'] as $trxData) {
                call_user_func($this->nodeCompleteCallback, 'trx', $trxData['content'], []);
            }
        }
    }

    /**
     * Return parsed data
     */
    public function getParsedData(): array
    {
        return $this->parsedData;
    }

    /**
     * Return CDATA cache (for accessing original translation content)
     */
    public function getCdataCache(): string
    {
        return $this->cdataCache;
    }

    /**
     * Return full response
     */
    public function getFullResponse(): string
    {
        return $this->fullResponse;
    }
}
