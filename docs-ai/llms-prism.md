TITLE: Install Prism PHP Library via Composer
DESCRIPTION: This command uses Composer to add the Prism PHP library and its required dependencies to your project. It is advised to pin the version to prevent issues from future breaking changes.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/getting-started/installation.md#_snippet_0

LANGUAGE: bash
CODE:
```
composer require prism-php/prism
```

----------------------------------------

TITLE: Adding Images to Messages in Prism (PHP)
DESCRIPTION: This PHP code snippet demonstrates how to attach images to user messages in Prism for vision analysis. It showcases various methods for creating `Image` value objects: from a local file path, a storage disk path, a URL, a base64 encoded string, and raw image content. The example then shows how to include these image-enabled messages in a `Prism::text()` call for processing by a specified provider and model.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/input-modalities/images.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Image;

// From a local path
$message = new UserMessage(
    "What's in this image?",
    [Image::fromLocalPath(path: '/path/to/image.jpg')]
);

// From a path on a storage disk
$message = new UserMessage(
    "What's in this image?",
    [Image::fromStoragePath(
        path: '/path/to/image.jpg',
        disk: 'my-disk' // optional - omit/null for default disk
    )]
);

// From a URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl(url: 'https://example.com/diagram.png')]
);

// From base64
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromBase64(base64: base64_encode(file_get_contents('/path/to/image.jpg')))]
);

// From raw content
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromRawContent(rawContent: file_get_contents('/path/to/image.jpg')))]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withMessages([$message])
    ->asText();
```

----------------------------------------

TITLE: Adding documents to messages using Prism's Document object
DESCRIPTION: This PHP code demonstrates how to attach various types of documents (local path, storage path, base64, raw content, text string, URL, and chunks) to a user message using Prism's `Document` value object and the `additionalContent` property. It showcases different static factory methods available for creating `Document` instances.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/input-modalities/documents.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\OpenAIFile;

Prism::text()
    ->using('my-provider', 'my-model')
    ->withMessages([
        // From a local path
        new UserMessage('Here is the document from a local path', [
            Document::fromLocalPath(
                path: 'tests/Fixtures/test-pdf.pdf',
                title: 'My document title' // optional
            ),
        ]),
        // From a storage path
        new UserMessage('Here is the document from a storage path', [
            Document::fromStoragePath(
                path: 'mystoragepath/file.pdf',
                disk: 'my-disk', // optional - omit/null for default disk
                title: 'My document title' // optional
            ),
        ]),
        // From base64
        new UserMessage('Here is the document from base64', [
            Document::fromBase64(
                base64: $baseFromDB,
                mimeType: 'optional/mimetype', // optional
                title: 'My document title' // optional
            ),
        ]),
        // From raw content
        new UserMessage('Here is the document from raw content', [
            Document::fromRawContent(
                rawContent: $rawContent,
                mimeType: 'optional/mimetype', // optional
                title: 'My document title' // optional
            ),
        ]),
        // From a text string
        new UserMessage('Here is the document from a text string (e.g. from your database)', [
            Document::fromText(
                text: 'Hello world!',
                title: 'My document title' // optional
            ),
        ]),
        // From an URL
        new UserMessage('Here is the document from a url (make sure this is publically accessible)', [
            Document::fromUrl(
                url: 'https://example.com/test-pdf.pdf',
                title: 'My document title' // optional
            ),
        ]),
        // From chunks
        new UserMessage('Here is a chunked document', [
            Document::fromChunks(
                chunks: [
                    'chunk one',
                    'chunk two'
                ],
                title: 'My document title' // optional
            ),
        ]),
    ])
    ->asText();
```

----------------------------------------

TITLE: Generate Basic Text with Prism PHP
DESCRIPTION: Demonstrates the simplest way to generate text using Prism's `text()` method, specifying a provider and model, and providing a prompt. The generated text is then echoed.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/text-generation.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withPrompt('Tell me a short story about a brave knight.')
    ->asText();

echo $response->text;
```

----------------------------------------

TITLE: Prism Tools System API
DESCRIPTION: Explains Prism's powerful tools system for building interactive AI assistants that can perform actions within an application. Covers tool definition, parameter schemas, execution, and managing conversational flow with tool choice and streaming responses.
SOURCE: https://github.com/prism-php/prism/blob/main/workshop.md#_snippet_3

LANGUAGE: APIDOC
CODE:
```
Prism\Tool::as(string $name, callable $callback):
  Purpose: Defines a custom tool that the AI model can invoke.
  Parameters:
    $name: The unique name of the tool.
    $callback: The PHP callable to execute when the tool is called.

