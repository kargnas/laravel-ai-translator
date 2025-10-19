<?php

/**
 * Real-World Examples for Laravel AI Translator
 * 
 * This file contains practical examples of using the Laravel AI Translator
 * in production scenarios.
 */

use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Plugins\PIIMaskingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\AbstractObserverPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ============================================================================
// Example 1: E-Commerce Platform Translation
// ============================================================================

class EcommerceTranslationService
{
    /**
     * Translate product catalog with optimization
     */
    public function translateProductCatalog(array $products, array $targetLocales)
    {
        // Prepare texts
        $texts = [];
        foreach ($products as $product) {
            $texts["product_{$product->id}_name"] = $product->name;
            $texts["product_{$product->id}_description"] = $product->description;
            $texts["product_{$product->id}_features"] = $product->features;
        }
        
        $result = TranslationBuilder::make()
            ->from('en')
            ->to($targetLocales)
            
            // Optimization
            ->trackChanges()  // Skip unchanged products
            ->withTokenChunking(3000)  // Optimal chunk size
            
            // Quality
            ->withStyle('marketing', 'Use persuasive language for product descriptions')
            ->withGlossary([
                'Free Shipping' => ['ko' => '무료 배송', 'ja' => '送料無料'],
                'Add to Cart' => ['ko' => '장바구니 담기', 'ja' => 'カートに追加'],
                'In Stock' => ['ko' => '재고 있음', 'ja' => '在庫あり'],
            ])
            
            // Security
            ->secure()  // Mask customer data if present
            
            // Progress tracking
            ->onProgress(function($output) use ($products) {
                $this->updateProductTranslationStatus($output);
            })
            ->translate($texts);
        
        // Save translations
        $this->saveProductTranslations($result, $products);
        
        return $result;
    }
    
    private function updateProductTranslationStatus($output)
    {
        if (preg_match('/product_(\d+)_/', $output->key, $matches)) {
            $productId = $matches[1];
            Cache::put("translation_progress_{$productId}", 'processing', 60);
        }
    }
    
    private function saveProductTranslations($result, $products)
    {
        foreach ($result->getTranslations() as $locale => $translations) {
            foreach ($products as $product) {
                DB::table('product_translations')->updateOrInsert(
                    ['product_id' => $product->id, 'locale' => $locale],
                    [
                        'name' => $translations["product_{$product->id}_name"] ?? null,
                        'description' => $translations["product_{$product->id}_description"] ?? null,
                        'features' => $translations["product_{$product->id}_features"] ?? null,
                        'translated_at' => now(),
                    ]
                );
            }
        }
    }
}

// ============================================================================
// Example 2: SaaS Multi-Tenant Translation
// ============================================================================

class MultiTenantTranslationService
{
    /**
     * Handle translations for different tenants with custom configurations
     */
    public function translateForTenant(string $tenantId, array $texts)
    {
        $tenant = $this->getTenant($tenantId);
        $builder = TranslationBuilder::make()
            ->from($tenant->source_locale)
            ->to($tenant->target_locales)
            ->forTenant($tenantId);
        
        // Apply tenant-specific style
        if ($tenant->translation_style) {
            $builder->withStyle($tenant->translation_style, $tenant->style_instructions);
        }
        
        // Apply tenant glossary
        if ($glossary = $this->getTenantGlossary($tenantId)) {
            $builder->withGlossary($glossary);
        }
        
        // Apply tenant security settings
        if ($tenant->require_pii_protection) {
            $builder->secure();
            
            // Add custom PII patterns for tenant
            if ($tenant->custom_pii_patterns) {
                $builder->withPlugin(new PIIMaskingPlugin([
                    'mask_custom_patterns' => $tenant->custom_pii_patterns,
                ]));
            }
        }
        
        // Apply tenant-specific providers
        if ($tenant->preferred_ai_providers) {
            $builder->withProviders($tenant->preferred_ai_providers);
        }
        
        // Cost optimization for different tiers
        if ($tenant->subscription_tier === 'basic') {
            $builder->trackChanges()  // More aggressive caching
                   ->withTokenChunking(1500);  // Smaller chunks
        } elseif ($tenant->subscription_tier === 'premium') {
            $builder->withTokenChunking(4000)  // Larger chunks for speed
                   ->withValidation(['all']);  // Full validation
        }
        
        // Execute translation
        $result = $builder->translate($texts);
        
        // Track usage for billing
        $this->trackTenantUsage($tenantId, $result);
        
        return $result;
    }
    
