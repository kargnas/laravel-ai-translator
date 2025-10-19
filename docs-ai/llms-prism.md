# Prism PHP - AI Integration for Laravel

Prism is a Laravel package that provides a unified, fluent interface for integrating Large Language Models (LLMs) into PHP applications. It abstracts the complexities of working with multiple AI providers (OpenAI, Anthropic, Google Gemini, Groq, Mistral, DeepSeek, XAI, OpenRouter, Ollama, VoyageAI, and ElevenLabs) into a consistent API that handles text generation, structured output, embeddings, image generation, audio processing, tool calling, and streaming responses.

The package enables developers to build AI-powered applications without dealing with provider-specific implementation details. Prism handles message formatting, tool execution, streaming chunks, multi-modal inputs (images, documents, audio, video), and response parsing automatically. It includes comprehensive testing utilities, provider interoperability, rate limit handling, and support for both synchronous and streaming operations.

## Text Generation

Generate text responses using any supported LLM provider.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Basic text generation
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Tell me a short story about a brave knight.')
    ->asText();

echo $response->text;
echo "Tokens used: {$response->usage->promptTokens} + {$response->usage->completionTokens}";
echo "Finish reason: {$response->finishReason->name}";
```

## Text Generation with System Prompt and Parameters

Configure generation behavior with system prompts and temperature settings.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->withMaxTokens(500)
    ->usingTemperature(0.7)
    ->withClientOptions(['timeout' => 30])
    ->withClientRetry(3, 100)
    ->asText();

echo $response->text;
```

## Multi-Modal Text Generation

Process images, documents, audio, and video alongside text prompts.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Video;

// Analyze an image
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'What objects do you see in this image?',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();

// Process a PDF document
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'Summarize the key points from this document',
        [Document::fromLocalPath('/path/to/document.pdf')]
    )
    ->asText();

// Analyze video content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Describe what happens in this video',
        [Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();

// Multiple media types in one prompt
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Compare this image with the information in this document',
        [
            Image::fromLocalPath('/path/to/chart.png'),
            Document::fromLocalPath('/path/to/report.pdf')
        ]
    )
    ->asText();

echo $response->text;
```

## Conversational Messages

Maintain conversation context with message chains.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format for data interchange...'),
        new UserMessage('Can you show me an example?')
    ])
    ->asText();

echo $response->text;

// Access message history
foreach ($response->responseMessages as $message) {
    if ($message instanceof AssistantMessage) {
        echo $message->content . "\n";
    }
}
```

## Structured Output

Extract data in a specific format using schema definitions.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    name: 'movie_review',
    description: 'A structured movie review',
    properties: [
        new StringSchema('title', 'The movie title'),
        new StringSchema('rating', 'Rating out of 5 stars'),
        new StringSchema('summary', 'Brief review summary'),
        new NumberSchema('score', 'Numeric score from 1-10')
    ],
    requiredFields: ['title', 'rating', 'summary', 'score']
);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSchema($schema)
    ->withPrompt('Review the movie Inception')
    ->asStructured();

// Access structured data
$review = $response->structured;
echo $review['title'];    // "Inception"
echo $review['rating'];   // "5 stars"
echo $review['summary'];  // "A mind-bending thriller..."
echo $review['score'];    // 9
```

## Structured Output with OpenAI Strict Mode

Enable strict schema validation for OpenAI models.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\ArraySchema;

$schema = new ObjectSchema(
    name: 'product_data',
    description: 'Product information',
    properties: [
        new StringSchema('name', 'Product name'),
        new StringSchema('category', 'Product category'),
        new ArraySchema('tags', 'Product tags', new StringSchema('tag', 'A tag'))
    ],
    requiredFields: ['name', 'category']
);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withProviderOptions([
        'schema' => ['strict' => true]
    ])
    ->withSchema($schema)
    ->withPrompt('Generate product data for a laptop')
    ->asStructured();

if ($response->structured !== null) {
    print_r($response->structured);
}
```

## Tools and Function Calling

Extend AI capabilities by providing callable functions.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;

// Create a weather tool
$weatherTool = Tool::as('weather')
    ->for('Get current weather conditions')
    ->withStringParameter('city', 'The city to get weather for')
    ->using(function (string $city): string {
        // Call your weather API
        return "The weather in {$city} is sunny and 72°F.";
    });