Prism\Tool::withParameters(Prism\Schema\ObjectSchema $schema):
  Purpose: Defines the input parameters for a tool using a schema.
  Parameters:
    $schema: An ObjectSchema defining the tool's expected parameters.

Prism::withToolChoice(string $choice):
  Purpose: Controls how the AI model uses tools (e.g., 'auto', 'none', specific tool name).
  Parameters:
    $choice: The tool choice strategy.

Prism::stream():
  Purpose: Enables streaming responses from the AI model, useful for conversational interfaces.
```

----------------------------------------

TITLE: Generate Text using Prism's Fluent Helper Function
DESCRIPTION: This snippet demonstrates the use of Prism's convenient `prism()` helper function, which resolves the `Prism` instance from the application container. It provides a more concise and fluent syntax for initiating text generation requests, similar to the main `Prism` facade.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/getting-started/introduction.md#_snippet_1

LANGUAGE: php
CODE:
```
prism()
    ->text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();
```

----------------------------------------

TITLE: Maintain Conversation Context with Message Chains in Prism PHP
DESCRIPTION: Explains how to use `withMessages` to pass a series of messages, enabling multi-turn conversations and maintaining context across interactions. It demonstrates using `UserMessage` and `AssistantMessage`.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/text-generation.md#_snippet_3

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->asText();
```

----------------------------------------

TITLE: Set up basic text response faking with Prism PHP
DESCRIPTION: This snippet demonstrates how to set up a basic fake text response using Prism's testing utilities. It shows how to define the expected text and usage for a single response, then use Prism's fake method to intercept calls and assert the output.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/testing.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\Testing\TextResponseFake;

it('can generate text', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20));

    // Set up the fake
    $fake = Prism::fake([$fakeResponse]);

    // Run your code
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('Who are you?')
        ->asText();

    // Make assertions
    expect($response->text)->toBe('Hello, I am Claude!');
});
```

----------------------------------------

TITLE: Generate Single Text Embedding with Prism PHP
DESCRIPTION: This snippet demonstrates how to generate a single text embedding using the Prism PHP library. It initializes the embeddings generator with OpenAI and a specific model, processes input text, and retrieves the resulting vector and token usage.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/embeddings.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text goes here')
    ->asEmbeddings();

// Get your embeddings vector
$embeddings = $response->embeddings[0]->embedding;

// Check token usage
echo $response->usage->tokens;
```

----------------------------------------

TITLE: Handle Text Generation Responses in Prism PHP
DESCRIPTION: Details how to access and interpret the response object from text generation. It covers retrieving the generated text, finish reason, token usage statistics, individual generation steps, and message history.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/text-generation.md#_snippet_6

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Access the generated text
echo $response->text;

// Check why the generation stopped
echo $response->finishReason->name;

// Get token usage statistics
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// For multi-step generations, examine each step
foreach ($response->steps as $step) {
    echo "Step text: {$step->text}";
    echo "Step tokens: {$step->usage->completionTokens}";
}

// Access message history
foreach ($response->responseMessages as $message) {
    if ($message instanceof AssistantMessage) {
        echo $message->content;
    }
}
```

----------------------------------------

TITLE: Testing AI Tool Usage in Prism PHP
DESCRIPTION: Demonstrates how to test AI tool calls within the Prism PHP library. It sets up a fake response sequence where the AI first calls a 'weather' tool and then uses the tool's result to form a final text response. The example asserts the correct tool call arguments, tool results, and the final generated text.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/testing.md#_snippet_3

LANGUAGE: php
CODE:
```
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

it('can use weather tool', function () {
    // Define the expected tool call and response sequence
    $responses = [
        (new ResponseBuilder)
            ->addStep(
                // First response: AI decides to use the weather tool
                TextStepFake::make()
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_123',
                            name: 'weather',
                            arguments: ['city' => 'Paris']
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withUsage(new Usage(15, 25))
                    ->withMeta(new Meta('fake-1', 'fake-model'))
            )
            ->addStep(
                // Second response: AI uses the tool result to form a response
                TextStepFake::make()
                    ->withText('Based on current conditions, the weather in Paris is sunny with a temperature of 72°F.')
                    ->withToolResults([
                        new ToolResult(
                            toolCallId: 'call_123',
                            toolName: 'weather',
                            args: ['city' => 'Paris'],
                            result: 'Sunny, 72°F'
                        ),
                    ])
                    ->withFinishReason(FinishReason::Stop)
                    ->withUsage(new Usage(20, 30))
                    ->withMeta(new Meta('fake-2', 'fake-model')),
            )
            ->toResponse(),
    ];

    // Set up the fake
    Prism::fake($responses);

    // Create the weather tool
    $weatherTool = Tool::as('weather')
        ->for('Get weather information')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city) => "The weather in {$city} is sunny with a temperature of 72°F");

    // Run the actual test
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What\'s the weather in Paris?')
        ->withTools([$weatherTool])
        ->withMaxSteps(2)
        ->asText();

    // Assert the response has the correct number of steps
    expect($response->steps)->toHaveCount(2);

    // Assert tool calls were made correctly
    expect($response->steps[0]->toolCalls)->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->name)->toBe('weather');
    expect($response->steps[0]->toolCalls[0]->arguments())->toBe(['city' => 'Paris']);

    // Assert tool results were processed
    expect($response->toolResults)->toHaveCount(1);
    expect($response->toolResults[0]->result)
        ->toBe('Sunny, 72°F');

    // Assert final response
    expect($response->text)
        ->toBe('Based on current conditions, the weather in Paris is sunny with a temperature of 72°F.');
});
```

----------------------------------------

TITLE: Generate Image with Prism using OpenAI DALL-E 3
DESCRIPTION: Demonstrates the basic setup and usage of Prism to generate an image from a text prompt using the OpenAI DALL-E 3 model. It shows how to initialize Prism, specify the model, provide a prompt, and retrieve the image URL.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/image-generation.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;

$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
echo $image->url; // https://oaidalleapiprodscus.blob.core.windows.net/...
```