    private function getTenant(string $tenantId)
    {
        return DB::table('tenants')->find($tenantId);
    }
    
    private function getTenantGlossary(string $tenantId): array
    {
        return Cache::remember("tenant_glossary_{$tenantId}", 3600, function() use ($tenantId) {
            return DB::table('tenant_glossaries')
                ->where('tenant_id', $tenantId)
                ->pluck('translation', 'term')
                ->toArray();
        });
    }
    
    private function trackTenantUsage(string $tenantId, $result)
    {
        DB::table('tenant_usage')->insert([
            'tenant_id' => $tenantId,
            'texts_translated' => count($result->getTranslations()),
            'tokens_used' => $result->getTokenUsage()['total'] ?? 0,
            'locales' => json_encode(array_keys($result->getTranslations())),
            'created_at' => now(),
        ]);
    }
}

// ============================================================================
// Example 3: Content Management System
// ============================================================================

class CMSTranslationService
{
    /**
     * Translate blog posts with SEO optimization
     */
    public function translateBlogPost($post, array $targetLocales)
    {
        // Prepare content with metadata
        $texts = [
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'content' => $post->content,
            'meta_description' => $post->meta_description,
            'meta_keywords' => $post->meta_keywords,
        ];
        
        // Add custom SEO plugin
        $seoPlugin = new class extends AbstractObserverPlugin {
            public function subscribe(): array
            {
                return ['translation.completed' => 'optimizeForSEO'];
            }
            
            public function optimizeForSEO(TranslationContext $context): void
            {
                foreach ($context->translations as $locale => &$translations) {
                    // Ensure meta description length
                    if (isset($translations['meta_description'])) {
                        $translations['meta_description'] = $this->truncateToLength(
                            $translations['meta_description'],
                            160
                        );
                    }
                    
                    // Ensure title length for SEO
                    if (isset($translations['title'])) {
                        $translations['title'] = $this->truncateToLength(
                            $translations['title'],
                            60
                        );
                    }
                }
            }
            
            private function truncateToLength(string $text, int $maxLength): string
            {
                if (mb_strlen($text) <= $maxLength) {
                    return $text;
                }
                return mb_substr($text, 0, $maxLength - 3) . '...';
            }
        };
        
        $result = TranslationBuilder::make()
            ->from($post->original_locale)
            ->to($targetLocales)
            ->withStyle('blog', 'Maintain engaging blog writing style')
            ->withContext('Blog post about ' . $post->category)
            ->withPlugin($seoPlugin)
            ->withValidation(['html', 'length'])
            ->translate($texts);
        
        // Save translations
        $this->saveBlogTranslations($post, $result);
        
        // Generate translated slugs
        $this->generateTranslatedSlugs($post, $result);
        
        return $result;
    }
    
    private function saveBlogTranslations($post, $result)
    {
        foreach ($result->getTranslations() as $locale => $translations) {
            DB::table('post_translations')->updateOrInsert(
                ['post_id' => $post->id, 'locale' => $locale],
                [
                    'title' => $translations['title'],
                    'excerpt' => $translations['excerpt'],
                    'content' => $translations['content'],
                    'meta_description' => $translations['meta_description'],
                    'meta_keywords' => $translations['meta_keywords'],
                    'translated_at' => now(),
                ]
            );
        }
    }
    
