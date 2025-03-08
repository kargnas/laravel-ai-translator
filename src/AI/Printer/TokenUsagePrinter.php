<?php

namespace Kargnas\LaravelAiTranslator\AI\Printer;

use Illuminate\Console\Command;

/**
 * 토큰 사용량 및 비용 계산을 출력하는 유틸리티 클래스
 */
class TokenUsagePrinter
{
    /**
     * 지원되는 모델 목록
     */
    public const MODEL_CLAUDE_3_OPUS = 'claude-3-opus-20240229';
    public const MODEL_CLAUDE_3_5_SONNET = 'claude-3-5-sonnet-20240620';
    public const MODEL_CLAUDE_3_HAIKU = 'claude-3-haiku-20240307';
    public const MODEL_CLAUDE_3_5_HAIKU = 'claude-3-5-haiku-20240307';
    public const MODEL_CLAUDE_3_7_SONNET = 'claude-3-7-sonnet-20240808';

    /**
     * 모델별 가격 정보 ($ per million tokens)
     */
    protected const MODEL_RATES = [
        self::MODEL_CLAUDE_3_OPUS => [
            'input' => 15.0,
            'output' => 75.0,
            'cache_write' => 18.75, // 25% 할증
            'cache_read' => 1.5,    // 10% (90% 할인)
            'name' => 'Claude 3 Opus'
        ],
        self::MODEL_CLAUDE_3_5_SONNET => [
            'input' => 3.0,
            'output' => 15.0,
            'cache_write' => 3.75,  // 25% 할증
            'cache_read' => 0.3,    // 10% (90% 할인)
            'name' => 'Claude 3.5 Sonnet'
        ],
        self::MODEL_CLAUDE_3_HAIKU => [
            'input' => 0.25,
            'output' => 1.25,
            'cache_write' => 0.30,  // 25% 할증
            'cache_read' => 0.03,   // 10% (90% 할인)
            'name' => 'Claude 3 Haiku'
        ],
        self::MODEL_CLAUDE_3_5_HAIKU => [
            'input' => 0.25,
            'output' => 1.25,
            'cache_write' => 0.30,  // 25% 할증
            'cache_read' => 0.03,   // 10% (90% 할인)
            'name' => 'Claude 3.5 Haiku'
        ],
        self::MODEL_CLAUDE_3_7_SONNET => [
            'input' => 3.0,
            'output' => 15.0,
            'cache_write' => 3.75,  // 25% 할증
            'cache_read' => 0.3,    // 10% (90% 할인)
            'name' => 'Claude 3.7 Sonnet'
        ],
    ];

    /**
     * 사용자 정의 색상 코드
     */
    protected $colors = [
        'gray' => "\033[38;5;245m",
        'blue' => "\033[38;5;33m",
        'green' => "\033[38;5;40m",
        'yellow' => "\033[38;5;220m",
        'purple' => "\033[38;5;141m",
        'red' => "\033[38;5;196m",
        'reset' => "\033[0m",
        'blue_bg' => "\033[48;5;24m",
        'white' => "\033[38;5;255m",
        'bold' => "\033[1m",
        'yellow_bg' => "\033[48;5;220m",
        'black' => "\033[38;5;16m",
        'line_clear' => "\033[2K\r"
    ];

    /**
     * 현재 사용 중인 모델
     */
    protected string $currentModel;

    /**
     * 원래 모델
     */
    protected ?string $originalModel = null;

    /**
     * 생성자
     */
    public function __construct(string $model = null)
    {
        // 모델이 지정되지 않으면 그대로 null 유지
        $this->currentModel = $model;
        $this->originalModel = $model;

        // 지정된 모델이 있지만 정확히 일치하지 않는 경우, 가장 유사한 모델 찾기
        if ($this->currentModel !== null && !isset(self::MODEL_RATES[$this->currentModel])) {
            $this->currentModel = $this->findClosestModel($this->currentModel);
        }
    }

    /**
     * 모델명에서 버전 번호와 접미사를 제거하고 정규화합니다.
     */
    protected function normalizeModelName(string $modelName): string
    {
        // 접미사 제거 및 소문자로 변환
        return strtolower(preg_replace('/-(?:latest|\d+)/', '', $modelName));
    }

    /**
     * 사람이 읽기 쉬운 형식의 모델명으로 변환합니다.
     */
    protected function getHumanReadableModelName(string $modelId): string
    {
        $name = $modelId;

        // 기존 모델 이름으로 매핑
        if (isset(self::MODEL_RATES[$modelId])) {
            return self::MODEL_RATES[$modelId]['name'];
        }

        // 기본 모델명 정리
        $name = preg_replace('/-(?:latest|\d+)/', '', $name);
        $name = str_replace('-', ' ', $name);
        $name = ucwords($name); // 각 단어 첫 글자 대문자로

        return $name;
    }