----------------------------------------

TITLE: Generate Text with Prism using Various LLM Providers
DESCRIPTION: This example demonstrates how to generate text using Prism's unified interface, showcasing the flexibility to switch between different AI providers such as Anthropic, Mistral, Ollama, and OpenAI. It utilizes the `Prism::text()` method to specify the provider, model, system prompt, and user prompt, then retrieves and echoes the generated text content.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/getting-started/introduction.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Mistral, 'mistral-medium')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'llama2')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

----------------------------------------

TITLE: Best Practice: Use `withSystemPrompt` for Provider Interoperability (PHP)
DESCRIPTION: Highlights a best practice for handling system messages across multiple providers. It advises against using `SystemMessage` directly in `withMessages` when provider switching is expected, and instead recommends `withSystemPrompt` for better portability, as Prism can handle provider-specific formatting.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/advanced/provider-interoperability.md#_snippet_3

LANGUAGE: php
CODE:
```
// Avoid this when switching between providers
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withMessages([
        new SystemMessage('You are a helpful assistant.'),
        new UserMessage('Tell me about AI'),
    ])
    ->asText();

// Prefer this instead
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSystemPrompt('You are a helpful assistant.')
    ->withPrompt('Tell me about AI')
    ->asText();
```

----------------------------------------

TITLE: Best Practice: Writing Clear and Concise Schema Field Descriptions in PHP
DESCRIPTION: This snippet emphasizes the importance of providing clear and informative descriptions for schema fields. It contrasts a vague description with a detailed one, demonstrating how better descriptions improve clarity for developers and AI providers.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/schemas.md#_snippet_12

LANGUAGE: php
CODE:
```
// ❌ Not helpful
new StringSchema('name', 'the name');

// ✅ Much better
new StringSchema('name', 'The user\'s display name (2-50 characters)');
```

----------------------------------------

TITLE: Generate Embedding from Direct Text Input (Prism PHP)
DESCRIPTION: This snippet illustrates how to generate an embedding by directly providing text as input to the Prism PHP embeddings generator. It uses the `fromInput` method to process a single string.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/embeddings.md#_snippet_2

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Analyze this text')
    ->asEmbeddings();
```

----------------------------------------

TITLE: General Provider Configuration Template
DESCRIPTION: Illustrates the common structure for configuring individual AI providers within the `providers` section of the Prism configuration, including placeholders for API keys, URLs, and other provider-specific settings.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/getting-started/configuration.md#_snippet_2

LANGUAGE: php
CODE:
```
'providers' => [
    'provider-name' => [
        'api_key' => env('PROVIDER_API_KEY', ''),
        'url' => env('PROVIDER_URL', 'https://api.provider.com'),
        // Other provider-specific settings
    ],
],
```

----------------------------------------

TITLE: Prism Text Generation API
DESCRIPTION: Covers the core text generation capabilities of Prism, including basic usage, system prompts, and integrating with Laravel views for prompt templating. Demonstrates how to switch between different AI providers seamlessly.
SOURCE: https://github.com/prism-php/prism/blob/main/workshop.md#_snippet_0

LANGUAGE: APIDOC
CODE:
```
Prism::text(string $prompt):
  Purpose: Initiates a text generation request.
  Parameters:
    $prompt: The main text prompt for generation.

Prism::withSystemPrompt(string $prompt):
  Purpose: Adds a system-level instruction or context to the generation request.
  Parameters:
    $prompt: The system prompt string.