    private function generateTranslatedSlugs($post, $result)
    {
        foreach ($result->getTranslations() as $locale => $translations) {
            $slug = Str::slug($translations['title']);
            
            // Ensure unique slug
            $count = 1;
            $originalSlug = $slug;
            while (DB::table('post_translations')
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('post_id', '!=', $post->id)
                ->exists()
            ) {
                $slug = "{$originalSlug}-{$count}";
                $count++;
            }
            
            DB::table('post_translations')
                ->where('post_id', $post->id)
                ->where('locale', $locale)
                ->update(['slug' => $slug]);
        }
    }
}

// ============================================================================
// Example 4: Customer Support System
// ============================================================================

class SupportTicketTranslationService
{
    /**
     * Translate support tickets with PII protection
     */
    public function translateTicket($ticket, string $targetLocale)
    {
        // Prepare ticket content
        $texts = [
            'subject' => $ticket->subject,
            'description' => $ticket->description,
        ];
        
        // Add messages
        foreach ($ticket->messages as $index => $message) {
            $texts["message_{$index}"] = $message->content;
        }
        
        $result = TranslationBuilder::make()
            ->from($ticket->original_locale)
            ->to($targetLocale)
            
            // Critical: Protect customer data
            ->secure()
            ->withPlugin(new PIIMaskingPlugin([
                'mask_emails' => true,
                'mask_phones' => true,
                'mask_credit_cards' => true,
                'mask_custom_patterns' => [
                    '/TICKET-\d{8}/' => 'TICKET_ID',
                    '/ORDER-\d{10}/' => 'ORDER_ID',
                    '/CUSTOMER-\d{6}/' => 'CUSTOMER_ID',
                ],
            ]))
            
            // Maintain support tone
            ->withStyle('support', 'Use helpful and empathetic customer service language')
            
            // Technical terms glossary
            ->withGlossary([
                'refund' => ['es' => 'reembolso', 'fr' => 'remboursement'],
                'warranty' => ['es' => 'garantía', 'fr' => 'garantie'],
                'troubleshooting' => ['es' => 'solución de problemas', 'fr' => 'dépannage'],
            ])
            
            ->translate($texts);
        
        // Save translated ticket
        $this->saveTranslatedTicket($ticket, $targetLocale, $result);
        
        // Notify support agent
        $this->notifyAgent($ticket, $targetLocale);
        
        return $result;
    }
    
    private function saveTranslatedTicket($ticket, $locale, $result)
    {
        $translations = $result->getTranslations()[$locale] ?? [];
        
        DB::table('ticket_translations')->insert([
            'ticket_id' => $ticket->id,
            'locale' => $locale,
            'subject' => $translations['subject'] ?? '',
            'description' => $translations['description'] ?? '',
            'messages' => json_encode(
                array_filter($translations, fn($key) => str_starts_with($key, 'message_'), ARRAY_FILTER_USE_KEY)
            ),
            'created_at' => now(),
        ]);
    }
    
    private function notifyAgent($ticket, $locale)
    {
        $agent = $this->findAgentForLocale($locale);
        if ($agent) {
            Notification::send($agent, new TicketTranslatedNotification($ticket, $locale));
        }
    }
    
    private function findAgentForLocale($locale)
    {
        return User::where('role', 'support_agent')
            ->whereJsonContains('languages', $locale)
            ->first();
    }
}

// ============================================================================
// Example 5: API Documentation Translation
// ============================================================================

