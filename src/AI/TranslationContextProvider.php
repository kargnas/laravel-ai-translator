<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

/**
 * Purpose: Provides global translation context for improving translation consistency across files
 * Objectives:
 * - Collect existing translations from already translated files
 * - Include both source and target language strings for context
 * - Prioritize most relevant translations for context
 * - Manage context size to prevent context window overflows
 */
class TranslationContextProvider
{
    /**
     * Get global translation context for improving consistency
     * 
     * @param string $sourceLocale Source language locale code
     * @param string $targetLocale Target language locale code
     * @param string $currentFilePath Current file being translated
     * @param int $maxContextItems Maximum number of context items to include (to prevent context overflow)
     * @return array Context data organized by file with both source and target strings
     */
    public function getGlobalTranslationContext(
        string $sourceLocale,
        string $targetLocale,
        string $currentFilePath,
        int $maxContextItems = 100
    ): array {
        // 언어 파일이 위치한 기본 디렉토리 경로
        $langDirectory = config('ai-translator.source_directory');

        // 소스 및 타겟 언어 디렉토리 경로 구성
        $sourceLocaleDir = $this->getLanguageDirectory($langDirectory, $sourceLocale);
        $targetLocaleDir = $this->getLanguageDirectory($langDirectory, $targetLocale);

        \Log::debug("TranslationContext: 경로 구성 - 소스: {$sourceLocaleDir}, 타겟: {$targetLocaleDir}");

        // 소스 디렉토리가 없으면 빈 배열 반환
        if (!is_dir($sourceLocaleDir)) {
            \Log::warning("TranslationContext: 소스 디렉토리가 없음: {$sourceLocaleDir}");
            return [];
        }

        $currentFileName = basename($currentFilePath);
        $context = [];
        $totalContextItems = 0;
        $processedFiles = 0;

        // 소스 디렉토리의 모든 PHP 파일 가져오기
        $sourceFiles = glob("{$sourceLocaleDir}/*.php");

        // 파일이 없는 경우 빈 배열 반환
        if (empty($sourceFiles)) {
            \Log::debug("TranslationContext: 소스 디렉토리에 PHP 파일이 없음: {$sourceLocaleDir}");
            return [];
        }

        \Log::debug("TranslationContext: {$sourceLocaleDir}에서 " . count($sourceFiles) . "개의 PHP 파일 발견");

        // 유사한 이름의 파일을 먼저 처리하여 컨텍스트 관련성 향상
        usort($sourceFiles, function ($a, $b) use ($currentFileName) {
            $similarityA = similar_text($currentFileName, basename($a));
            $similarityB = similar_text($currentFileName, basename($b));
            return $similarityB <=> $similarityA;
        });

        foreach ($sourceFiles as $sourceFile) {
            // 현재 파일 건너뛰기
            if (basename($sourceFile) === $currentFileName) {
                continue;
            }

            // 최대 컨텍스트 항목 수를 초과하면 중단
            if ($totalContextItems >= $maxContextItems) {
                \Log::debug("TranslationContext: 최대 항목 수({$maxContextItems})에 도달하여 중단");
                break;
            }

            try {
                // 타겟 파일 경로 확인
                $targetFile = $targetLocaleDir . '/' . basename($sourceFile);
                $hasTargetFile = file_exists($targetFile);

                // 소스 파일에서 원본 문자열 가져오기
                $sourceTransformer = new PHPLangTransformer($sourceFile);
                $sourceStrings = $sourceTransformer->flatten();

                // 소스 파일이 비어있으면 건너뛰기
                if (empty($sourceStrings)) {
                    \Log::debug("TranslationContext: 소스 파일이 비어있음: " . basename($sourceFile));
                    continue;
                }

                // 타겟 파일이 존재하면 타겟 문자열 가져오기
                $targetStrings = [];
                if ($hasTargetFile) {
                    $targetTransformer = new PHPLangTransformer($targetFile);
                    $targetStrings = $targetTransformer->flatten();
                } else {
                    \Log::debug("TranslationContext: 타겟 파일 없음, 소스만 사용: {$targetFile}");
                }

                // 파일당 최대 항목 수 제한
                $maxPerFile = min(20, intval($maxContextItems / count($sourceFiles) / 2) + 1);

                // 긴 파일의 경우 우선순위가 높은 항목만 선택
                if (count($sourceStrings) > $maxPerFile) {
                    \Log::debug("TranslationContext: " . basename($sourceFile) . " - 항목 제한 적용: " . count($sourceStrings) . " → {$maxPerFile}");
                    if ($hasTargetFile && !empty($targetStrings)) {
                        // 타겟이 있는 경우 소스와 타겟 모두 우선순위 적용
                        $prioritizedItems = $this->getPrioritizedStrings($sourceStrings, $targetStrings, $maxPerFile);
                        $sourceStrings = $prioritizedItems['source'];
                        $targetStrings = $prioritizedItems['target'];
                    } else {
                        // 타겟이 없는 경우 소스만 우선순위 적용
                        $sourceStrings = $this->getPrioritizedSourceOnly($sourceStrings, $maxPerFile);
                    }
                }

                // 번역 컨텍스트 구성 - 소스 및 타겟 문자열 모두 포함
                $fileContext = [];
                foreach ($sourceStrings as $key => $sourceValue) {
                    if ($hasTargetFile && !empty($targetStrings)) {
                        // 타겟 파일이 있는 경우, 소스와 타겟 모두 포함
                        $targetValue = $targetStrings[$key] ?? null;
                        if ($targetValue !== null) {
                            $fileContext[$key] = [
                                'source' => $sourceValue,
                                'target' => $targetValue
                            ];
                        }
                    } else {
                        // 타겟 파일이 없는 경우, 소스만 포함
                        $fileContext[$key] = [
                            'source' => $sourceValue,
                            'target' => null
                        ];
                    }
                }

                if (!empty($fileContext)) {
                    // 파일명에서 .php 확장자 제거하고 루트 키로 저장
                    $rootKey = pathinfo(basename($sourceFile), PATHINFO_FILENAME);
                    $context[$rootKey] = $fileContext;
                    $totalContextItems += count($fileContext);
                    $processedFiles++;

                    \Log::debug("TranslationContext: 컨텍스트 추가 - {$rootKey}: " . count($fileContext) . "개 항목");
                }
            } catch (\Exception $e) {
                // 문제가 있는 파일 건너뛰기
                \Log::warning("TranslationContext: 파일 처리 오류 - " . basename($sourceFile) . ": " . $e->getMessage());
                continue;
            }
        }

        \Log::debug("TranslationContext: 컨텍스트 수집 완료 - {$processedFiles}개 파일, {$totalContextItems}개 항목");
        return $context;
    }