Prism::withProvider(string $providerName):
  Purpose: Switches the AI provider for the current generation request.
  Parameters:
    $providerName: The name of the provider (e.g., 'openai', 'anthropic').
```

----------------------------------------

TITLE: Handle AI Responses and Inspect Tool Results in Prism PHP
DESCRIPTION: This snippet illustrates how to handle responses from AI interactions that involve tool calls in Prism PHP. It demonstrates accessing the final text response, iterating through toolResults to inspect the outcomes of executed tools, and examining toolCalls within each step of the AI's process.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/tools-function-calling.md#_snippet_12

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();

// Get the final answer
echo $response->text;

// ->text is empty for tool calls

// Inspect tool usage

if ($response->toolResults) {
    foreach ($response->toolResults as $toolResult) {
        echo "Tool: " . $toolResult->toolName . "\n";
        echo "Result: " . $toolResult->result . "\n";
    }
}


foreach ($response->steps as $step) {
    if ($step->toolCalls) {
        foreach ($step->toolCalls as $toolCall) {
            echo "Tool: " . $toolCall->name . "\n";
            echo "Arguments: " . json_encode($toolCall->arguments()) . "\n";
        }
    }
}
```

----------------------------------------

TITLE: Basic AI Response Streaming with Prism PHP
DESCRIPTION: Demonstrates how to initiate a basic streaming response from the Prism library, processing and displaying each text chunk as it arrives to provide real-time output to the user. It includes flushing the output buffer for immediate browser display.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/streaming-output.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;

$response = Prism::text()
    ->using('openai', 'gpt-4')
    ->withPrompt('Tell me a story about a brave knight.')
    ->asStream();

// Process each chunk as it arrives
foreach ($response as $chunk) {
    echo $chunk->text;
    // Flush the output buffer to send text to the browser immediately
    ob_flush();
    flush();
}
```

----------------------------------------

TITLE: Integrating Tools with AI Streaming in Prism PHP
DESCRIPTION: Shows how to define and integrate custom tools with a streaming AI response. It demonstrates processing tool calls and results in real-time alongside the generated text, enabling interactive AI applications that can dynamically use external functionalities.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/streaming-output.md#_snippet_2

LANGUAGE: php
CODE:
```
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;

$weatherTool = Tool::as('weather')
    ->for('Get current weather information')
    ->withStringParameter('city', 'City name')
    ->using(function (string $city) {
        return "The weather in {$city} is sunny and 72°F.";
    });

$response = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withTools([$weatherTool])
    ->withMaxSteps(3) // Control maximum number of back-and-forth steps
    ->withPrompt('What\'s the weather like in San Francisco today?')
    ->asStream();

$fullResponse = '';
foreach ($response as $chunk) {
    // Append each chunk to build the complete response
    $fullResponse .= $chunk->text;

    // Check for tool calls
    if ($chunk->chunkType === ChunkType::ToolCall) {
        foreach ($chunk->toolCalls as $call) {
            echo "Tool called: " . $call->name;
        }
    }

    // Check for tool results
    if ($chunk->chunkType === ChunkType::ToolResult) {
        foreach ($chunk->toolResults as $result) {
            echo "Tool result: " . $result->result;
        }
    }
}

echo "Final response: " . $fullResponse;
```

----------------------------------------

TITLE: Testing Streamed Responses in Prism PHP
DESCRIPTION: Illustrates how to test AI streamed responses by faking a text response and iterating over the streamed chunks. The fake provider automatically converts the given text into a stream of chunks, allowing for verification of the streaming behavior.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/testing.md#_snippet_4

LANGUAGE: php
CODE:
```
Prism::fake([
    TextResponseFake::make()
        ->withText('fake response text') // text to be streamed
        ->withFinishReason(FinishReason::Stop), // finish reason for final chunk
]);

$text = Prism::text()
    ->using('anthropic', 'claude-3-sonnet')
    ->withPrompt('What is the meaning of life?')
    ->asStream();

$outputText = '';
foreach ($text as $chunk) {
    $outputText .= $chunk->text; // will be ['fake ', 'respo', 'nse t', 'ext', ''];
}

