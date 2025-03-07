<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

/**
 * HTTP client for calling Anthropic (Claude) server
 * This class only provides basic request functionality.
 */
class AnthropicClient
{
    protected string $baseUrl = 'https://api.anthropic.com/v1';
    protected string $apiKey;
    protected string $apiVersion = '2023-06-01';

    public function __construct(string $apiKey, string $apiVersion = '2023-06-01')
    {
        $this->apiKey = $apiKey;
        $this->apiVersion = $apiVersion;
    }

    public function messages()
    {
        return new AnthropicMessages($this);
    }

    /**
     * Performs a regular HTTP request.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws \Exception When API error occurs
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type' => 'application/json',
                ])->$method("{$this->baseUrl}/{$endpoint}", $data);

        if (!$response->successful()) {
            throw new \Exception("Anthropic API error: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Performs a message generation request in streaming mode.
     *
     * @param array $data Request data
     * @param callable $onChunk Callback function to be called for each chunk
     * @return array Final response data
     * @throws \Exception When API error occurs
     */
    public function createMessageStream(array $data, callable $onChunk): array
    {
        // Set up streaming request
        $data['stream'] = true;

        // Final response data
        $finalResponse = [
            'content' => [],
            'model' => $data['model'] ?? null,
            'id' => null,
            'type' => 'message',
            'role' => null,
            'stop_reason' => null,
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0
            ],
            'thinking' => ''
        ];

        // Current content block index being processed
        $currentBlockIndex = null;
        $contentBlocks = [];

        // Execute streaming request
        $this->requestStream('post', 'messages', $data, function ($rawChunk, $parsedData) use ($onChunk, &$finalResponse, &$currentBlockIndex, &$contentBlocks) {
            // Skip if parsedData is null or not an array
            if (!is_array($parsedData)) {
                return;
            }

            // Event type check
            $eventType = $parsedData['type'] ?? '';

            // Handle message_start event
            if ($eventType === 'message_start' && isset($parsedData['message'])) {
                $message = $parsedData['message'];
                if (isset($message['id'])) {
                    $finalResponse['id'] = $message['id'];
                }
                if (isset($message['model'])) {
                    $finalResponse['model'] = $message['model'];
                }
                if (isset($message['role'])) {
                    $finalResponse['role'] = $message['role'];
                }
                if (isset($message['usage'])) {
                    $finalResponse['usage'] = $message['usage'];
                }
            }
            // Handle content_block_start event
            else if ($eventType === 'content_block_start') {
                if (isset($parsedData['index']) && isset($parsedData['content_block'])) {
                    $currentBlockIndex = $parsedData['index'];
                    $contentBlocks[$currentBlockIndex] = $parsedData['content_block'];

                    // Initialize thinking block
                    if (isset($parsedData['content_block']['type']) && $parsedData['content_block']['type'] === 'thinking') {
                        if (!isset($contentBlocks[$currentBlockIndex]['thinking'])) {
                            $contentBlocks[$currentBlockIndex]['thinking'] = '';
                        }
                    }
                    // Initialize text block
                    else if (isset($parsedData['content_block']['type']) && $parsedData['content_block']['type'] === 'text') {
                        if (!isset($contentBlocks[$currentBlockIndex]['text'])) {
                            $contentBlocks[$currentBlockIndex]['text'] = '';
                        }
                    }
                }
            }
            // Handle content_block_delta event
            else if ($eventType === 'content_block_delta' && isset($parsedData['index']) && isset($parsedData['delta'])) {
                $index = $parsedData['index'];
                $deltaType = $parsedData['delta']['type'] ?? '';

                // Process thinking_delta
                if ($deltaType === 'thinking_delta' && isset($parsedData['delta']['thinking'])) {
                    $finalResponse['thinking'] .= $parsedData['delta']['thinking'];

                    if (isset($contentBlocks[$index]) && isset($contentBlocks[$index]['type']) && $contentBlocks[$index]['type'] === 'thinking') {
                        $contentBlocks[$index]['thinking'] .= $parsedData['delta']['thinking'];
                    }
                }
                // Process text_delta
                else if ($deltaType === 'text_delta' && isset($parsedData['delta']['text'])) {
                    if (isset($contentBlocks[$index]) && isset($contentBlocks[$index]['type']) && $contentBlocks[$index]['type'] === 'text') {
                        $contentBlocks[$index]['text'] .= $parsedData['delta']['text'];
                    }
                }
            }
            // Handle content_block_stop event
            else if ($eventType === 'content_block_stop' && isset($parsedData['index'])) {
                $index = $parsedData['index'];
                if (isset($contentBlocks[$index])) {
                    $block = $contentBlocks[$index];

                    // Add content block to final response
                    if (isset($block['type'])) {
                        if ($block['type'] === 'text' && isset($block['text'])) {
                            $finalResponse['content'][] = [
                                'type' => 'text',
                                'text' => $block['text']
                            ];
                        } else if ($block['type'] === 'thinking' && isset($block['thinking'])) {
                            // thinking is stored separately
                        }
                    }
                }
            }
            // Handle message_delta event
            else if ($eventType === 'message_delta') {
                if (isset($parsedData['delta'])) {
                    if (isset($parsedData['delta']['stop_reason'])) {
                        $finalResponse['stop_reason'] = $parsedData['delta']['stop_reason'];
                    }
                }
                if (isset($parsedData['usage'])) {
                    $finalResponse['usage'] = $parsedData['usage'];
                }
            }

            // Call callback with parsed data
            $onChunk($rawChunk, $parsedData);
        });

        return $finalResponse;
    }

    /**
     * Performs a streaming HTTP request.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param callable $onChunk Callback function to be called for each chunk
     * @return void
     * @throws \Exception When API error occurs
     */
    public function requestStream(string $method, string $endpoint, array $data, callable $onChunk): void
    {
        // Set up streaming request
        $url = "{$this->baseUrl}/{$endpoint}";
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
            'content-type: application/json',
            'accept: application/json',
        ];

        // Initialize cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Buffer for incomplete SSE data
        $buffer = '';

        // Set up callback for chunk data processing
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk, &$buffer) {
            // Append new chunk to buffer
            $buffer .= $chunk;

            // Process complete SSE events from buffer
            $pattern = "/event: ([^\n]+)\ndata: ({.*})\n\n/";
            while (preg_match($pattern, $buffer, $matches)) {
                $eventType = $matches[1];
                $jsonData = $matches[2];

                // Parse JSON data
                $data = json_decode($jsonData, true);

                // Call callback with parsed data
                if ($data !== null) {
                    $onChunk($chunk, $data);
                } else {
                    // If JSON parsing fails, pass the raw chunk
                    $onChunk($chunk, null);
                }

                // Remove processed event from buffer
                $buffer = str_replace($matches[0], '', $buffer);
            }

            return strlen($chunk);
        });

        // Execute request
        $result = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Anthropic API streaming error: {$error}");
        }

        // Check HTTP status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            curl_close($ch);
            throw new \Exception("Anthropic API streaming error: HTTP {$httpCode}");
        }

        // Close cURL
        curl_close($ch);
    }
}