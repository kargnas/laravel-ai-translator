<?php

namespace Kargnas\LaravelAiTranslator\Support\Parsers;

class XMLParser
{
    private array $parsedData = [];

    public function parse(string $xml): void
    {
        $this->parsedData = ['key' => [], 'trx' => []];
        
        // Simple pattern matching for <item> tags
        if (preg_match_all('/<item>(.*?)<\/item>/s', $xml, $matches)) {
            foreach ($matches[1] as $itemContent) {
                $this->processItem($itemContent);
            }
        }
    }

    private function processItem(string $itemContent): void
    {
        // Extract key and translation
        if (preg_match('/<key>(.*?)<\/key>/s', $itemContent, $keyMatch) &&
            preg_match('/<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $itemContent, $trxMatch)) {
            
            $key = trim(html_entity_decode($keyMatch[1], ENT_QUOTES | ENT_XML1));
            $trx = $this->unescapeContent($trxMatch[1]);
            
            $this->parsedData['key'][] = ['content' => $key];
            $this->parsedData['trx'][] = ['content' => $trx];
        }
    }

    private function unescapeContent(string $content): string
    {
        return str_replace(
            ['\\"', "\\'", '\\\\'],
            ['"', "'", '\\'],
            trim($content)
        );
    }

    public function getParsedData(): array
    {
        return $this->parsedData;
    }
}