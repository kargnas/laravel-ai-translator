<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI 서버로 호출하는 HTTP 클라이언트
 */
class OpenAIClient
{
    protected string $baseUrl = 'https://api.openai.com/v1';

    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * 일반 HTTP 요청을 수행합니다.
     *
     * @param  string  $method  HTTP 메소드
     * @param  string  $endpoint  API 엔드포인트
     * @param  array  $data  요청 데이터
     * @return array 응답 데이터
     *
     * @throws \Exception API 오류 발생 시
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ])->$method("{$this->baseUrl}/{$endpoint}", $data);

        if (! $response->successful()) {
            throw new \Exception("OpenAI API error: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * 메시지 생성 요청을 스트리밍 모드로 수행합니다.
     *
     * @param  array  $data  요청 데이터
     * @param  callable  $onChunk  청크 데이터를 받을 때마다 호출될 콜백 함수
     * @return array 최종 응답 데이터
     *
     * @throws \Exception API 오류 발생 시
     */
    public function createChatStream(array $data, ?callable $onChunk = null): array
    {
        // 스트리밍 요청 설정
        $data['stream'] = true;

        // 최종 응답 데이터
        $finalResponse = [
            'id' => null,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $data['model'] ?? null,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                    ],
                    'finish_reason' => null,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];

        // 스트리밍 요청 실행
        $this->requestStream('post', 'chat/completions', $data, function ($chunk) use ($onChunk, &$finalResponse) {
            // 청크 데이터 처리
            if ($chunk && trim($chunk) !== '') {
                // 여러 줄의 데이터 처리
                $lines = explode("\n", $chunk);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // SSE 형식 처리 (data: 로 시작하는 라인)
                    if (strpos($line, 'data: ') === 0) {
                        $jsonData = substr($line, 6); // 'data: ' 제거

                        // '[DONE]' 메시지 처리
                        if (trim($jsonData) === '[DONE]') {
                            continue;
                        }

                        // JSON 디코딩
                        $data = json_decode($jsonData, true);

                        if (json_last_error() === JSON_ERROR_NONE && $data) {
                            // 메타데이터 업데이트
                            if (isset($data['id']) && ! $finalResponse['id']) {
                                $finalResponse['id'] = $data['id'];
                            }

                            if (isset($data['model'])) {
                                $finalResponse['model'] = $data['model'];
                            }

                            // 콘텐츠 처리
                            if (isset($data['choices']) && is_array($data['choices']) && ! empty($data['choices'])) {
                                foreach ($data['choices'] as $choice) {
                                    if (isset($choice['delta']['content'])) {
                                        $content = $choice['delta']['content'];

                                        // 콘텐츠 추가
                                        $finalResponse['choices'][0]['message']['content'] .= $content;
                                    }

                                    if (isset($choice['finish_reason'])) {
                                        $finalResponse['choices'][0]['finish_reason'] = $choice['finish_reason'];
                                    }
                                }
                            }

                            // 콜백 호출
                            if ($onChunk) {
                                $onChunk($line, $data);
                            }
                        }
                    } elseif (strpos($line, 'event: ') === 0) {
                        // 이벤트 처리 (필요한 경우)
                        continue;
                    }
                }
            }
        });

        return $finalResponse;
    }

    /**
     * 스트리밍 HTTP 요청을 수행합니다.
     *
     * @param  string  $method  HTTP 메소드
     * @param  string  $endpoint  API 엔드포인트
     * @param  array  $data  요청 데이터
     * @param  callable  $onChunk  청크 데이터를 받을 때마다 호출될 콜백 함수
     *
     * @throws \Exception API 오류 발생 시
     */
    public function requestStream(string $method, string $endpoint, array $data, callable $onChunk): void
    {
        // 스트리밍 요청 설정
        $url = "{$this->baseUrl}/{$endpoint}";
        $headers = [
            'Authorization: Bearer '.$this->apiKey,
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];

        // cURL 초기화
        $ch = curl_init();

        // cURL 옵션 설정
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // 청크 데이터 처리를 위한 콜백 설정
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk) {
            $onChunk($data);

            return strlen($data);
        });

        // 요청 실행
        $result = curl_exec($ch);

        // 오류 확인
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("OpenAI API streaming error: {$error}");
        }

        // HTTP 상태 코드 확인
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            curl_close($ch);
            throw new \Exception("OpenAI API streaming error: HTTP {$httpCode}");
        }

        // cURL 종료
        curl_close($ch);
    }
}