// Create a search tool
$searchTool = Tool::as('search')
    ->for('Search for current information')
    ->withStringParameter('query', 'The search query')
    ->using(function (string $query): string {
        // Perform search
        return "Search results for: {$query}";
    });

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(3)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool, $searchTool])
    ->asText();

echo $response->text;

// Inspect tool usage
if ($response->toolResults) {
    foreach ($response->toolResults as $toolResult) {
        echo "Tool: {$toolResult->toolName}\n";
        echo "Result: {$toolResult->result}\n";
    }
}
```

## Complex Tool with Object Parameters

Define tools that accept structured data.

```php
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\BooleanSchema;
use Illuminate\Support\Facades\DB;

$updateUserTool = Tool::as('update_user')
    ->for('Update a user profile in the database')
    ->withObjectParameter(
        'user',
        'The user profile data',
        [
            new StringSchema('name', 'User\'s full name'),
            new NumberSchema('age', 'User\'s age'),
            new StringSchema('email', 'User\'s email address'),
            new BooleanSchema('active', 'Whether the user is active')
        ],
        requiredFields: ['name', 'email']
    )
    ->using(function (array $user): string {
        // Update database
        DB::table('users')
            ->where('email', $user['email'])
            ->update([
                'name' => $user['name'],
                'age' => $user['age'] ?? null,
                'active' => $user['active'] ?? true
            ]);

        return "Updated user profile for: {$user['name']}";
    });

$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2)
    ->withPrompt('Update the user profile for alice@example.com: set name to Alice Smith, age 30, and mark as active')
    ->withTools([$updateUserTool])
    ->asText();

echo $response->text;
```

## Streaming Output

Stream AI responses in real-time as they're generated.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\FinishReason;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Tell me a story about a brave knight.')
    ->asStream();

// Process each chunk as it arrives
foreach ($response as $chunk) {
    echo $chunk->text;

    if ($chunk->usage) {
        echo "\nTokens: {$chunk->usage->promptTokens} + {$chunk->usage->completionTokens}";
    }

    if ($chunk->finishReason === FinishReason::Stop) {
        echo "\nGeneration complete!";
    }

    // Flush output buffer for real-time display
    ob_flush();
    flush();
}
```

## Streaming with Tools

Stream responses while executing tool calls.

```php
use Prism\Prism\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Enums\ChunkType;

$weatherTool = Tool::as('weather')
    ->for('Get current weather information')
    ->withStringParameter('city', 'City name')
    ->using(function (string $city) {
        return "The weather in {$city} is sunny and 72°F.";
    });

$response = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What\'s the weather like in San Francisco today?')
    ->asStream();

$fullResponse = '';
foreach ($response as $chunk) {
    $fullResponse .= $chunk->text;

    // Check for tool calls
    if ($chunk->chunkType === ChunkType::ToolCall) {
        foreach ($chunk->toolCalls as $call) {
            echo "\n[Tool called: {$call->name}]\n";
        }
    }

    // Check for tool results
    if ($chunk->chunkType === ChunkType::ToolResult) {
        foreach ($chunk->toolResults as $result) {
            echo "\n[Tool result: {$result->result}]\n";
        }
    }

    echo $chunk->text;
    ob_flush();
    flush();
}

echo "\nFinal response: {$fullResponse}";
```

## Embeddings

Generate vector embeddings for semantic search and similarity analysis.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Single embedding
$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text goes here')
    ->asEmbeddings();

$embeddings = $response->embeddings[0]->embedding;
echo "Vector dimensions: " . count($embeddings);
echo "Token usage: {$response->usage->tokens}";

// Multiple embeddings
$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('First text')
    ->fromInput('Second text')
    ->fromArray(['Third text', 'Fourth text'])
    ->asEmbeddings();

foreach ($response->embeddings as $embedding) {
    $vector = $embedding->embedding;
    // Store or process vector
    echo "Generated vector with " . count($vector) . " dimensions\n";
}

// From file
$response = Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3')
    ->fromFile('/path/to/document.txt')
    ->withClientOptions(['timeout' => 30])
    ->withClientRetry(3, 100)
    ->asEmbeddings();