class APIDocumentationTranslator
{
    /**
     * Translate API documentation with code preservation
     */
    public function translateAPIDocs($documentation, array $targetLocales)
    {
        // Custom plugin to preserve code blocks
        $codePreserver = new class extends AbstractMiddlewarePlugin {
            private array $codeBlocks = [];
            private int $blockCounter = 0;
            
            protected function getStage(): string
            {
                return 'pre_process';
            }
            
            public function handle(TranslationContext $context, Closure $next): mixed
            {
                // Extract and replace code blocks
                foreach ($context->texts as $key => &$text) {
                    $text = preg_replace_callback('/```[\s\S]*?```/', function($match) {
                        $placeholder = "__CODE_BLOCK_{$this->blockCounter}__";
                        $this->codeBlocks[$placeholder] = $match[0];
                        $this->blockCounter++;
                        return $placeholder;
                    }, $text);
                }
                
                // Store for restoration
                $context->setPluginData($this->getName(), [
                    'code_blocks' => $this->codeBlocks,
                ]);
                
                $result = $next($context);
                
                // Restore code blocks in translations
                $codeBlocks = $context->getPluginData($this->getName())['code_blocks'];
                foreach ($context->translations as $locale => &$translations) {
                    foreach ($translations as &$translation) {
                        foreach ($codeBlocks as $placeholder => $code) {
                            $translation = str_replace($placeholder, $code, $translation);
                        }
                    }
                }
                
                return $result;
            }
        };
        
        $texts = $this->extractDocumentationTexts($documentation);
        
        $result = TranslationBuilder::make()
            ->from('en')
            ->to($targetLocales)
            ->withPlugin($codePreserver)
            ->withStyle('technical', 'Use precise technical language')
            ->withGlossary($this->getAPIGlossary())
            ->withValidation(['variables'])  // Preserve API placeholders
            ->translate($texts);
        
        $this->saveTranslatedDocs($documentation, $result);
        
        return $result;
    }
    
    private function extractDocumentationTexts($documentation): array
    {
        $texts = [];
        
        foreach ($documentation->endpoints as $endpoint) {
            $texts["endpoint_{$endpoint->id}_description"] = $endpoint->description;
            
            foreach ($endpoint->parameters as $param) {
                $texts["param_{$endpoint->id}_{$param->name}"] = $param->description;
            }
            
            foreach ($endpoint->responses as $response) {
                $texts["response_{$endpoint->id}_{$response->code}"] = $response->description;
            }
        }
        
        return $texts;
    }
    
    private function getAPIGlossary(): array
    {
        return [
            'endpoint' => 'endpoint',  // Keep as-is
            'API' => 'API',
            'JSON' => 'JSON',
            'OAuth' => 'OAuth',
            'webhook' => 'webhook',
            'payload' => 'payload',
            'authentication' => ['es' => 'autenticación', 'fr' => 'authentification'],
            'authorization' => ['es' => 'autorización', 'fr' => 'autorisation'],
        ];
    }
    
    private function saveTranslatedDocs($documentation, $result)
    {
        foreach ($result->getTranslations() as $locale => $translations) {
            // Generate translated documentation
            $translatedDoc = $this->generateDocumentation($documentation, $translations, $locale);
            
            // Save to storage
            Storage::put("docs/api/{$locale}/documentation.json", json_encode($translatedDoc));
            
            // Generate static site
            $this->generateStaticSite($translatedDoc, $locale);
        }
    }
    
    private function generateStaticSite($documentation, $locale)
    {
        // Generate HTML/Markdown files for static site generator
        Artisan::call('docs:generate', [
            'locale' => $locale,
            'format' => 'markdown',
        ]);
    }
}

// ============================================================================
// Usage Examples
// ============================================================================

// E-commerce translation
$ecommerce = new EcommerceTranslationService();
$products = Product::where('needs_translation', true)->get();
$ecommerce->translateProductCatalog($products, ['es', 'fr', 'de', 'ja']);

// Multi-tenant translation
$multiTenant = new MultiTenantTranslationService();
$multiTenant->translateForTenant('tenant_123', [
    'welcome' => 'Welcome to our platform',
    'dashboard' => 'Your Dashboard',
]);

// CMS translation
$cms = new CMSTranslationService();
$post = Post::find(1);
$cms->translateBlogPost($post, ['ko', 'ja', 'zh']);

// Support ticket translation
$support = new SupportTicketTranslationService();
$ticket = Ticket::find(456);
$support->translateTicket($ticket, 'es');

// API documentation
$apiDocs = new APIDocumentationTranslator();
$documentation = APIDocumentation::latest()->first();
$apiDocs->translateAPIDocs($documentation, ['es', 'fr', 'de', 'ja', 'ko']);