<?php

use Kargnas\LaravelAiTranslator\Plugins\PIIMaskingPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationRequest;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PluginManager;

/**
 * PIIMaskingPlugin 테스트
 * - PII 마스킹
 * - 마스크 토큰 생성
 * - PII 복원
 */

beforeEach(function () {
    $this->plugin = new PIIMaskingPlugin();
    $this->pipeline = new TranslationPipeline(new PluginManager());
});

test('masks email addresses', function () {
    $texts = [
        'contact' => 'Contact us at support@example.com for help',
        'team' => 'Email john.doe@company.org for details',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $processed = false;
    $this->plugin->handle($context, function ($ctx) use (&$processed) {
        $processed = true;
        
        // Check that emails are masked
        expect($ctx->texts['contact'])->toContain('__PII_EMAIL_')
            ->and($ctx->texts['contact'])->not->toContain('@example.com')
            ->and($ctx->texts['team'])->toContain('__PII_EMAIL_')
            ->and($ctx->texts['team'])->not->toContain('@company.org');
        
        // Simulate translation
        $ctx->translations['ko'] = [
            'contact' => str_replace('Contact us at', '연락처:', $ctx->texts['contact']),
            'team' => str_replace('Email', '이메일:', $ctx->texts['team']),
        ];
        
        return $ctx;
    });
    
    expect($processed)->toBeTrue();
    
    // Check that emails are restored in translations
    expect($context->translations['ko']['contact'])->toContain('support@example.com')
        ->and($context->translations['ko']['team'])->toContain('john.doe@company.org');
});

test('masks phone numbers', function () {
    $texts = [
        'us' => 'Call us at (555) 123-4567',
        'intl' => 'International: +1-555-987-6543',
        'dots' => 'Phone: 555.123.4567',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        // Check phone masking
        foreach ($ctx->texts as $text) {
            expect($text)->toContain('__PII_PHONE_')
                ->and($text)->not->toMatch('/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/');
        }
        
        // Simulate translation with masks
        $ctx->translations['ko'] = $ctx->texts;
        
        return $ctx;
    });
    
    // Check restoration
    expect($context->translations['ko']['us'])->toContain('(555) 123-4567')
        ->and($context->translations['ko']['intl'])->toContain('+1-555-987-6543')
        ->and($context->translations['ko']['dots'])->toContain('555.123.4567');
});

test('masks credit card numbers', function () {
    $texts = [
        'visa' => 'Payment: 4111 1111 1111 1111', // Valid Visa test number
        'master' => 'Card: 5500-0000-0000-0004', // Valid MasterCard test number
        'invalid' => 'Number: 1234 5678 9012 3456', // Invalid (fails Luhn)
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        // Valid cards should be masked
        expect($ctx->texts['visa'])->toContain('__PII_CARD_')
            ->and($ctx->texts['master'])->toContain('__PII_CARD_')
            // Invalid card should not be masked
            ->and($ctx->texts['invalid'])->toContain('1234 5678 9012 3456');
        
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    // Check restoration
    expect($context->translations['ko']['visa'])->toContain('4111 1111 1111 1111')
        ->and($context->translations['ko']['master'])->toContain('5500-0000-0000-0004');
});

test('masks SSN numbers', function () {
    $texts = [
        'ssn' => 'SSN: 123-45-6789',
        'text' => 'ID is 987-65-4321 for processing',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        expect($ctx->texts['ssn'])->toContain('__PII_SSN_')
            ->and($ctx->texts['ssn'])->not->toContain('123-45-6789')
            ->and($ctx->texts['text'])->toContain('__PII_SSN_')
            ->and($ctx->texts['text'])->not->toContain('987-65-4321');
        
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    expect($context->translations['ko']['ssn'])->toContain('123-45-6789')
        ->and($context->translations['ko']['text'])->toContain('987-65-4321');
});

test('masks IP addresses', function () {
    $texts = [
        'ipv4' => 'Server at 192.168.1.1',
        'public' => 'Connect to 8.8.8.8',
        'ipv6' => 'IPv6: 2001:0db8:85a3:0000:0000:8a2e:0370:7334',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        expect($ctx->texts['ipv4'])->toContain('__PII_IP_')
            ->and($ctx->texts['ipv4'])->not->toContain('192.168.1.1')
            ->and($ctx->texts['public'])->toContain('__PII_IP_')
            ->and($ctx->texts['ipv6'])->toContain('__PII_IP_');
        
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    expect($context->translations['ko']['ipv4'])->toContain('192.168.1.1')
        ->and($context->translations['ko']['public'])->toContain('8.8.8.8')
        ->and($context->translations['ko']['ipv6'])->toContain('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
});

test('supports custom patterns', function () {
    $plugin = new PIIMaskingPlugin([
        'mask_custom_patterns' => [
            '/EMP-\d{6}/' => 'EMPLOYEE_ID',
            '/ORD-[A-Z]{2}-\d{8}/' => 'ORDER_ID',
        ],
    ]);
    
    $texts = [
        'employee' => 'Employee EMP-123456 has been assigned',
        'order' => 'Order ORD-US-12345678 is processing',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $plugin->handle($context, function ($ctx) {
        expect($ctx->texts['employee'])->toContain('__PII_EMPLOYEE_ID_')
            ->and($ctx->texts['employee'])->not->toContain('EMP-123456')
            ->and($ctx->texts['order'])->toContain('__PII_ORDER_ID_')
            ->and($ctx->texts['order'])->not->toContain('ORD-US-12345678');
        
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    expect($context->translations['ko']['employee'])->toContain('EMP-123456')
        ->and($context->translations['ko']['order'])->toContain('ORD-US-12345678');
});

test('preserves same PII across multiple occurrences', function () {
    $texts = [
        'text1' => 'Email admin@site.com for help',
        'text2' => 'Contact admin@site.com today',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        // Same email should get same mask token
        $mask1 = preg_match('/__PII_EMAIL_\d+__/', $ctx->texts['text1'], $matches1);
        $mask2 = preg_match('/__PII_EMAIL_\d+__/', $ctx->texts['text2'], $matches2);
        
        expect($mask1)->toBe(1)
            ->and($mask2)->toBe(1)
            ->and($matches1[0])->toBe($matches2[0]);
        
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    expect($context->translations['ko']['text1'])->toContain('admin@site.com')
        ->and($context->translations['ko']['text2'])->toContain('admin@site.com');
});

test('provides masking statistics', function () {
    $texts = [
        'mixed' => 'Email: test@example.com, Phone: 555-123-4567, SSN: 123-45-6789',
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $this->plugin->handle($context, function ($ctx) {
        $ctx->translations['ko'] = $ctx->texts;
        return $ctx;
    });
    
    $stats = $this->plugin->getStats();
    
    expect($stats['total_masks'])->toBe(3)
        ->and($stats['mask_types'])->toHaveKey('EMAIL')
        ->and($stats['mask_types'])->toHaveKey('PHONE')
        ->and($stats['mask_types'])->toHaveKey('SSN');
});