$vector = $response->embeddings[0]->embedding;
```

## Image Generation

Generate images from text descriptions.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Basic image generation
$response = Prism::image()
    ->using(Provider::OpenAI, 'dall-e-3')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "Image URL: {$image->url}\n";
}

// DALL-E 3 with options
$response = Prism::image()
    ->using(Provider::OpenAI, 'dall-e-3')
    ->withPrompt('A beautiful sunset over mountains')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
        'response_format' => 'b64_json' // url, b64_json
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    file_put_contents('sunset.png', base64_decode($image->base64));
}

// GPT-Image-1 (always returns base64)
$response = Prism::image()
    ->using(Provider::OpenAI, 'gpt-image-1')
    ->withPrompt('A futuristic city skyline at night')
    ->withProviderOptions([
        'size' => '1536x1024',              // 1024x1024, 1536x1024, 1024x1536, auto
        'quality' => 'high',                // auto, high, medium, low
        'background' => 'transparent',      // transparent, opaque, auto
        'output_format' => 'png',           // png, jpeg, webp
        'output_compression' => 90          // 0-100 (for jpeg/webp)
    ])
    ->generate();

$image = $response->firstImage();
file_put_contents('city.png', base64_decode($image->base64));
```

## Gemini Image Generation

Generate and edit images using Google Gemini models.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Gemini Flash conversational image generation with editing
$originalImage = fopen('boots.png', 'r');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Make these boots red instead')
    ->withProviderOptions([
        'image' => $originalImage,
        'image_mime_type' => 'image/png',
    ])
    ->generate();

// Imagen 4 with options
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();

if ($response->hasImages()) {
    foreach ($response->images as $image) {
        if ($image->hasBase64()) {
            // All Gemini images are base64-encoded
            file_put_contents("image_{$image->index}.png", base64_decode($image->base64));
            echo "MIME type: {$image->mimeType}\n";
        }
    }
}
```

## Audio - Text to Speech

Convert text into natural-sounding speech.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Basic text-to-speech
$response = Prism::audio()
    ->using(Provider::OpenAI, 'tts-1')
    ->withInput('Hello, this is a test of text-to-speech functionality.')
    ->withVoice('alloy')
    ->asAudio();

$audio = $response->audio;
if ($audio->hasBase64()) {
    file_put_contents('output.mp3', base64_decode($audio->base64));
    echo "MIME type: {$audio->getMimeType()}\n";
}

// Advanced TTS with options
$response = Prism::audio()
    ->using(Provider::OpenAI, 'tts-1-hd')
    ->withInput('Welcome to our premium audio experience.')
    ->withVoice('nova')
    ->withProviderOptions([
        'response_format' => 'mp3',    // mp3, opus, aac, flac, wav, pcm
        'speed' => 1.2,                // 0.25 to 4.0
    ])
    ->withClientOptions(['timeout' => 60])
    ->asAudio();

file_put_contents('premium-speech.mp3', base64_decode($response->audio->base64));
```

## Audio - Speech to Text

Transcribe audio files into text.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

// Basic speech-to-text
$audioFile = Audio::fromPath('/path/to/audio.mp3');

$response = Prism::audio()
    ->using(Provider::OpenAI, 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: {$response->text}\n";

// From various sources
$audioFromUrl = Audio::fromUrl('https://example.com/audio.mp3');
$audioFromBase64 = Audio::fromBase64($base64Data, 'audio/mpeg');
$audioFromContent = Audio::fromContent($binaryData, 'audio/wav');

// With options and verbose output
$response = Prism::audio()
    ->using(Provider::OpenAI, 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'en',
        'prompt' => 'Previous context for better accuracy...',
        'response_format' => 'verbose_json'
    ])
    ->asText();

echo "Transcription: {$response->text}\n";

// Access detailed metadata
if (isset($response->additionalContent['segments'])) {
    foreach ($response->additionalContent['segments'] as $segment) {
        echo "Segment: {$segment['text']}\n";
        echo "Time: {$segment['start']}s - {$segment['end']}s\n";
    }
}
```

## Prism Server

Expose AI models through an OpenAI-compatible API.

```php
// In config/prism.php
return [
    'prism_server' => [
        'enabled' => env('PRISM_SERVER_ENABLED', true),
        'middleware' => ['api', 'auth'],
    ],
];

// In AppServiceProvider.php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\PrismServer;

public function boot(): void
{
    // Register custom models
    PrismServer::register(
        'my-custom-assistant',
        fn () => Prism::text()
            ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
            ->withSystemPrompt('You are a helpful coding assistant.')
    );

    PrismServer::register(
        'creative-writer',
        fn () => Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o')
            ->withSystemPrompt('You are a creative writer.')
            ->usingTemperature(0.9)
    );
}
```

```bash
# List available models
curl http://localhost:8000/prism/openai/v1/models