expect($outputText)->toBe('fake response text');
```

----------------------------------------

TITLE: Handling Exceptions in Prism PHP Text Generation
DESCRIPTION: Illustrates how to implement robust error handling for text generation operations in Prism PHP using `try-catch` blocks to specifically catch `PrismException` and generic `Throwable` errors, and log the details.
SOURCE: https://github.com/prism-php/prism/blob/main/tests/Fixtures/test-embedding-file.md#_snippet_13

LANGUAGE: php
CODE:
```
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-sonnet')
        ->withPrompt('Generate text...')
        ->generate();
} catch (PrismException $e) {
    Log::error('Text generation failed:', ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    Log::error('Generic error:', ['error' => $e->getMessage]);
}
```

----------------------------------------

TITLE: Prism Text Generation Parameters API Reference
DESCRIPTION: Documents various methods available to fine-tune text generation, including `withMaxTokens`, `usingTemperature`, `usingTopP`, `withClientOptions`, `withClientRetry`, and `usingProviderConfig`. Provides details on their purpose and usage.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/text-generation.md#_snippet_5

LANGUAGE: APIDOC
CODE:
```
Generation Parameters:
- `withMaxTokens`
  - Description: Maximum number of tokens to generate.
- `usingTemperature`
  - Description: Temperature setting. The value is passed through to the provider. The range depends on the provider and model. For most providers, 0 means almost deterministic results, and higher values mean more randomness.
  - Tip: It is recommended to set either temperature or topP, but not both.
- `usingTopP`
  - Description: Nucleus sampling. The value is passed through to the provider. The range depends on the provider and model. For most providers, nucleus sampling is a number between 0 and 1. E.g., 0.1 would mean that only tokens with the top 10% probability mass are considered.
  - Tip: It is recommended to set either temperature or topP, but not both.
- `withClientOptions`
  - Description: Allows passing Guzzle's request options (e.g., `['timeout' => 30]`) to the underlying Laravel HTTP client.
- `withClientRetry`
  - Description: Configures retries for the underlying Laravel HTTP client (e.g., `(3, 100)` for 3 retries with 100ms delay).
- `usingProviderConfig`
  - Description: Allows complete or partial override of the provider's configuration. Useful for multi-tenant applications where users supply their own API keys. Values are merged with the original configuration.
```

----------------------------------------

TITLE: Generate Multiple Text Embeddings with Prism PHP
DESCRIPTION: This example shows how to generate multiple text embeddings simultaneously using the Prism PHP library. It accepts both direct string inputs and an array of strings, then iterates through the returned embeddings to access individual vectors and checks total token usage.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/embeddings.md#_snippet_1

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    // First embedding
    ->fromInput('Your text goes here')
    // Second embedding
    ->fromInput('Your second text goes here')
    // Third and fourth embeddings
    ->fromArray([
        'Third',
        'Fourth'
    ])
    ->asEmbeddings();

/** @var Embedding $embedding */
foreach ($embeddings as $embedding) {
    // Do something with your embeddings
    $embedding->embedding;
}

// Check token usage
echo $response->usage->tokens;
```

----------------------------------------

TITLE: Catching PrismRateLimitedException for Rate Limit Hits
DESCRIPTION: This snippet demonstrates how to catch the `PrismRateLimitedException` thrown by Prism when an API rate limit is exceeded. It shows how to iterate through the `rateLimits` property of the exception, which contains an array of `ProviderRateLimit` objects, allowing for graceful failure and inspection of specific limits.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/advanced/rate-limits.md#_snippet_1

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
        ->withPrompt('Hello world!')
        ->asText();
}
catch (PrismRateLimitedException $e) {
    /** @var ProviderRateLimit $rate_limit */
    foreach ($e->rateLimits as $rate_limit) {
        // Loop through rate limits...
    }

    // Log, fail gracefully, etc.
}
```

----------------------------------------

TITLE: Processing Streaming Chunks and Usage Information in Prism
DESCRIPTION: Illustrates how to iterate over streaming chunks, access the text content, and extract additional information like token usage (prompt and completion tokens) and the generation's finish reason from each chunk. This allows for detailed monitoring of the AI response.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/streaming-output.md#_snippet_1

LANGUAGE: php
CODE:
```
foreach ($response as $chunk) {
    // The text fragment in this chunk
    echo $chunk->text;

    if ($chunk->usage) {
        echo "Prompt tokens: " . $chunk->usage->promptTokens;
        echo "Completion tokens: " . $chunk->usage->completionTokens;
    }

    // Check if this is the final chunk
    if ($chunk->finishReason === FinishReason::Stop) {
        echo "Generation complete: " . $chunk->finishReason->name;
    }
}
```

----------------------------------------

TITLE: Get Structured Data with Prism PHP
DESCRIPTION: This PHP example demonstrates how to use the Prism library to define a schema for a movie review and retrieve structured data from an OpenAI model. It shows schema definition, prompt configuration, and accessing the structured response.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/structured-output.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    name: 'movie_review',
    description: 'A structured movie review',
    properties: [
        new StringSchema('title', 'The movie title'),
        new StringSchema('rating', 'Rating out of 5 stars'),
        new StringSchema('summary', 'Brief review summary')
    ],
    requiredFields: ['title', 'rating', 'summary']
);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSchema($schema)
    ->withPrompt('Review the movie Inception')
    ->asStructured();

// Access your structured data
$review = $response->structured;
echo $review['title'];    // "Inception"
echo $review['rating'];   // "5 stars"
echo $review['summary'];  // "A mind-bending..."
```

