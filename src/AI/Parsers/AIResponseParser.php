<?php

namespace Kargnas\LaravelAiTranslator\AI\Parsers;

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

/**
 * 완전히 새로 작성된 AI 응답 파서 클래스
 * - CDATA 직접 추출 및 처리
 * - 모든 특수 문자와 HTML 태그 보존
 * - 안정적인 XML 파싱
 */
class AIResponseParser
{
    // XML 파서
    private XMLParser $xmlParser;

    // 번역된 항목 저장
    private array $translatedItems = [];

    // 처리된 키 추적
    private array $processedKeys = [];

    // 디버그 모드
    private bool $debug = false;

    // 번역 완료 콜백
    private $translatedCallback = null;

    // 전체 응답 저장
    private string $fullResponse = '';

    // 새로운 번역 시작 항목 찾기 (아직 시작되지 않은 키)
    private array $startedKeys = [];

    /**
     * 생성자
     *
     * @param  callable|null  $translatedCallback  번역 항목 완료시 호출할 콜백
     * @param  bool  $debug  디버그 모드 활성화 여부
     */
    public function __construct(?callable $translatedCallback = null, bool $debug = false)
    {
        $this->xmlParser = new XMLParser($debug);
        $this->translatedCallback = $translatedCallback;
        $this->debug = $debug;
        $this->xmlParser->onNodeComplete([$this, 'handleNodeComplete']);
    }