    /**
     * 지정된 언어에 대한 디렉토리 경로를 결정합니다.
     * 
     * @param string $langDirectory 언어 파일 기본 디렉토리 경로
     * @param string $locale 언어 로케일 코드
     * @return string 언어별 디렉토리 경로
     */
    protected function getLanguageDirectory(string $langDirectory, string $locale): string
    {
        // 슬래시로 끝나는 경우 제거
        $langDirectory = rtrim($langDirectory, '/');

        // 1. /locale 패턴이 이미 포함된 경우 (예: /lang/en)
        if (preg_match('#/[a-z]{2}(_[A-Z]{2})?$#', $langDirectory)) {
            return preg_replace('#/[a-z]{2}(_[A-Z]{2})?$#', "/{$locale}", $langDirectory);
        }

        // 2. 기본 경로에 언어 코드 추가
        return "{$langDirectory}/{$locale}";
    }

    /**
     * 소스 및 타겟 문자열에서 우선순위가 높은 항목을 선택합니다.
     * 
     * @param array $sourceStrings 소스 문자열 배열
     * @param array $targetStrings 타겟 문자열 배열
     * @param int $maxItems 최대 항목 수
     * @return array 우선순위가 높은 소스 및 타겟 문자열
     */
    protected function getPrioritizedStrings(array $sourceStrings, array $targetStrings, int $maxItems): array
    {
        $prioritizedSource = [];
        $prioritizedTarget = [];
        $commonKeys = array_intersect(array_keys($sourceStrings), array_keys($targetStrings));

        // 1. 짧은 문자열 우선 (UI 요소, 버튼 등)
        foreach ($commonKeys as $key) {
            if (strlen($sourceStrings[$key]) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $sourceStrings[$key];
                $prioritizedTarget[$key] = $targetStrings[$key];
            }
        }

        // 2. 나머지 항목 추가
        foreach ($commonKeys as $key) {
            if (!isset($prioritizedSource[$key]) && count($prioritizedSource) < $maxItems) {
                $prioritizedSource[$key] = $sourceStrings[$key];
                $prioritizedTarget[$key] = $targetStrings[$key];
            }

            if (count($prioritizedSource) >= $maxItems) {
                break;
            }
        }

        return [
            'source' => $prioritizedSource,
            'target' => $prioritizedTarget
        ];
    }

    /**
     * 소스 문자열만 우선순위를 적용합니다.
     */
    protected function getPrioritizedSourceOnly(array $sourceStrings, int $maxItems): array
    {
        $prioritizedSource = [];

        // 1. 짧은 문자열 우선 (UI 요소, 버튼 등)
        foreach ($sourceStrings as $key => $value) {
            if (strlen($value) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $value;
            }
        }

        // 2. 나머지 항목 추가
        foreach ($sourceStrings as $key => $value) {
            if (!isset($prioritizedSource[$key]) && count($prioritizedSource) < $maxItems) {
                $prioritizedSource[$key] = $value;
            }

            if (count($prioritizedSource) >= $maxItems) {
                break;
            }
        }

        return $prioritizedSource;
    }
}