----------------------------------------

TITLE: Define and Use a Weather Tool with Prism
DESCRIPTION: Demonstrates how to define a 'weather' tool with a string parameter for city and integrate it into a Prism text generation request, showing how the AI can call the tool to get weather information.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/tools-function-calling.md#_snippet_0

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('weather')
    ->for('Get current weather conditions')
    ->withStringParameter('city', 'The city to get weather for')
    ->using(function (string $city): string {
        // Your weather API logic here
        return "The weather in {$city} is sunny and 72°F.";
    });

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();
```

----------------------------------------

TITLE: Set AI Behavior with System Prompts in Prism PHP
DESCRIPTION: Illustrates how to use `withSystemPrompt` to define the AI's persona or context, ensuring consistent responses. This example sets the AI as an expert mathematician.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/text-generation.md#_snippet_1

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->asText();
```

----------------------------------------

TITLE: Set AI behavior with system prompts in Prism
DESCRIPTION: Shows how to use a system prompt to guide the AI's persona and context, ensuring consistent responses. This example sets the AI as an expert mathematician.
SOURCE: https://github.com/prism-php/prism/blob/main/tests/Fixtures/test-embedding-file.md#_snippet_1

LANGUAGE: php
CODE:
```
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->generate();
```

----------------------------------------

TITLE: Integrate Prism Server with Open WebUI using Docker Compose
DESCRIPTION: Set up Open WebUI and a Laravel application (hosting Prism Server) using Docker Compose for a seamless chat interface experience. This configuration links the services and sets necessary environment variables for API communication.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/prism-server.md#_snippet_4

LANGUAGE: YAML
CODE:
```
services:
  open-webui:
    image: ghcr.io/open-webui/open-webui:main
    ports:
      - "3000:8080"
    environment:
      OPENAI_API_BASE_URLS: "http://laravel:8080/prism/openai/v1"
      WEBUI_SECRET_KEY: "your-secret-key"

  laravel:
    image: serversideup/php:8.3-fpm-nginx
    volumes:
      - ".:/var/www/html"
    environment:
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
    depends_on:
      - open-webui
```

----------------------------------------

TITLE: Prism Message Types for Conversations
DESCRIPTION: Lists the available message types used in Prism for constructing conversation chains, including System, User, Assistant, and Tool Result messages. Notes that SystemMessage may be converted to UserMessage by some providers.
SOURCE: https://github.com/prism-php/prism/blob/main/tests/Fixtures/test-embedding-file.md#_snippet_4

LANGUAGE: APIDOC
CODE:
```
Message Types:
- `SystemMessage`
- `UserMessage`
- `AssistantMessage`
- `ToolResultMessage`

Note: Some providers, like Anthropic, do not support the `SystemMessage` type. In those cases we convert `SystemMessage` to `UserMessage`.
```

----------------------------------------

TITLE: Common Configuration Settings for Prism Structured Output
DESCRIPTION: This section outlines common configuration options available for fine-tuning structured output generations in Prism. It covers model configuration parameters like `maxTokens`, `temperature`, and `topP`, as well as input methods such as `withPrompt`, `withMessages`, and `withSystemPrompt`.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/structured-output.md#_snippet_5

LANGUAGE: APIDOC
CODE:
```
Model Configuration:
- maxTokens: Set the maximum number of tokens to generate
- temperature: Control output randomness (provider-dependent)
- topP: Alternative to temperature for controlling randomness (provider-dependent)

Input Methods:
- withPrompt: Single prompt for generation
- withMessages: Message history for more context
- withSystemPrompt: System-level instructions
```

----------------------------------------

TITLE: Prism Environment Variable Examples
DESCRIPTION: Examples of environment variables (`.env`) used to configure Prism server settings and provider-specific details like API keys and URLs, following Laravel's best practices for sensitive data.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/getting-started/configuration.md#_snippet_3

LANGUAGE: shell
CODE:
```
# Prism Server Configuration
PRISM_SERVER_ENABLED=true

# Provider Configuration
PROVIDER_API_KEY=your-api-key-here
PROVIDER_URL=https://custom-endpoint.com
```

----------------------------------------

TITLE: Create a Basic Search Tool in Prism
DESCRIPTION: Shows a straightforward example of defining a 'search' tool with a string parameter for a query, highlighting the fluent API for tool creation and the requirement for tools to return a string.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/tools-function-calling.md#_snippet_2