# Chat completions
curl -X POST http://localhost:8000/prism/openai/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "my-custom-assistant",
    "messages": [
      {"role": "user", "content": "Help me write a function to validate emails"}
    ]
  }'
```

## Testing

Comprehensive testing utilities with fakes and assertions.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

test('generates text response', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20))
        ->withFinishReason(FinishReason::Stop);

    Prism::fake([$fakeResponse]);

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->text)->toBe('Hello, I am Claude!');
    expect($response->usage->promptTokens)->toBe(10);
});

test('handles tool calls', function () {
    $responses = [
        (new ResponseBuilder)
            ->addStep(
                TextStepFake::make()
                    ->withToolCalls([
                        new ToolCall('call_123', 'weather', ['city' => 'Paris'])
                    ])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withUsage(new Usage(15, 25))
                    ->withMeta(new Meta('fake-1', 'fake-model'))
            )
            ->addStep(
                TextStepFake::make()
                    ->withText('The weather in Paris is sunny and 72°F.')
                    ->withToolResults([
                        new ToolResult('call_123', 'weather', ['city' => 'Paris'], 'Sunny, 72°F')
                    ])
                    ->withFinishReason(FinishReason::Stop)
                    ->withUsage(new Usage(20, 30))
                    ->withMeta(new Meta('fake-2', 'fake-model'))
            )
            ->toResponse()
    ];

    Prism::fake($responses);

    $weatherTool = Tool::as('weather')
        ->for('Get weather information')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city) => "Sunny, 72°F");

    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What\'s the weather in Paris?')
        ->withTools([$weatherTool])
        ->withMaxSteps(2)
        ->asText();

    expect($response->steps)->toHaveCount(2);
    expect($response->toolResults[0]->result)->toBe('Sunny, 72°F');
    expect($response->text)->toBe('The weather in Paris is sunny and 72°F.');
});

test('streams responses', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('streaming test')
            ->withFinishReason(FinishReason::Stop)
    ])->withFakeChunkSize(5);

    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test streaming')
        ->asStream();

    $outputText = '';
    foreach ($stream as $chunk) {
        $outputText .= $chunk->text;
    }

    expect($outputText)->toBe('streaming test');
});
```

## Configuration and Multi-Tenancy

Override provider configuration for multi-tenant applications.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// User-specific API keys
$userConfig = [
    'api_key' => $user->anthropic_api_key,
];

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->usingProviderConfig($userConfig)
    ->withPrompt('Generate a response using the user\'s API key')
    ->asText();

// Complete provider override
$customConfig = [
    'api_key' => 'sk-custom-key',
    'url' => 'https://custom-proxy.example.com/v1',
    'organization' => 'org-123',
    'project' => 'proj-456'
];

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->usingProviderConfig($customConfig)
    ->withPrompt('Use custom configuration')
    ->asText();
```

## Helper Function

Use the global helper function for convenience.

```php
use Prism\Prism\Enums\Provider;

// Using the prism() helper
$response = prism()
    ->text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withPrompt('Hello world')
    ->asText();

echo $response->text;
```

## Use Cases and Integration Patterns

Prism excels at building AI-powered Laravel applications with minimal boilerplate. Common use cases include chatbots with conversation history, content generation pipelines with structured output validation, semantic search with vector embeddings, document analysis combining PDFs and images, automated customer support with tool calling for database lookups, and real-time AI assistants using streaming responses. The package handles provider switching transparently, making it ideal for applications that need fallback providers or cost optimization through provider selection.

Integration patterns leverage Laravel's service container and facade system. Register custom Prism configurations in service providers for dependency injection. Use Prism Server to expose AI models through REST APIs that work with any OpenAI-compatible client. Implement tool classes as invokable controllers that access Laravel services like databases, queues, and cache. Chain multiple Prism operations in jobs for background processing of large documents or batch embeddings. Test AI features comprehensively using Prism's fake helpers and assertions. Configure provider-specific options through arrays for fine-grained control while maintaining a unified interface. Handle rate limits and errors gracefully with built-in retry logic and exception types. Stream responses directly to HTTP responses for real-time user experiences in web applications.
