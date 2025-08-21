<?php

use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\TranslationRequest;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\PluginManager;
use Kargnas\LaravelAiTranslator\Core\PipelineStages;
use Kargnas\LaravelAiTranslator\Plugins\AbstractMiddlewarePlugin;

/**
 * TranslationPipeline 핵심 기능 테스트
 * - 파이프라인 스테이지 실행 순서
 * - 미들웨어 체인 동작
 * - 이벤트 발생
 */

beforeEach(function () {
    $this->pluginManager = new PluginManager();
    $this->pipeline = new TranslationPipeline($this->pluginManager);
});

test('pipeline executes stages in correct order', function () {
    $executedStages = [];
    
    // Register handlers for each stage
    $stages = PipelineStages::all();
    
    foreach ($stages as $stage) {
        $this->pipeline->registerStage($stage, function ($context) use ($stage, &$executedStages) {
            $executedStages[] = $stage;
        });
    }
    
    $request = new TranslationRequest(
        ['key1' => 'Hello'],
        'en',
        'ko'
    );
    
    // Execute pipeline
    $generator = $this->pipeline->process($request);
    iterator_to_array($generator); // Consume generator
    
    expect($executedStages)->toBe($stages);
});

test('middleware chain wraps pipeline execution', function () {
    $executionOrder = [];
    
    // Create test middleware
    $middleware = new class($executionOrder) extends AbstractMiddlewarePlugin {
        public function __construct(private &$order) {
            parent::__construct();
            $this->name = 'test_middleware';
        }
        
        protected function getStage(): string {
            return PipelineStages::TRANSLATION;
        }
        
        public function handle(TranslationContext $context, \Closure $next): mixed {
            $this->order[] = 'before';
            $result = $next($context);
            $this->order[] = 'after';
            return $result;
        }
    };
    
    $this->pipeline->registerPlugin($middleware);
    
    $request = new TranslationRequest(['test' => 'text'], 'en', 'ko');
    $generator = $this->pipeline->process($request);
    iterator_to_array($generator);
    
    expect($executionOrder)->toContain('before')
        ->and($executionOrder)->toContain('after')
        ->and(array_search('before', $executionOrder))
        ->toBeLessThan(array_search('after', $executionOrder));
});

test('pipeline emits lifecycle events', function () {
    $emittedEvents = [];
    
    // Listen for events
    $this->pipeline->on('translation.started', function ($context) use (&$emittedEvents) {
        $emittedEvents[] = 'started';
    });
    
    $this->pipeline->on('translation.completed', function ($context) use (&$emittedEvents) {
        $emittedEvents[] = 'completed';
    });
    
    $request = new TranslationRequest(['test' => 'text'], 'en', 'ko');
    $generator = $this->pipeline->process($request);
    iterator_to_array($generator);
    
    expect($emittedEvents)->toContain('started')
        ->and($emittedEvents)->toContain('completed');
});

test('pipeline handles errors gracefully', function () {
    // Register failing handler
    $this->pipeline->registerStage(PipelineStages::TRANSLATION, function ($context) {
        throw new RuntimeException('Translation failed');
    });
    
    $request = new TranslationRequest(['test' => 'text'], 'en', 'ko');
    
    expect(function () use ($request) {
        $generator = $this->pipeline->process($request);
        iterator_to_array($generator);
    })->toThrow(RuntimeException::class);
});