<?php

namespace Kargnas\LaravelAiTranslator\AI\Parsers;

use Illuminate\Support\Facades\Log;

class XMLParser
{
    // 전체 XML 응답 저장
    private string $fullResponse = '';

    // 파싱된 데이터
    private array $parsedData = [];

    // 디버그 모드
    private bool $debug = false;

    // 노드 완성시 콜백
    private $nodeCompleteCallback = null;

    // CDATA 내용 캐시 (완전한 CDATA 추출용)
    private string $cdataCache = '';

    // 임시 XML 저장용 파일 경로
    private string $tempFile = '';

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
        // 랜덤 임시 파일 생성
        $this->tempFile = sys_get_temp_dir().'/xml_parser_'.md5(uniqid('', true)).'.xml';
    }

    public function __destruct()
    {
        // 임시 파일 정리
        if (file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    /**
     * 노드 완료 콜백 설정
     */
    public function onNodeComplete(callable $callback): void
    {
        $this->nodeCompleteCallback = $callback;
    }

    /**
     * 파서 상태 초기화
     */
    public function reset(): void
    {
        $this->fullResponse = '';
        $this->parsedData = [];
        $this->cdataCache = '';
    }

    /**
     * 청크 데이터 추가하고 전체 응답 누적
     */
    public function addChunk(string $chunk): void
    {
        $this->fullResponse .= $chunk;
    }

    /**
     * 전체 XML 문자열 파싱 (스트리밍 대신 완전한 문자열 처리)
     */
    public function parse(string $xml): void
    {
        $this->reset();
        $this->fullResponse = $xml;
        $this->processFullResponse();
    }

    /**
     * 전체 응답 처리 (표준 XML 파서 우선 사용)
     */
    private function processFullResponse(): void
    {
        if ($this->debug) {
            Log::debug('XMLParser: Processing full response', [
                'response_length' => strlen($this->fullResponse),
                'has_translations_tag' => strpos($this->fullResponse, '<translations>') !== false,
                'has_item_tags' => strpos($this->fullResponse, '<item>') !== false,
            ]);
        }

        // 방법 1: 표준 XML 파서 사용 시도
        if (! $this->tryStandardXmlParsing()) {
            // 방법 2: 실패할 경우 정규식 기반 파싱으로 대체
            if ($this->debug) {
                Log::debug('XMLParser: Standard XML parsing failed, falling back to regex parsing');
            }

            // 완전한 <item> 태그 추출 먼저 시도
            if (strpos($this->fullResponse, '<item>') !== false) {
                $this->extractCompleteItems();
            }

            // 정규식으로 필요한 태그와 내용 추출
            $this->extractKeyItems();
            $this->extractTrxItems();
        }
    }

    /**
     * 표준 XML 파서를 사용한 처리 시도
     */
    private function tryStandardXmlParsing(): bool
    {
        // XML 응답 정리 및 CDATA 보존
        $xml = $this->prepareXmlForParsing($this->fullResponse);

        // XML이 비어있거나 불완전하면 건너뛰기
        if (empty($xml) || strpos($xml, '<translations') === false) {
            if ($this->debug) {
                Log::debug('XMLParser: Cannot parse with standard XML: empty or missing root element');
            }

            return false;
        }

        try {
            // 기존 libxml 에러 처리 설정 백업
            $useInternalErrors = libxml_use_internal_errors(true);

            // 임시 파일에 XML 저장
            file_put_contents($this->tempFile, $xml);

            // SimpleXML 로드
            $xmlObj = simplexml_load_file($this->tempFile, 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xmlObj === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors($useInternalErrors);

                if ($this->debug) {
                    Log::debug('XMLParser: SimpleXML load failed', [
                        'errors' => array_map(function ($error) {
                            return $error->message;
                        }, $errors),
                    ]);
                }

                return false;
            }

            // 원래 에러 처리 설정 복원
            libxml_use_internal_errors($useInternalErrors);

            // 파싱 시작
            $itemCount = 0;

            // <item> 요소 순회
            foreach ($xmlObj->item as $item) {
                $key = (string) $item->key;
                $trx = (string) $item->trx;

                if (empty($key) || empty($trx)) {
                    continue;
                }

                // 이미 처리된 키인지 확인
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
                    continue;
                }

                // 파싱된 데이터에 추가
                if (! isset($this->parsedData['key'])) {
                    $this->parsedData['key'] = [];
                }
                if (! isset($this->parsedData['trx'])) {
                    $this->parsedData['trx'] = [];
                }

                $this->parsedData['key'][] = ['content' => $key];
                $this->parsedData['trx'][] = ['content' => $trx];
                $itemCount++;

                if ($this->debug) {
                    Log::debug('XMLParser: Parsed item with SimpleXML', [
                        'key' => $key,
                        'trx_length' => strlen($trx),
                        'trx_preview' => substr($trx, 0, 50),
                    ]);
                }
            }

            return $itemCount > 0;

        } catch (\Exception $e) {
            if ($this->debug) {
                Log::debug('XMLParser: XML parsing error', [
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * 표준 XML 파싱을 위한 응답 정리
     */
    private function prepareXmlForParsing(string $xml): string
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
        $xml = $this->unescapeSpecialChars($xml);

        // 루트 태그 누락 시 추가
        if (! preg_match('/^\s*<\?xml|^\s*<translations/i', $xml)) {
            $xml = '<translations>'.$xml.'</translations>';
        }

        // XML 선언 추가 (없는 경우)
        if (strpos($xml, '<?xml') === false) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;
        }

        return $xml;
    }

    /**
     * 완전한 <item> 태그 추출 (다중 항목 처리)
     */
    private function extractCompleteItems(): void
    {
        // 여러 패턴으로 <item> 태그 시도
        $patterns = [
            // 표준 패턴 (줄바꿈 있는 경우)
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)<\/trx>\s*<\/item>/s',

            // 한 줄 패턴
            '/<item><key>(.*?)<\/key><trx>(.*?)<\/trx><\/item>/s',

            // 태그 사이에 공백 있는 경우
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)<\/trx>\s*<\/item>/s',

            // 닫는 태그 없는 경우를 처리
            '/<item>\s*<key>(.*?)<\/key>\s*<trx>(.*?)(?:<\/trx>|<item>)/s',

            // CDATA를 직접 찾는 패턴
            '/<key>(.*?)<\/key>\s*<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s',

            // 단순화된 패턴
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

                // 각 항목에 대해 처리
                foreach ($matches as $i => $match) {
                    if (count($match) < 3) {
                        continue; // 패턴 매치 실패
                    }

                    $key = $this->cleanContent($match[1]);
                    $trxContent = $match[2];

                    // 이미 처리된 키인지 확인
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
                        continue; // 이미 처리된 키 스킵
                    }

                    // CDATA 내용 추출
                    $trxProcessed = $this->processTrxContent($trxContent);

                    // 파싱된 데이터에 추가
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

        // 추가: 특별한 케이스 - CDATA 직접 추출 시도
        if (preg_match_all('/<key>(.*?)<\/key>.*?<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $this->fullResponse, $matches, PREG_SET_ORDER)) {
            if ($this->debug) {
                Log::debug('XMLParser: Direct CDATA extraction attempt', [
                    'found' => count($matches),
                ]);
            }

            foreach ($matches as $i => $match) {
                $key = $this->cleanContent($match[1]);
                $cdata = $match[2];

                // 이미 처리된 키인지 확인
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
                    continue; // 이미 처리된 키 스킵
                }

                // 파싱된 데이터에 추가
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
     * 전체 XML에서 <key> 태그 추출
     */
    private function extractKeyItems(): void
    {
        if (preg_match_all('/<key>(.*?)<\/key>/s', $this->fullResponse, $matches)) {
            $this->parsedData['key'] = [];

            foreach ($matches[1] as $keyContent) {
                $content = $this->cleanContent($keyContent);
                $this->parsedData['key'][] = ['content' => $content];

                // 디버그 로그 제거
            }
        }
    }

    /**
     * 전체 XML에서 <trx> 태그와 CDATA 내용 추출
     */
    private function extractTrxItems(): void
    {
        // CDATA를 포함한 <trx> 태그 내용 추출 (greedy 패턴 사용)
        $pattern = '/<trx>(.*?)<\/trx>/s';

        if (preg_match_all($pattern, $this->fullResponse, $matches)) {
            $this->parsedData['trx'] = [];

            foreach ($matches[1] as $trxContent) {
                // CDATA 추출 및 처리
                $processedContent = $this->processTrxContent($trxContent);
                $this->parsedData['trx'][] = ['content' => $processedContent];

                // CDATA 내용 캐시에 저장 (후처리용)
                $this->cdataCache = $processedContent;

                // 디버그 로그 제거
            }
        }
    }

    /**
     * <trx> 태그 내용 처리 및 CDATA 추출
     */
    private function processTrxContent(string $content): string
    {
        // CDATA 내용 추출
        if (preg_match('/<!\[CDATA\[(.*)\]\]>/s', $content, $cdataMatches)) {
            $cdataContent = $cdataMatches[1];

            // 특수 문자 이스케이프 처리
            $processedContent = $this->unescapeSpecialChars($cdataContent);

            // 디버그 로그 제거

            return $processedContent;
        }

        // CDATA가 없는 경우 원본 내용 반환
        return $this->unescapeSpecialChars($content);
    }

    /**
     * 특수 문자 이스케이프 해제 (백슬래시, 따옴표 등)
     */
    private function unescapeSpecialChars(string $content): string
    {
        // 이스케이프된 따옴표와 백슬래시 복원
        $unescaped = str_replace(
            ['\\"', "\\'", '\\\\'],
            ['"', "'", '\\'],
            $content
        );

        return $unescaped;
    }

    /**
     * 태그 내용 정리 (공백, HTML 엔티티 등)
     */
    private function cleanContent(string $content): string
    {
        // HTML 엔티티 디코딩
        $content = html_entity_decode($content, ENT_QUOTES | ENT_XML1);

        // 앞뒤 공백 제거
        return trim($content);
    }

    /**
     * 처리된 모든 항목에 대해 콜백 호출
     */
    private function notifyAllProcessedItems(): void
    {
        if (! $this->nodeCompleteCallback) {
            return;
        }

        // <item> 태그가 존재하는 경우 처리
        if (preg_match_all('/<item>(.*?)<\/item>/s', $this->fullResponse, $itemMatches)) {
            foreach ($itemMatches[1] as $itemContent) {
                // 각 <item> 내부의 <key>와 <trx> 추출
                if (preg_match('/<key>(.*?)<\/key>/s', $itemContent, $keyMatch) &&
                    preg_match('/<trx>(.*?)<\/trx>/s', $itemContent, $trxMatch)) {

                    $key = $this->cleanContent($keyMatch[1]);
                    $trxContent = $this->processTrxContent($trxMatch[1]);

                    // 콜백 호출
                    call_user_func($this->nodeCompleteCallback, 'item', $itemContent, []);
                }
            }
        }

        // <key> 태그가 존재하는 경우 처리
        if (! empty($this->parsedData['key'])) {
            foreach ($this->parsedData['key'] as $keyData) {
                call_user_func($this->nodeCompleteCallback, 'key', $keyData['content'], []);
            }
        }

        // <trx> 태그가 존재하는 경우 처리
        if (! empty($this->parsedData['trx'])) {
            foreach ($this->parsedData['trx'] as $trxData) {
                call_user_func($this->nodeCompleteCallback, 'trx', $trxData['content'], []);
            }
        }
    }

    /**
     * 파싱된 데이터 반환
     */
    public function getParsedData(): array
    {
        return $this->parsedData;
    }

    /**
     * CDATA 캐시 반환 (원본 번역 내용 접근용)
     */
    public function getCdataCache(): string
    {
        return $this->cdataCache;
    }

    /**
     * 전체 응답 반환
     */
    public function getFullResponse(): string
    {
        return $this->fullResponse;
    }
}
