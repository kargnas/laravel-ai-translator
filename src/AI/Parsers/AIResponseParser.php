<?php

namespace Kargnas\LaravelAiTranslator\AI\Parsers;

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

/**
 * AI Response Parser Class - Complete Rewrite
 * - Direct CDATA extraction and processing
 * - Preserves all special characters and HTML tags
 * - Stable XML parsing
 */
class AIResponseParser
{
    // XML Parser
    private XMLParser $xmlParser;

    // Store translated items
    private array $translatedItems = [];

    // Track processed keys
    private array $processedKeys = [];

    // Debug mode
    private bool $debug = false;

    // Translation completion callback
    private $translatedCallback = null;

    // Store full response
    private string $fullResponse = '';

    // Track started keys (not yet started)
    private array $startedKeys = [];

    /**
     * Constructor
     *
     * @param  callable|null  $translatedCallback  Callback to be called when translation item is completed
     * @param  bool  $debug  Enable debug mode
     */
    public function __construct(?callable $translatedCallback = null, bool $debug = false)
    {
        $this->xmlParser = new XMLParser($debug);
        $this->translatedCallback = $translatedCallback;
        $this->debug = $debug;
        $this->xmlParser->onNodeComplete([$this, 'handleNodeComplete']);
    }

    /**
     * Parse chunks - accumulate all chunks
     *
     * @param  string  $chunk  XML chunk
     * @return array Currently parsed translation items
     */
    public function parseChunk(string $chunk): array
    {
        // Add chunk to full response
        $this->fullResponse .= $chunk;

        // Find completed <item> tags
        if (preg_match_all('/<item>(.*?)<\/item>/s', $this->fullResponse, $matches)) {
            foreach ($matches[0] as $index => $fullItem) {
                // Extract <key> and <trx> from each <item>
                if (
                    preg_match('/<key>(.*?)<\/key>/s', $fullItem, $keyMatch) &&
                    preg_match('/<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $fullItem, $trxMatch)
                ) {
                    $key = $this->cleanContent($keyMatch[1]);
                    $translatedText = $this->cleanContent($trxMatch[1]);

                    // Check if key is already processed
                    if (in_array($key, $this->processedKeys)) {
                        continue;
                    }

                    // Create new translation item
                    $localizedString = new LocalizedString;
                    $localizedString->key = $key;
                    $localizedString->translated = $translatedText;

                    // Process comment tag if exists
                    if (preg_match('/<comment>(.*?)<\/comment>/s', $fullItem, $commentMatch)) {
                        $localizedString->comment = $this->cleanContent($commentMatch[1]);
                    }

                    $this->translatedItems[] = $localizedString;
                    $this->processedKeys[] = $key;

                    if ($this->debug) {
                        Log::debug('AIResponseParser: Processed translation item', [
                            'key' => $key,
                            'translated_text' => $translatedText,
                            'comment' => $localizedString->comment ?? null
                        ]);
                    }

                    // Remove processed item
                    $this->fullResponse = str_replace($fullItem, '', $this->fullResponse);
                }
            }
        }

        // Find new translation start items (not yet started keys)
        if (preg_match('/<item>(?:(?!<\/item>).)*$/s', $this->fullResponse, $inProgressMatch)) {
            if (
                preg_match('/<key>(.*?)<\/key>/s', $inProgressMatch[0], $keyMatch) &&
                !in_array($this->cleanContent($keyMatch[1]), $this->processedKeys)
            ) {
                $startedKey = $this->cleanContent($keyMatch[1]);

                // Array to check if started event has occurred
                if (!isset($this->startedKeys)) {
                    $this->startedKeys = [];
                }

                // Process only for keys that haven't had started event
                if (!in_array($startedKey, $this->startedKeys)) {
                    $startedString = new LocalizedString;
                    $startedString->key = $startedKey;
                    $startedString->translated = '';

                    // Call callback with started status
                    if ($this->translatedCallback) {
                        call_user_func($this->translatedCallback, $startedString, TranslationStatus::STARTED, $this->translatedItems);
                    }

                    if ($this->debug) {
                        Log::debug('AIResponseParser: Translation started', [
                            'key' => $startedKey
                        ]);
                    }

                    // Record key that had started event
                    $this->startedKeys[] = $startedKey;
                }
            }
        }

        return $this->translatedItems;
    }

    /**
     * Handle special characters
     */
    private function cleanContent(string $content): string
    {
        return trim(html_entity_decode($content, ENT_QUOTES | ENT_XML1));
    }

    /**
     * Parse full response
     *
     * @param  string  $response  Full response
     * @return array Parsed translation items
     */
    public function parse(string $response): array
    {
        if ($this->debug) {
            Log::debug('AIResponseParser: Starting parsing full response', [
                'response_length' => strlen($response),
                'contains_cdata' => strpos($response, 'CDATA') !== false,
                'contains_xml' => strpos($response, '<') !== false && strpos($response, '>') !== false,
            ]);
        }

        // Store full response
        $this->fullResponse = $response;

        // Method 1: Try direct CDATA extraction (most reliable)
        $cdataExtracted = $this->extractCdataFromResponse($response);

        // Method 2: Use standard XML parser
        $cleanedResponse = $this->cleanAndNormalizeXml($response);
        $this->xmlParser->parse($cleanedResponse);

        // Method 3: Try partial response processing (extract data from incomplete responses)
        if (empty($this->translatedItems)) {
            $this->extractPartialTranslations($response);
        }

        if ($this->debug) {
            Log::debug('AIResponseParser: Parsing result', [
                'direct_cdata_extraction' => $cdataExtracted,
                'extracted_items_count' => count($this->translatedItems),
                'keys_found' => !empty($this->translatedItems) ? array_map(function ($item) {
                    return $item->key;
                }, $this->translatedItems) : [],
            ]);
        }

        return $this->translatedItems;
    }

    /**
     * Try to extract translations from partial response (when response is incomplete)
     *
     * @param  string  $response  Response text
     * @return bool Extraction success
     */
    private function extractPartialTranslations(string $response): bool
    {
        // Extract individual CDATA blocks
        $cdataPattern = '/<!\[CDATA\[(.*?)\]\]>/s';
        if (preg_match_all($cdataPattern, $response, $cdataMatches)) {
            $cdataContents = $cdataMatches[1];

            if ($this->debug) {
                Log::debug('AIResponseParser: Found individual CDATA blocks', [
                    'count' => count($cdataContents),
                ]);
            }

            // Extract key tags
            $keyPattern = '/<key>(.*?)<\/key>/s';
            if (preg_match_all($keyPattern, $response, $keyMatches)) {
                $keys = array_map([$this, 'cleanupSpecialChars'], $keyMatches[1]);

                // Process only if number of keys matches number of CDATA contents
                if (count($keys) === count($cdataContents) && count($keys) > 0) {
                    foreach ($keys as $i => $key) {
                        if (empty($key) || in_array($key, $this->processedKeys)) {
                            continue;
                        }

                        $translatedText = $this->cleanupSpecialChars($cdataContents[$i]);
                        $this->createTranslationItem($key, $translatedText);

                        if ($this->debug) {
                            Log::debug('AIResponseParser: Created translation from partial match', [
                                'key' => $key,
                                'text_preview' => substr($translatedText, 0, 30),
                            ]);
                        }
                    }

                    return count($this->translatedItems) > 0;
                }
            }
        }

        return false;
    }

    /**
     * Try to extract CDATA directly from original response
     *
     * @param  string  $response  Full response
     * @return bool Extraction success
     */
    private function extractCdataFromResponse(string $response): bool
    {
        // Process multiple items: extract key and translation from <item> tags
        $itemPattern = '/<item>\s*<key>(.*?)<\/key>\s*<trx><!\[CDATA\[(.*?)\]\]><\/trx>\s*<\/item>/s';
        if (preg_match_all($itemPattern, $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $i => $match) {
                if (isset($match[1]) && isset($match[2]) && !empty($match[1]) && !empty($match[2])) {
                    $key = trim($match[1]);
                    $translatedText = $this->cleanupSpecialChars($match[2]);

                    // Check if key is already processed
                    if (in_array($key, $this->processedKeys)) {
                        continue;
                    }

                    $localizedString = new LocalizedString;
                    $localizedString->key = $key;
                    $localizedString->translated = $translatedText;

                    $this->translatedItems[] = $localizedString;
                    $this->processedKeys[] = $key;

                    if ($this->debug) {
                        Log::debug('AIResponseParser: Extracted item directly', [
                            'key' => $key,
                            'translated_length' => strlen($translatedText),
                        ]);
                    }
                }
            }

            // Find in-progress items
            if (preg_match('/<item>(?:(?!<\/item>).)*$/s', $response, $inProgressMatch)) {
                if (
                    preg_match('/<key>(.*?)<\/key>/s', $inProgressMatch[0], $keyMatch) &&
                    !in_array($this->cleanContent($keyMatch[1]), $this->processedKeys)
                ) {
                    $inProgressKey = $this->cleanContent($keyMatch[1]);
                    $inProgressString = new LocalizedString;
                    $inProgressString->key = $inProgressKey;
                    $inProgressString->translated = '';

                    if ($this->translatedCallback) {
                        call_user_func($this->translatedCallback, $inProgressString, TranslationStatus::IN_PROGRESS, $this->translatedItems);
                    }
                }
            }

            return count($this->translatedItems) > 0;
        }

        return false;
    }

    /**
     * Handle special characters
     *
     * @param  string  $content  Content to process
     * @return string Processed content
     */
    private function cleanupSpecialChars(string $content): string
    {
        // Restore escaped quotes and backslashes
        return str_replace(
            ['\\"', "\\'", '\\\\'],
            ['"', "'", '\\'],
            $content
        );
    }

    /**
     * Clean and normalize XML
     *
     * @param  string  $xml  XML to clean
     * @return string Cleaned XML
     */
    private function cleanAndNormalizeXml(string $xml): string
    {
        // Remove content before actual XML tags start
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
        $xml = $this->cleanupSpecialChars($xml);

        // Add root tag if missing
        if (!preg_match('/^\s*<\?xml|^\s*<translations/i', $xml)) {
            $xml = '<translations>' . $xml . '</translations>';
        }

        // Add CDATA if missing
        if (preg_match('/<trx>(.*?)<\/trx>/s', $xml, $matches) && !strpos($matches[0], 'CDATA')) {
            $xml = str_replace(
                $matches[0],
                '<trx><![CDATA[' . $matches[1] . ']]></trx>',
                $xml
            );
        }

        return $xml;
    }

    /**
     * Node completion callback handler
     *
     * @param  string  $tagName  Tag name
     * @param  string  $content  Tag content
     * @param  array  $attributes  Tag attributes
     */
    public function handleNodeComplete(string $tagName, string $content, array $attributes): void
    {
        // Process <trx> tag (single item case)
        if ($tagName === 'trx' && !isset($this->processedKeys[0])) {
            // Reference CDATA cache (if full content exists)
            $cdataCache = $this->xmlParser->getCdataCache();
            if (!empty($cdataCache)) {
                $content = $cdataCache;
            }

            $this->createTranslationItem('test', $content);
        }
        // Process <item> tag (multiple items case)
        elseif ($tagName === 'item') {
            $parsedData = $this->xmlParser->getParsedData();

            // Check if all keys and translation items exist
            if (
                isset($parsedData['key']) && !empty($parsedData['key']) &&
                isset($parsedData['trx']) && !empty($parsedData['trx']) &&
                count($parsedData['key']) === count($parsedData['trx'])
            ) {
                // Process all parsed keys and translation items
                foreach ($parsedData['key'] as $i => $keyData) {
                    if (isset($parsedData['trx'][$i])) {
                        $key = $keyData['content'];
                        $translated = $parsedData['trx'][$i]['content'];

                        // Process only if key is not empty and not duplicate
                        if (!empty($key) && !empty($translated) && !in_array($key, $this->processedKeys)) {
                            $this->createTranslationItem($key, $translated);

                            if ($this->debug) {
                                Log::debug('AIResponseParser: Created translation item from parsed data', [
                                    'key' => $key,
                                    'index' => $i,
                                    'translated_length' => strlen($translated),
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Create translation item
     *
     * @param  string  $key  Key
     * @param  string  $translated  Translated content
     * @param  string|null  $comment  Optional comment
     */
    private function createTranslationItem(string $key, string $translated, ?string $comment = null): void
    {
        if (empty($key) || empty($translated) || in_array($key, $this->processedKeys)) {
            return;
        }

        $localizedString = new LocalizedString;
        $localizedString->key = $key;
        $localizedString->translated = $translated;
        $localizedString->comment = $comment;

        $this->translatedItems[] = $localizedString;
        $this->processedKeys[] = $key;

        if ($this->debug) {
            Log::debug('AIResponseParser: Created translation item', [
                'key' => $key,
                'translated_length' => strlen($translated),
                'comment' => $comment
            ]);
        }
    }

    /**
     * Get translated items
     *
     * @return array Array of translated items
     */
    public function getTranslatedItems(): array
    {
        return $this->translatedItems;
    }

    /**
     * Reset parser
     */
    public function reset(): self
    {
        $this->xmlParser->reset();
        $this->translatedItems = [];
        $this->processedKeys = [];
        $this->fullResponse = '';

        return $this;
    }
}