LANGUAGE: php
CODE:
```
use Prism\Prism\Facades\Tool;

$searchTool = Tool::as('search')
    ->for('Search for current information')
    ->withStringParameter('query', 'The search query')
    ->using(function (string $query): string {
        // Your search implementation
        return "Search results for: {$query}";
    });
```

----------------------------------------

TITLE: Streaming AI Responses in a Laravel Controller
DESCRIPTION: Provides an example of how to implement AI response streaming within a Laravel controller using `response()->stream()`. It configures the HTTP headers for server-sent events to ensure immediate output flushing and prevent buffering by web servers like Nginx.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/streaming-output.md#_snippet_3

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Illuminate\Http\Response;

public function streamResponse()
{
    return response()->stream(function () {
        $stream = Prism::text()
            ->using('openai', 'gpt-4')
            ->withPrompt('Explain quantum computing step by step.')
            ->asStream();

        foreach ($stream as $chunk) {
            echo $chunk->text;
            ob_flush();
            flush();
        }
    }, 200, [
        'Cache-Control' => 'no-cache',
        'Content-Type' => 'text/event-stream',
        'X-Accel-Buffering' => 'no', // Prevents Nginx from buffering
    ]);
}
```

----------------------------------------

TITLE: Generate Image and Access URL or Base64 Data in PHP
DESCRIPTION: Illustrates a simple image generation request using Prism with OpenAI DALL-E 3. It shows how to check for and access the generated image's URL or base64 data from the response object.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/image-generation.md#_snippet_1

LANGUAGE: php
CODE:
```
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

// Access the generated image
$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "Image URL: " . $image->url;
}
if ($image->hasBase64()) {
    echo "Base64 Image Data: " . $image->base64;
}
```

----------------------------------------

TITLE: Customize DALL-E 3 Image Generation Options in PHP
DESCRIPTION: Shows how to apply provider-specific options for OpenAI's DALL-E 3 model using Prism's `withProviderOptions()` method. This includes setting image size, quality, style, and response format.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/image-generation.md#_snippet_3

LANGUAGE: php
CODE:
```
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A beautiful sunset over mountains')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
        'response_format' => 'url',     // url, b64_json
    ])
    ->generate();
```

----------------------------------------

TITLE: Use Anthropic Tool Calling Mode for Structured Output in Prism PHP
DESCRIPTION: This PHP example shows how to leverage Anthropic's tool calling mode via Prism for more reliable structured output, especially with complex or non-English prompts. It's recommended for robust JSON parsing when direct structured output isn't natively supported.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/structured-output.md#_snippet_2

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::structured()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withPrompt('天氣怎麼樣？應該穿什麼？') // Chinese text with potential quotes
    ->withProviderOptions(['use_tool_calling' => true])
    ->asStructured();
```

----------------------------------------

TITLE: Configuring Object Schemas for Strict Mode Providers (e.g., OpenAI) in PHP
DESCRIPTION: This example demonstrates how to construct an `ObjectSchema` for use with strict mode providers like OpenAI. It highlights the practice of marking all fields as 'required' in the `requiredFields` array, even if some are `nullable`, to ensure explicit definition as per provider requirements.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/schemas.md#_snippet_11

LANGUAGE: php
CODE:
```
// For OpenAI strict mode:
// - All fields should be required
// - Use nullable: true for optional fields
$userSchema = new ObjectSchema(
    name: 'user',
    description: 'User profile',
    properties: [
        new StringSchema('email', 'Required email address'),
        new StringSchema('bio', 'Optional biography', nullable: true)
    ],
    requiredFields: ['email', 'bio'] // Note: bio is required but nullable
);
```

----------------------------------------

TITLE: Define a Basic Object Schema for Structured Data in Prism PHP
DESCRIPTION: This example shows how to create a simple ObjectSchema to define a structured data type like a user profile, combining StringSchema and NumberSchema for its properties. It also demonstrates specifying required fields within the object.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/schemas.md#_snippet_6

LANGUAGE: php
CODE:
```
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$profileSchema = new ObjectSchema(
    name: 'profile',
    description: 'A user\'s public profile information',
    properties: [
        new StringSchema('username', 'The unique username'),
        new StringSchema('bio', 'A short biography'),
        new NumberSchema('joined_year', 'Year the user joined'),
    ],
    requiredFields: ['username']
);
```

----------------------------------------

TITLE: Configure Client Options and Retries for Embeddings (Prism PHP)
DESCRIPTION: This snippet shows how to apply common settings to an embeddings request in Prism PHP, such as adjusting the client timeout and configuring automatic retries for network resilience. These options enhance the robustness of API calls.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/embeddings.md#_snippet_4

LANGUAGE: php
CODE:
```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text here')
    ->withClientOptions(['timeout' => 30]) // Adjust request timeout
    ->withClientRetry(3, 100) // Add automatic retries
    ->asEmbeddings();
```