    /**
     * 가장 유사한 모델을 찾아 반환합니다.
     * 
     * @param string $modelName 모델 이름
     * @return string 가장 유사한 등록된 모델 이름
     */
    protected function findClosestModel(string $modelName): string
    {
        $bestMatch = self::MODEL_CLAUDE_3_5_SONNET; // 기본값
        $bestScore = 0;

        // 정규식으로 접미사 제거 (-latest 또는 -숫자 형식)
        $simplifiedName = $this->normalizeModelName($modelName);

        // 정확한 매칭부터 시도
        foreach (array_keys(self::MODEL_RATES) as $availableModel) {
            $simplifiedAvailableModel = $this->normalizeModelName($availableModel);

            // 정확한 매칭이면 바로 반환
            if ($simplifiedName === $simplifiedAvailableModel) {
                return $availableModel;
            }

            // 부분 매칭 검사
            if (
                stripos($simplifiedAvailableModel, $simplifiedName) !== false ||
                stripos($simplifiedName, $simplifiedAvailableModel) !== false
            ) {

                // 유사도 점수 계산
                $score = $this->calculateSimilarity($simplifiedName, $simplifiedAvailableModel);

                // 주요 모델 타입 일치 (haiku, sonnet, opus) 시 가산점
                if (stripos($simplifiedName, 'haiku') !== false && stripos($simplifiedAvailableModel, 'haiku') !== false) {
                    $score += 0.2;
                } elseif (stripos($simplifiedName, 'sonnet') !== false && stripos($simplifiedAvailableModel, 'sonnet') !== false) {
                    $score += 0.2;
                } elseif (stripos($simplifiedName, 'opus') !== false && stripos($simplifiedAvailableModel, 'opus') !== false) {
                    $score += 0.2;
                }

                // 버전 번호 일치 시 가산점 (3, 3.5, 3.7 등)
                if (
                    preg_match('/claude-(\d+(?:\.\d+)?)/', $simplifiedName, $inputMatches) &&
                    preg_match('/claude-(\d+(?:\.\d+)?)/', $simplifiedAvailableModel, $availableMatches)
                ) {
                    if ($inputMatches[1] === $availableMatches[1]) {
                        $score += 0.3;
                    }
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $availableModel;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * 두 문자열 간의 유사도를 계산합니다.
     * 
     * @param string $str1 첫 번째 문자열
     * @param string $str2 두 번째 문자열
     * @return float 0~1 사이의 유사도 값 (1이 완전 일치)
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        // 단순화된 유사도 계산: 공통 부분 문자열 길이 / 가장 긴 문자열 길이
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);

        // 레벤슈타인 거리 기반 유사도 계산
        $levDistance = levenshtein($str1, $str2);
        $maxLength = max(strlen($str1), strlen($str2));

        // 거리가 작을수록 유사도는 높음
        return 1 - ($levDistance / $maxLength);
    }

    /**
     * 사용 중인 모델을 변경합니다
     */
    public function setModel(string $model): self
    {
        if ($model === null) {
            $this->currentModel = null;
            $this->originalModel = null;
            return $this;
        }

        $this->originalModel = $model;

        if (isset(self::MODEL_RATES[$model])) {
            $this->currentModel = $model;
        } else {
            // 정확히 일치하지 않으면 가장 유사한 모델 찾기
            $this->currentModel = $this->findClosestModel($model);
        }
        return $this;
    }

    /**
     * 현재 사용 중인 모델에 대한 가격 정보를 반환합니다
     */
    protected function getModelRates(): array
    {
        // 모델이 지정되지 않았거나 존재하지 않으면 기본 모델 사용
        if ($this->currentModel === null || !isset(self::MODEL_RATES[$this->currentModel])) {
            return self::MODEL_RATES[self::MODEL_CLAUDE_3_5_SONNET];
        }

        return self::MODEL_RATES[$this->currentModel];
    }

    /**
     * 현재 모델의 가격 계수를 반환합니다 ($ per token)
     */
    protected function getRateInput(): float
    {
        return $this->getModelRates()['input'] / 1000000;
    }

    protected function getRateOutput(): float
    {
        return $this->getModelRates()['output'] / 1000000;
    }

    protected function getRateCacheWrite(): float
    {
        return $this->getModelRates()['cache_write'] / 1000000;
    }

    protected function getRateCacheRead(): float
    {
        return $this->getModelRates()['cache_read'] / 1000000;
    }

    protected function getModelName(): string
    {
        return $this->getModelRates()['name'];
    }

    /**
     * 토큰 사용량 요약을 출력
     */
    public function printTokenUsageSummary(Command $command, array $usage): void
    {
        $command->line("\n" . str_repeat('─', 80));
        $command->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Token Usage Summary " . $this->colors['reset']);

        // 토큰 사용량 테이블 출력
        $command->line($this->colors['yellow'] . "Input Tokens" . $this->colors['reset'] . ": " . $this->colors['green'] . $usage['input_tokens'] . $this->colors['reset']);
        $command->line($this->colors['yellow'] . "Output Tokens" . $this->colors['reset'] . ": " . $this->colors['green'] . $usage['output_tokens'] . $this->colors['reset']);
        $command->line($this->colors['yellow'] . "Cache Created" . $this->colors['reset'] . ": " . $this->colors['blue'] . $usage['cache_creation_input_tokens'] . $this->colors['reset']);
        $command->line($this->colors['yellow'] . "Cache Read" . $this->colors['reset'] . ": " . $this->colors['blue'] . $usage['cache_read_input_tokens'] . $this->colors['reset']);
        $command->line($this->colors['yellow'] . "Total Tokens" . $this->colors['reset'] . ": " . $this->colors['bold'] . $this->colors['purple'] . $usage['total_tokens'] . $this->colors['reset']);
    }

    /**
     * 비용 계산 정보를 출력
     */
    public function printCostEstimation(Command $command, array $usage): void
    {
        $command->line("\n" . str_repeat('─', 80));

        // 원래 모델 이름과 매칭된 모델이 다를 경우 정보 제공
        $modelHeader = " Cost Estimation (" . $this->getModelName() . ") ";

        // 원래 요청한 모델이 직접 매치되지 않은 경우
        if ($this->originalModel && $this->originalModel !== $this->currentModel) {
            $modelHeader = " Cost Estimation (" . $this->getModelName() . " - mapped from '{$this->originalModel}') ";
        }

        $command->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . $modelHeader . $this->colors['reset']);

        // 기본 입출력 비용
        $inputCost = $usage['input_tokens'] * $this->getRateInput();
        $outputCost = $usage['output_tokens'] * $this->getRateOutput();

        // 캐시 관련 비용 계산
        $cacheCreationCost = $usage['cache_creation_input_tokens'] * $this->getRateCacheWrite();
        $cacheReadCost = $usage['cache_read_input_tokens'] * $this->getRateCacheRead();

        // 캐시 없이 사용했을 경우 비용
        $noCacheTotalInputTokens = $usage['input_tokens'] + $usage['cache_creation_input_tokens'] + $usage['cache_read_input_tokens'];
        $noCacheInputCost = $noCacheTotalInputTokens * $this->getRateInput();
        $noCacheTotalCost = $noCacheInputCost + $outputCost;

        // 캐시 사용 총 비용
        $totalCost = $inputCost + $outputCost + $cacheCreationCost + $cacheReadCost;

        // 절약된 비용
        $savedCost = $noCacheTotalCost - $totalCost;
        $savedPercentage = $noCacheTotalCost > 0 ? ($savedCost / $noCacheTotalCost) * 100 : 0;

        // 모델 가격 정보
        $modelRates = $this->getModelRates();
        $command->line($this->colors['purple'] . "Model Pricing" . $this->colors['reset'] . ":");
        $command->line("  Input: $" . number_format($modelRates['input'], 2) . " per million tokens");
        $command->line("  Output: $" . number_format($modelRates['output'], 2) . " per million tokens");
        $command->line("  Cache Write: $" . number_format($modelRates['cache_write'], 2) . " per million tokens (25% premium)");
        $command->line("  Cache Read: $" . number_format($modelRates['cache_read'], 2) . " per million tokens (90% discount)");

        // 비용 출력
        $command->line("\n" . $this->colors['yellow'] . "Your Cost Breakdown" . $this->colors['reset'] . ":");
        $command->line("  Regular Input Cost: $" . number_format($inputCost, 6));
        $command->line("  Cache Creation Cost: $" . number_format($cacheCreationCost, 6) . " (25% premium over regular input)");
        $command->line("  Cache Read Cost: $" . number_format($cacheReadCost, 6) . " (90% discount from regular input)");
        $command->line("  Output Cost: $" . number_format($outputCost, 6));
        $command->line("  Total Cost: $" . number_format($totalCost, 6));

        // 비용 절약 정보 추가
        if ($usage['cache_read_input_tokens'] > 0) {
            $command->line("\n" . $this->colors['green'] . $this->colors['bold'] . "Cache Savings" . $this->colors['reset']);
            $command->line("  Cost without Caching: $" . number_format($noCacheTotalCost, 6));
            $command->line("  Saved Amount: $" . number_format($savedCost, 6) . " (" . number_format($savedPercentage, 2) . "% reduction)");
        }
    }

    /**
     * 다른 모델과의 비용 비교 정보를 출력합니다
     */
    public function printModelComparison(Command $command, array $usage): void
    {
        $command->line("\n" . str_repeat('─', 80));
        $command->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Model Cost Comparison " . $this->colors['reset']);

        $currentModel = $this->currentModel;
        $comparison = [];

        foreach (self::MODEL_RATES as $model => $rates) {
            // 임시로 모델 변경
            $this->currentModel = $model;

            // 기본 입출력 비용
            $inputCost = $usage['input_tokens'] * $this->getRateInput();
            $outputCost = $usage['output_tokens'] * $this->getRateOutput();

            // 캐시 관련 비용 계산
            $cacheCreationCost = $usage['cache_creation_input_tokens'] * $this->getRateCacheWrite();
            $cacheReadCost = $usage['cache_read_input_tokens'] * $this->getRateCacheRead();

            // 캐시 사용 총 비용
            $totalCost = $inputCost + $outputCost + $cacheCreationCost + $cacheReadCost;

            // 비교 데이터 저장
            $comparison[$model] = [
                'name' => $rates['name'],
                'total_cost' => $totalCost,
                'input_cost' => $inputCost,
                'output_cost' => $outputCost,
                'cache_write_cost' => $cacheCreationCost,
                'cache_read_cost' => $cacheReadCost
            ];
        }

        // 원래 모델로 복원
        $this->currentModel = $currentModel;

        // 테이블 헤더
        $command->line("");
        $command->line($this->colors['bold'] . "MODEL" . str_repeat(' ', 20) . "TOTAL COST" . str_repeat(' ', 5) . "SAVINGS vs CURRENT" . $this->colors['reset']);
        $command->line(str_repeat('─', 80));

        // 현재 모델의 비용
        $currentModelCost = isset($comparison[$currentModel]) ? $comparison[$currentModel]['total_cost'] : 0;

        // 모델별 비용 비교 테이블 출력 (비용 기준 오름차순 정렬)
        uasort($comparison, function ($a, $b) {
            return $a['total_cost'] <=> $b['total_cost'];
        });

        foreach ($comparison as $model => $data) {
            $isCurrentModel = ($model === $currentModel);

            // 모델 이름 형식
            $modelName = str_pad($data['name'], 25, ' ');
            if ($isCurrentModel) {
                $modelName = $this->colors['green'] . "➤ " . $modelName . $this->colors['reset'];
            } else {
                $modelName = "  " . $modelName;
            }

            // 비용 형식
            $costStr = "$" . str_pad(number_format($data['total_cost'], 6), 12, ' ', STR_PAD_LEFT);

            // 현재 모델과의 비용 차이
            $savingsAmount = $currentModelCost - $data['total_cost'];
            $savingsPercent = $currentModelCost > 0 ? ($savingsAmount / $currentModelCost) * 100 : 0;

            $savingsStr = "";
            if (!$isCurrentModel && $currentModelCost > 0) {
                if ($savingsAmount > 0) {
                    // 비용 절감
                    $savingsStr = $this->colors['green'] . str_pad(number_format($savingsAmount, 6), 10, ' ', STR_PAD_LEFT) .
                        " (" . number_format($savingsPercent, 1) . "% less)" . $this->colors['reset'];
                } else {
                    // 비용 증가
                    $savingsStr = $this->colors['red'] . str_pad(number_format(abs($savingsAmount), 6), 10, ' ', STR_PAD_LEFT) .
                        " (" . number_format(abs($savingsPercent), 1) . "% more)" . $this->colors['reset'];
                }
            } else {
                $savingsStr = str_pad("—", 25, ' ');
            }

            $command->line($modelName . $costStr . "  " . $savingsStr);
        }
    }

    /**
     * 토큰 사용량과 비용 계산을 모두 출력
     */
    public function printFullReport(Command $command, array $usage, bool $includeComparison = true): void
    {
        $this->printTokenUsageSummary($command, $usage);
        $this->printCostEstimation($command, $usage);

        if ($includeComparison) {
            $this->printModelComparison($command, $usage);
        }
    }
}