    /**
     * 청크 파싱 - 모든 청크를 누적
     *
     * @param  string  $chunk  XML 청크
     * @return array 현재까지 파싱된 번역 항목
     */
    public function parseChunk(string $chunk): array
    {
        // 전체 응답에 청크 추가
        $this->fullResponse .= $chunk;

        // 완성된 <item> 태그 찾기
        if (preg_match_all('/<item>(.*?)<\/item>/s', $this->fullResponse, $matches)) {
            foreach ($matches[0] as $index => $fullItem) {
                // 각 <item> 내부의 <key>와 <trx> 추출
                if (
                    preg_match('/<key>(.*?)<\/key>/s', $fullItem, $keyMatch) &&
                    preg_match('/<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $fullItem, $trxMatch)
                ) {
                    $key = $this->cleanContent($keyMatch[1]);
                    $translatedText = $this->cleanContent($trxMatch[1]);

                    // 이미 처리된 키인지 확인
                    if (in_array($key, $this->processedKeys)) {
                        continue;
                    }

                    // 새 번역 항목 생성
                    $localizedString = new LocalizedString;
                    $localizedString->key = $key;
                    $localizedString->translated = $translatedText;

                    $this->translatedItems[] = $localizedString;
                    $this->processedKeys[] = $key;

                    if ($this->debug) {
                        Log::debug('AIResponseParser: Processed translation item', [
                            'key' => $key,
                            'translated_text' => $translatedText
                        ]);
                    }

                    // 처리된 항목 제거
                    $this->fullResponse = str_replace($fullItem, '', $this->fullResponse);
                }
            }
        }

        // 새로운 번역 시작 항목 찾기 (아직 시작되지 않은 키)
        if (preg_match('/<item>(?:(?!<\/item>).)*$/s', $this->fullResponse, $inProgressMatch)) {
            if (
                preg_match('/<key>(.*?)<\/key>/s', $inProgressMatch[0], $keyMatch) &&
                !in_array($this->cleanContent($keyMatch[1]), $this->processedKeys)
            ) {
                $startedKey = $this->cleanContent($keyMatch[1]);

                // 이미 started 이벤트가 발생했는지 확인하기 위한 배열
                if (!isset($this->startedKeys)) {
                    $this->startedKeys = [];
                }

                // 아직 started 이벤트가 발생하지 않은 키에 대해서만 처리
                if (!in_array($startedKey, $this->startedKeys)) {
                    $startedString = new LocalizedString;
                    $startedString->key = $startedKey;
                    $startedString->translated = '';

                    // started 상태로 콜백 호출
                    if ($this->translatedCallback) {
                        call_user_func($this->translatedCallback, $startedString, TranslationStatus::STARTED, $this->translatedItems);
                    }

                    if ($this->debug) {
                        Log::debug('AIResponseParser: Translation started', [
                            'key' => $startedKey
                        ]);
                    }

                    // started 이벤트가 발생한 키 기록
                    $this->startedKeys[] = $startedKey;
                }
            }
        }

        return $this->translatedItems;
    }

    /**
     * 특수 문자 처리
     */
    private function cleanContent(string $content): string
    {
        return trim(html_entity_decode($content, ENT_QUOTES | ENT_XML1));
    }

    /**
     * 전체 응답 파싱
     *
     * @param  string  $response  전체 응답
     * @return array 파싱된 번역 항목
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

        // 전체 응답 저장
        $this->fullResponse = $response;

        // 방법 1: 직접 CDATA 추출 시도 (가장 신뢰성 높음)
        $cdataExtracted = $this->extractCdataFromResponse($response);

        // 방법 2: 표준 XML 파서 사용
        $cleanedResponse = $this->cleanAndNormalizeXml($response);
        $this->xmlParser->parse($cleanedResponse);

        // 방법 3: 부분 응답 처리 시도 (불완전한 응답에서도 최대한 데이터 추출)
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
     * 부분 응답에서 번역 추출 시도 (응답이 불완전할 때)
     *
     * @param  string  $response  응답 텍스트
     * @return bool 추출 성공 여부
     */
    private function extractPartialTranslations(string $response): bool
    {
        // 개별 CDATA 블록 추출
        $cdataPattern = '/<!\[CDATA\[(.*?)\]\]>/s';
        if (preg_match_all($cdataPattern, $response, $cdataMatches)) {
            $cdataContents = $cdataMatches[1];

            if ($this->debug) {
                Log::debug('AIResponseParser: Found individual CDATA blocks', [
                    'count' => count($cdataContents),
                ]);
            }

            // key 태그 추출
            $keyPattern = '/<key>(.*?)<\/key>/s';
            if (preg_match_all($keyPattern, $response, $keyMatches)) {
                $keys = array_map([$this, 'cleanupSpecialChars'], $keyMatches[1]);

                // 키와 CDATA 내용이 같은 수인 경우에만 처리
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
     * 원본 응답에서 CDATA 직접 추출 시도
     *
     * @param  string  $response  전체 응답
     * @return bool 추출 성공 여부
     */
    private function extractCdataFromResponse(string $response): bool
    {
        // 다중 항목 처리: <item> 태그에서 키와 번역 추출
        $itemPattern = '/<item>\s*<key>(.*?)<\/key>\s*<trx><!\[CDATA\[(.*?)\]\]><\/trx>\s*<\/item>/s';
        if (preg_match_all($itemPattern, $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $i => $match) {
                if (isset($match[1]) && isset($match[2]) && !empty($match[1]) && !empty($match[2])) {
                    $key = trim($match[1]);
                    $translatedText = $this->cleanupSpecialChars($match[2]);

                    // 이미 처리된 키인지 확인
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

            // 진행 중인 항목 찾기
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
     * 특수 문자 처리
     *
     * @param  string  $content  처리할 내용
     * @return string 처리된 내용
     */
    private function cleanupSpecialChars(string $content): string
    {
        // 이스케이프된 따옴표와 백슬래시 복원
        return str_replace(
            ['\\"', "\\'", '\\\\'],
            ['"', "'", '\\'],
            $content
        );
    }

    /**
     * XML 정리 및 정규화
     *
     * @param  string  $xml  정리할 XML
     * @return string 정리된 XML
     */
    private function cleanAndNormalizeXml(string $xml): string
    {
        // 실제 XML 태그 시작 이전의 내용 제거
        $firstTagPos = strpos($xml, '<');
        if ($firstTagPos > 0) {
            $xml = substr($xml, $firstTagPos);
        }

        // 마지막 XML 태그 이후의 내용 제거
        $lastTagPos = strrpos($xml, '>');
        if ($lastTagPos !== false && $lastTagPos < strlen($xml) - 1) {
            $xml = substr($xml, 0, $lastTagPos + 1);
        }

        // 특수 문자 처리
        $xml = $this->cleanupSpecialChars($xml);

        // 루트 태그 누락 시 추가
        if (!preg_match('/^\s*<\?xml|^\s*<translations/i', $xml)) {
            $xml = '<translations>' . $xml . '</translations>';
        }

        // CDATA 누락된 경우 추가
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
     * 노드 완료 처리 콜백
     *
     * @param  string  $tagName  태그 이름
     * @param  string  $content  태그 내용
     * @param  array  $attributes  태그 속성
     */
    public function handleNodeComplete(string $tagName, string $content, array $attributes): void
    {
        // <trx> 태그 처리 (단일 항목 경우)
        if ($tagName === 'trx' && !isset($this->processedKeys[0])) {
            // CDATA 캐시 참조 (전체 내용 있을 경우)
            $cdataCache = $this->xmlParser->getCdataCache();
            if (!empty($cdataCache)) {
                $content = $cdataCache;
            }

            $this->createTranslationItem('test', $content);
        }
        // <item> 태그 처리 (다중 항목 경우)
        elseif ($tagName === 'item') {
            $parsedData = $this->xmlParser->getParsedData();

            // 모든 키와 번역 항목이 있는지 확인
            if (
                isset($parsedData['key']) && !empty($parsedData['key']) &&
                isset($parsedData['trx']) && !empty($parsedData['trx']) &&
                count($parsedData['key']) === count($parsedData['trx'])
            ) {
                // 파싱된 모든 키와 번역 항목 처리
                foreach ($parsedData['key'] as $i => $keyData) {
                    if (isset($parsedData['trx'][$i])) {
                        $key = $keyData['content'];
                        $translated = $parsedData['trx'][$i]['content'];

                        // 키가 비어있지 않고 중복되지 않은 경우에만 처리
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
     * 번역 항목 생성
     *
     * @param  string  $key  키
     * @param  string  $translated  번역된 내용
     */
    private function createTranslationItem(string $key, string $translated): void
    {
        if (empty($key) || empty($translated) || in_array($key, $this->processedKeys)) {
            return;
        }

        $localizedString = new LocalizedString;
        $localizedString->key = $key;
        $localizedString->translated = $translated;

        $this->translatedItems[] = $localizedString;
        $this->processedKeys[] = $key;

        if ($this->debug) {
            Log::debug('AIResponseParser: Created translation item', [
                'key' => $key,
                'translated_length' => strlen($translated)
            ]);
        }
    }

    /**
     * 번역된 항목 반환
     *
     * @return array 번역된 항목 배열
     */
    public function getTranslatedItems(): array
    {
        return $this->translatedItems;
    }

    /**
     * 파서 초기화
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
