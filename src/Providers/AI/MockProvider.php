<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

/**
 * Mock AI Provider for testing and development
 * 
 * Provides deterministic fake translations for testing purposes.
 * Returns predictable outputs without making real API calls.
 */
class MockProvider extends AbstractAIProvider
{
    /**
     * {@inheritDoc}
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale, array $metadata = []): array
    {
        $this->log('info', 'Mock translation started', [
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'text_count' => count($texts),
        ]);
        
        $translations = [];
        foreach ($texts as $key => $text) {
            // Provide realistic mock translations for common phrases
            $mockTranslations = $this->getMockTranslations($text, $targetLocale);
            $translations[$key] = $mockTranslations ?: "[MOCK] {$text} [{$targetLocale}]";
        }
        
        // Mock token usage
        $inputTokens = array_sum(array_map('strlen', $texts)) / 4; // Rough estimation
        $outputTokens = array_sum(array_map('strlen', $translations)) / 4;
        
        return [
            'translations' => $translations,
            'token_usage' => $this->formatTokenUsage((int) $inputTokens, (int) $outputTokens),
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function complete(string $prompt, array $config = []): string
    {
        $this->log('info', 'Mock completion started', [
            'prompt_length' => strlen($prompt),
        ]);
        
        // Mock completion response
        return "[MOCK COMPLETION] Based on the analysis, I recommend option 1 as the best translation.";
    }
    
    /**
     * {@inheritDoc}
     */
    protected function validateConfig(array $config): void
    {
        // Mock provider doesn't require any specific configuration
        // Override parent validation to allow empty model
    }
    
    /**
     * Get realistic mock translations for common phrases
     */
    private function getMockTranslations(?string $text, string $targetLocale): ?string
    {
        if (!$text) {
            return null;
        }
        
        $mockData = [
            'ko' => [
                'Hello World' => '안녕하세요 세계',
                'Hello' => '안녕하세요',
                'World' => '세계',
                'Welcome' => '환영합니다',
                'Thank you' => '감사합니다',
                'Please' => '부탁드립니다',
                'Yes' => '네',
                'No' => '아니요',
                'Submit' => '제출',
                'Cancel' => '취소',
                'Save' => '저장',
                'Delete' => '삭제',
                'Edit' => '편집',
                'Login' => '로그인',
                'Logout' => '로그아웃',
                'Register' => '회원가입',
                'Home' => '홈',
                'Settings' => '설정',
                'Profile' => '프로필',
                'Dashboard' => '대시보드',
                'Search' => '검색',
                'Loading...' => '로딩 중...',
                'Error' => '오류',
                'Success' => '성공',
                'Warning' => '경고',
                'Information' => '정보',
            ],
            'ja' => [
                'Hello World' => 'こんにちは世界',
                'Hello' => 'こんにちは',
                'World' => '世界',
                'Welcome' => 'ようこそ',
                'Thank you' => 'ありがとうございます',
                'Please' => 'お願いします',
                'Yes' => 'はい',
                'No' => 'いいえ',
                'Submit' => '送信',
                'Cancel' => 'キャンセル',
                'Save' => '保存',
                'Delete' => '削除',
                'Edit' => '編集',
                'Login' => 'ログイン',
                'Logout' => 'ログアウト',
                'Register' => '登録',
                'Home' => 'ホーム',
                'Settings' => '設定',
                'Profile' => 'プロフィール',
                'Dashboard' => 'ダッシュボード',
                'Search' => '検索',
                'Loading...' => '読み込み中...',
                'Error' => 'エラー',
                'Success' => '成功',
                'Warning' => '警告',
                'Information' => '情報',
            ],
            'es' => [
                'Hello World' => 'Hola Mundo',
                'Hello' => 'Hola',
                'World' => 'Mundo',
                'Welcome' => 'Bienvenido',
                'Thank you' => 'Gracias',
                'Please' => 'Por favor',
                'Yes' => 'Sí',
                'No' => 'No',
                'Submit' => 'Enviar',
                'Cancel' => 'Cancelar',
                'Save' => 'Guardar',
                'Delete' => 'Eliminar',
                'Edit' => 'Editar',
                'Login' => 'Iniciar sesión',
                'Logout' => 'Cerrar sesión',
                'Register' => 'Registrarse',
                'Home' => 'Inicio',
                'Settings' => 'Configuración',
                'Profile' => 'Perfil',
                'Dashboard' => 'Panel',
                'Search' => 'Buscar',
                'Loading...' => 'Cargando...',
                'Error' => 'Error',
                'Success' => 'Éxito',
                'Warning' => 'Advertencia',
                'Information' => 'Información',
            ],
        ];
        
        return $mockData[$targetLocale][$text] ?? null;
    }
}