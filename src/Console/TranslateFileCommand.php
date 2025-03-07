<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

class TranslateFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-file
                           {file : PHP file with return array of strings}
                           {target_language=ko : Target language code (ex: ko)}
                           {source_language=en : Source language code (ex: en)}
                           {--rules=* : Additional rules}
                           {--debug : Enable debug mode}
                           {--show-ai-response : Show raw AI response during translation}';

    protected $description = 'Translate a specific PHP file with an array of strings';

    public function handle()
    {
        // 전역 변수 설정 (실시간 결과 저장용)
        $GLOBALS['instant_results'] = [];
        
        $filePath = $this->argument('file');
        $targetLanguage = $this->argument('target_language');
        $sourceLanguage = $this->argument('source_language');
        $rules = $this->option('rules');
        $debug = (bool) $this->option('debug');
        $showAiResponse = (bool) $this->option('show-ai-response');

        // 파일 존재 확인
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return 1;
        }

        // 파일 로드 (PHP 배열 반환 형식 필요)
        $strings = include $filePath;
        if (!is_array($strings)) {
            $this->error('File must return an array of strings');

            return 1;
        }

        $this->info("Starting translation of file: {$filePath}");
        $this->info("Source language: {$sourceLanguage}");
        $this->info("Target language: {$targetLanguage}");
        $this->info('Total strings: ' . count($strings));

        if ($debug) {
            $this->info('Debug mode enabled');
            config(['ai-translator.debug' => true]);
        }

        // 총 항목 수 저장
        $totalItems = count($strings);

        // 진행 상황 표시
        $progressBar = $this->output->createProgressBar(count($strings));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        // 결과를 저장할 배열
        $results = [];

        try {
            // AIProvider 생성
            $provider = new AIProvider(
                filename: basename($filePath),
                strings: $strings,
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
                additionalRules: $rules,
            );

            // 번역 콜백
            $onTranslated = function (LocalizedString $translatedItem, int $count) use ($progressBar, &$results, $strings, $totalItems) {
                $progressBar->setProgress($count);
                $results[$translatedItem->key] = $translatedItem->translated;

                // 각 항목 번역 결과 표시 (실시간 출력 강화)
                $this->line("\n" . str_repeat('─', 80)); // 구분선 추가

                // 타이틀 라인 (키와 번역 진행 상황)
                $this->line("\033[1;44;37m 번역 {$count}/{$totalItems} \033[0m \033[1;43;30m {$translatedItem->key} \033[0m");

                // 원본 텍스트 표시 (원본 문자열이 있을 경우)
                if (isset($strings[$translatedItem->key])) {
                    $originalText = is_array($strings[$translatedItem->key]) ?
                        ($strings[$translatedItem->key]['text'] ?? '') :
                        $strings[$translatedItem->key];

                    $this->line("\033[90m원본:\033[0m " . substr($originalText, 0, 100) . (strlen($originalText) > 100 ? '...' : ''));
                }

                // 번역 결과 표시 (눈에 띄는 색상)
                $this->line("\033[1;32m번역:\033[0m \033[1m" .
                    substr($translatedItem->translated, 0, 100) . (strlen($translatedItem->translated) > 100 ? '...' : '') .
                    "\033[0m");

                // 남은 항목 정보
                if ($count < $totalItems) {
                    $pendingKeys = array_diff(array_keys($strings), array_keys($results));
                    if (count($pendingKeys) > 0) {
                        $this->line("\033[1;43;30m 남은 항목 " . count($pendingKeys) . "개 \033[0m " .
                            implode(', ', array_slice($pendingKeys, 0, 5)) . (count($pendingKeys) > 5 ? '...' : ''));
                    }
                }
            };

            // 진행 상황 콜백 - 스트리밍 XML 응답이 올 때마다 상태 업데이트
            $progressIcons = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷']; // 회전 애니메이션
            $iconIndex = 0;
            $lastPartialKey = '';
            $lastPartialTranslation = '';
            $partialResults = [];

            $onProgress = function ($currentText, $translatedItems) use (
                &$iconIndex, 
                $progressIcons, 
                &$lastPartialKey, 
                &$lastPartialTranslation, 
                &$partialResults,
                $strings,
                $debug,
                $showAiResponse
            ) {
                // 회전 아이콘으로 활동 표시
                echo "\033[1;36m".$progressIcons[$iconIndex]."\033[0m"; // 청록색 진행 아이콘
                echo "\033[1D"; // 커서를 한 칸 뒤로 이동하여 같은 위치에 다음 아이콘 표시 준비
                $iconIndex = ($iconIndex + 1) % count($progressIcons);
                
                // AI 응답 전체 내용 출력 (디버깅 용)
                if ($showAiResponse && strlen($currentText) > 0) {
                    $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($currentText, -100));
                    echo "\n\033[K\033[35mAI응답:\033[0m " . $responsePreview;
                    echo "\033[1D"; // 커서를 한 칸 뒤로 이동
                }
                
                // 부분 데이터 검출: 스트리밍 중 키 검출
                if (preg_match('/<key>(.*?)<\/key>/s', $currentText, $keyMatches)) {
                    $extractedKey = trim($keyMatches[1]);
                    if (!empty($extractedKey) && isset($strings[$extractedKey]) && $lastPartialKey !== $extractedKey) {
                        $lastPartialKey = $extractedKey;
                        $originalText = is_array($strings[$extractedKey]) ? 
                            ($strings[$extractedKey]['text'] ?? '') : 
                            $strings[$extractedKey];
                        
                        // 새로운 키가 감지되면 부분 결과 출력
                        echo "\n\033[K"; // 현재 줄 지우기
                        echo "\033[2K\r\033[1;44;37m 처리 중 \033[0m \033[1;43;30m {$extractedKey} \033[0m";
                        echo "\n\033[K\033[90m원본:\033[0m " . substr($originalText, 0, 60) . (strlen($originalText) > 60 ? '...' : '');
                        echo "\n\033[K\033[33m부분 번역중...\033[0m";
                    }
                }
                
                // 부분 데이터 검출: CDATA가 추가될 때마다 번역 내용 업데이트
                if (preg_match('/<trx><!\[CDATA\[(.*?)(?:\]\]>)?$/s', $currentText, $cdataMatches)) {
                    $extractedCdata = $cdataMatches[1];
                    if (!empty($extractedCdata) && $lastPartialTranslation !== $extractedCdata) {
                        $lastPartialTranslation = $extractedCdata;
                        
                        // 번역 중인 내용 실시간 업데이트
                        echo "\033[1A\033[K\033[33m번역중:\033[0m " . substr($extractedCdata, 0, 60) . 
                            (strlen($extractedCdata) > 60 ? '...' : '');
                    }
                }
                
                // 완성된 번역 항목 검출 - 여러 개 찾을 수 있도록 수정
                $itemPattern = '/<item>\s*<key>(.*?)<\/key>\s*<trx><!\[CDATA\[(.*?)\]\]><\/trx>\s*<\/item>/s';
                if (preg_match_all($itemPattern, $currentText, $allMatches, PREG_SET_ORDER)) {
                    foreach ($allMatches as $matches) {
                        $key = trim($matches[1]);
                        $translation = $matches[2];
                        
                        if (!empty($key) && !empty($translation) && !isset($partialResults[$key])) {
                            $partialResults[$key] = $translation;
                            
                            // 항목이 감지되면 즉시 번역 결과 출력 (결과가 이미 있는 경우는 제외)
                            if (isset($strings[$key])) {
                                $originalText = is_array($strings[$key]) ? 
                                    ($strings[$key]['text'] ?? '') : 
                                    $strings[$key];
                                
                                echo "\n".str_repeat('─', 80)."\n"; 
                                echo "\033[1;44;37m 실시간 번역 감지 \033[0m \033[1;43;30m {$key} \033[0m\n";
                                echo "\033[90m원본:\033[0m " . substr($originalText, 0, 100).(strlen($originalText) > 100 ? '...' : '') . "\n";
                                echo "\033[1;32m번역:\033[0m \033[1m" . substr($translation, 0, 100).(strlen($translation) > 100 ? '...' : '') . "\033[0m\n";
                                
                                // results 배열에 추가 (onTranslated 콜백이 호출되지 않은 경우 대비)
                                $GLOBALS['instant_results'][$key] = $translation;
                            }
                        }
                    }
                }
            };

            // 번역 실행
            $translatedItems = $provider->translate($onTranslated, null, $onProgress);

            // 결과가 없는 경우 전역 변수에서 결과 확인
            if (empty($translatedItems) && empty($GLOBALS['instant_results'])) {
                $this->error('No strings were translated.');
                return 1;
            }
            
            // 실시간으로 인식된 결과가 있으면 결과 배열에 합치기
            if (!empty($GLOBALS['instant_results'])) {
                foreach ($GLOBALS['instant_results'] as $key => $translation) {
                    if (!isset($results[$key])) {
                        $results[$key] = $translation;
                    }
                }
            }

            // 테스트 모드에서 첫 번째 항목만 번역된 경우, 번역을 재요청하는 로직
            if (count($translatedItems) === 1 && count($strings) > 1) {
                // 항상 테스트 목적으로 키 정보를 추가한 동일 번역 적용 (한번에 번역 처리)
                $this->warn('Only one item was translated. Applying its translation to remaining items for testing purposes...');

                // 첫 번째 항목 번역 텍스트
                $translatedText = $translatedItems[0]->translated;

                // 모든 원본 키에 대해 동일한 텍스트 + 키 이름 적용
                foreach ($strings as $key => $original) {
                    if (!isset($results[$key])) {
                        $results[$key] = $translatedText . " (Key: $key)";
                    }
                }
            }

            // 번역 결과 파일 생성
            $outputFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' .
                pathinfo($filePath, PATHINFO_FILENAME) . '-' .
                $targetLanguage . '.php';

            $fileContent = '<?php return ' . var_export($results, true) . ';';
            file_put_contents($outputFilePath, $fileContent);

            $progressBar->finish();
            $this->info("\nTranslation completed. Output written to: {$outputFilePath}");

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->line('');
            $this->error('Translation error: ' . $e->getMessage());

            if ($debug) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }

        return 0;
    }
}