----------------------------------------

TITLE: Validate Structured Data from Prism PHP Responses
DESCRIPTION: This PHP snippet provides best practices for validating structured data received from Prism. It shows how to check for parsing failures (null structured data) and how to ensure required fields are present in the returned array.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/structured-output.md#_snippet_4

LANGUAGE: php
CODE:
```
if ($response->structured === null) {
    // Handle parsing failure
}

if (!isset($response->structured['required_field'])) {
    // Handle missing required data
}
```

----------------------------------------

TITLE: Include images in Prism messages for multi-modal input
DESCRIPTION: Demonstrates how to add images to messages for multi-modal interactions, supporting images from local paths, URLs, and Base64 encoded strings. The example shows how to create a `UserMessage` with an attached image.
SOURCE: https://github.com/prism-php/prism/blob/main/tests/Fixtures/test-embedding-file.md#_snippet_5

LANGUAGE: php
CODE:
```
use Prism\Prism\ValueObjects\Messages\Support\Image;

// From a local file
$message = new UserMessage(
    "What's in this image?",
    [Image::fromLocalPath('/path/to/image.jpg')]
);

// From a URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl('https://example.com/diagram.png')]
);

// From a Base64
$image = base64_encode(file_get_contents('/path/to/image.jpg'));

$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromBase64($image)]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withMessages([$message])
    ->generate();
```

----------------------------------------

TITLE: Prism Multimodal Input APIs
DESCRIPTION: Explores Prism's capabilities for handling multimodal inputs, specifically images and documents. Details the use of `Image` and `Document` value objects and various input methods (path, base64, URL).
SOURCE: https://github.com/prism-php/prism/blob/main/workshop.md#_snippet_1

LANGUAGE: APIDOC
CODE:
```
Prism\ValueObjects\Image:
  Purpose: Represents an image input for multimodal AI models.
  Methods:
    fromPath(string $path): Creates an Image object from a file path.
    fromBase64(string $base64): Creates an Image object from a base64 encoded string.
    fromUrl(string $url): Creates an Image object from a URL.

Prism\ValueObjects\Document:
  Purpose: Represents a document input for multimodal AI models.
  Methods:
    fromPath(string $path): Creates a Document object from a file path.
    fromBase64(string $base64): Creates a Document object from a base64 encoded string.
    fromUrl(string $url): Creates a Document object from a URL.
```

----------------------------------------

TITLE: Adjusting Streamed Response Chunk Size in Prism PHP
DESCRIPTION: Shows how to control the chunk size when faking streamed responses in Prism PHP. By using `withFakeChunkSize`, developers can simulate different streaming behaviors, such as character-by-character streaming, for more granular testing.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/core-concepts/testing.md#_snippet_5

LANGUAGE: php
CODE:
```
Prism::fake([
    TextResponseFake::make()->withText('fake response text'),
])->withFakeChunkSize(1);
```

----------------------------------------

TITLE: Create a new Laravel Project and Install Prism
DESCRIPTION: Instructions to initialize a new Laravel project and then add the Prism PHP library as a dependency, followed by publishing Prism's configuration file.
SOURCE: https://github.com/prism-php/prism/blob/main/workshop.md#_snippet_4

LANGUAGE: bash
CODE:
```
composer create-project laravel/laravel prism-workshop
cd prism-workshop
composer require prism-php/prism
php artisan vendor:publish --tag=prism-config
```

----------------------------------------

TITLE: Maintain conversation context with message chains in Prism
DESCRIPTION: Explains how to use message chains to build interactive conversations, passing a series of user and assistant messages to maintain context across turns.
SOURCE: https://github.com/prism-php/prism/blob/main/tests/Fixtures/test-embedding-file.md#_snippet_3

LANGUAGE: php
CODE:
```
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->generate();
```

----------------------------------------

TITLE: Enable Anthropic Prompt Caching for Messages and Tools in PHP
DESCRIPTION: This code shows how to enable ephemeral prompt caching for System Messages, User Messages (including text, image, and PDF), and Tools using the `withProviderOptions()` method in Prism. Prompt caching significantly reduces latency and API costs for repeated content blocks. An alternative using the `AnthropicCacheType` Enum is also provided for type-safe configuration.
SOURCE: https://github.com/prism-php/prism/blob/main/docs/providers/anthropic.md#_snippet_1

LANGUAGE: php
CODE:
```
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withMessages([
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral']),

        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache me')
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();
```

LANGUAGE: php
CODE:
```
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;

(new UserMessage('I am a long re-usable user message.'))->withProviderOptions(['cacheType' => AnthropicCacheType::ephemeral])
```
