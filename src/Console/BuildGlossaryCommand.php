<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

class BuildGlossaryCommand extends Command
{
    protected $signature = 'ai-translator:build-glossary
                          {--source=en : Source language code}
                          {--output-dir=glossary : Directory to save generated glossary}
                          {--lang-dir= : Language directory (defaults to config)}
                          {--glossary-files=glossary.php,glossary.json,terms.php,dictionary.php : Comma-separated glossary files to scan}
                          {--extract-ambiguous : Extract potentially ambiguous terms from translations}
                          {--min-length=3 : Minimum word length to include}
                          {--format=json : Output format (json, php, md)}';

    protected $description = 'Build glossary from existing glossary files and extract ambiguous terms from translations';

    protected array $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
    ];

    protected string $sourceLocale;
    protected string $languageDirectory;
    protected string $outputDirectory;
    protected array $glossaryFiles = [];
    protected array $extractAmbiguous;
    protected int $minLength;
    protected string $outputFormat;
    
    protected array $glossary = [];
    protected array $ambiguousTerms = [];

    public function handle(): int
    {
        $this->initializeConfiguration();
        $this->displayHeader();

        // Scan existing glossary files
        $this->scanExistingGlossaries();
        
        // Extract ambiguous terms if requested
        if ($this->extractAmbiguous) {
            $this->extractAmbiguousTerms();
        }
        
        // Build and save glossary
        $this->buildGlossary();
        $this->saveGlossary();

        $this->displaySuccess();
        return self::SUCCESS;
    }

    protected function initializeConfiguration(): void
    {
        $this->sourceLocale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
        $this->languageDirectory = $this->option('lang-dir') ?? config('ai-translator.source_directory', 'lang');
        $this->outputDirectory = $this->option('output-dir');
        $this->extractAmbiguous = $this->option('extract-ambiguous');
        $this->minLength = (int) $this->option('min-length');
        $this->outputFormat = $this->option('format');
        
        $glossaryFilesOption = $this->option('glossary-files');
        $this->glossaryFiles = array_filter(array_map('trim', explode(',', $glossaryFilesOption)));
        
        // Create output directory if it doesn't exist
        if (!File::exists($this->outputDirectory)) {
            File::makeDirectory($this->outputDirectory, 0755, true);
        }
    }

    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line($this->colors['blue'] . str_repeat('─', 60) . $this->colors['reset']);
        $this->line($this->colors['blue'] . '│' . $this->colors['reset'] . 
                   str_pad($this->colors['bold'] . ' Laravel AI Translator - Glossary Builder ' . $this->colors['reset'], 68, ' ', STR_PAD_BOTH) . 
                   $this->colors['blue'] . '│' . $this->colors['reset']);
        $this->line($this->colors['blue'] . str_repeat('─', 60) . $this->colors['reset']);
        $this->newLine();
    }

    protected function scanExistingGlossaries(): void
    {
        $this->info("📚 Scanning existing glossary files...");
        
        $foundGlossaries = 0;
        $totalTerms = 0;
        
        // Scan in source language directory first
        foreach ($this->glossaryFiles as $glossaryFile) {
            $filePath = "{$this->languageDirectory}/{$this->sourceLocale}/{$glossaryFile}";
            
            if (File::exists($filePath)) {
                $terms = $this->parseGlossaryFile($filePath);
                if (!empty($terms)) {
                    $this->glossary = array_merge($this->glossary, $terms);
                    $foundGlossaries++;
                    $totalTerms += count($terms);
                    $this->line("  ✓ Loaded {$filePath}: " . count($terms) . " terms");
                }
            }
        }
        
        // Also check root language directory for JSON files
        foreach ($this->glossaryFiles as $glossaryFile) {
            if (str_ends_with($glossaryFile, '.json')) {
                $filePath = "{$this->languageDirectory}/{$glossaryFile}";
                
                if (File::exists($filePath)) {
                    $terms = $this->parseGlossaryFile($filePath);
                    if (!empty($terms)) {
                        $this->glossary = array_merge($this->glossary, $terms);
                        $foundGlossaries++;
                        $totalTerms += count($terms);
                        $this->line("  ✓ Loaded {$filePath}: " . count($terms) . " terms");
                    }
                }
            }
        }
        
        if ($foundGlossaries === 0) {
            $this->warn("  ⚠ No existing glossary files found. Will create new glossary from translations.");
        } else {
            $this->line("  ✓ Total terms loaded: {$totalTerms}");
        }
    }

    protected function parseGlossaryFile(string $filePath): array
    {
        $terms = [];
        
        try {
            if (str_ends_with($filePath, '.php')) {
                $phpTransformer = new PHPLangTransformer();
                $data = $phpTransformer->parse($filePath);
                $terms = $this->flattenGlossaryData($data);
            } elseif (str_ends_with($filePath, '.json')) {
                $jsonTransformer = new JSONLangTransformer();
                $data = $jsonTransformer->parse($filePath);
                $terms = $this->flattenGlossaryData($data);
            }
        } catch (\Exception $e) {
            $this->warn("  ⚠ Failed to parse {$filePath}: " . $e->getMessage());
        }
        
        return $terms;
    }

    protected function flattenGlossaryData(array $data): array
    {
        $terms = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Handle nested glossary structure
                foreach ($value as $subKey => $subValue) {
                    if (is_string($subValue)) {
                        $terms[$subKey] = $subValue;
                    }
                }
            } elseif (is_string($value)) {
                $terms[$key] = $value;
            }
        }
        
        return $terms;
    }

    protected function extractAmbiguousTerms(): void
    {
        $this->info("🔍 Extracting potentially ambiguous terms from translations...");
        
        $phpTransformer = new PHPLangTransformer();
        $jsonTransformer = new JSONLangTransformer();
        
        $allTranslations = [];
        
        // Collect all translations from source language
        $phpFiles = glob("{$this->languageDirectory}/{$this->sourceLocale}/*.php");
        foreach ($phpFiles ?? [] as $file) {
            try {
                $strings = $phpTransformer->parse($file);
                $flatStrings = $phpTransformer->flatten($strings);
                $allTranslations = array_merge($allTranslations, array_values($flatStrings));
            } catch (\Exception $e) {
                $this->warn("  ⚠ Failed to parse {$file}: " . $e->getMessage());
            }
        }
        
        // Check JSON file
        $jsonFile = "{$this->languageDirectory}/{$this->sourceLocale}.json";
        if (File::exists($jsonFile)) {
            try {
                $strings = $jsonTransformer->parse($jsonFile);
                $flatStrings = $jsonTransformer->flatten($strings);
                $allTranslations = array_merge($allTranslations, array_values($flatStrings));
            } catch (\Exception $e) {
                $this->warn("  ⚠ Failed to parse {$jsonFile}: " . $e->getMessage());
            }
        }
        
        // Find potentially ambiguous terms
        $this->findAmbiguousTerms($allTranslations);
        
        $this->line("  ✓ Found " . count($this->ambiguousTerms) . " potentially ambiguous terms");
    }

    protected function findAmbiguousTerms(array $translations): void
    {
        $ambiguousPatterns = [
            // Words that can have multiple meanings
            'set' => ['to place something', 'a collection of items', 'ready/prepared'],
            'run' => ['to execute', 'to move fast', 'a sequence'],
            'bank' => ['financial institution', 'river bank', 'to rely on'],
            'match' => ['to correspond', 'a game/contest', 'a stick for lighting'],
            'save' => ['to rescue', 'to store data', 'to keep money'],
            'file' => ['a document', 'to submit', 'a tool for smoothing'],
            'right' => ['correct', 'direction', 'entitlement'],
            'left' => ['direction', 'remaining', 'departed'],
            'table' => ['furniture', 'data structure', 'to postpone'],
            'close' => ['to shut', 'near', 'to end'],
            'open' => ['to unlock', 'not closed', 'to begin'],
            'light' => ['illumination', 'not heavy', 'pale color'],
            'fair' => ['just/equitable', 'a carnival', 'pale complexion'],
            'book' => ['a publication', 'to reserve', 'to record'],
            'lead' => ['to guide', 'a metal', 'to be ahead'],
            'date' => ['calendar day', 'romantic meeting', 'a fruit'],
            'content' => ['satisfied', 'subject matter', 'substance'],
            'number' => ['a digit', 'quantity', 'more numb'],
            'address' => ['location', 'to speak to', 'to deal with'],
            'can' => ['able to', 'a container', 'to preserve'],
            'will' => ['future tense', 'testament', 'determination'],
            'may' => ['might', 'the month', 'permission'],
            'minute' => ['60 seconds', 'very small', 'meeting notes'],
            'second' => ['time unit', 'ordinal number', 'to support'],
            'point' => ['a dot', 'to indicate', 'a sharp end', 'a score'],
            'order' => ['sequence', 'command', 'to request'],
            'state' => ['condition', 'government region', 'to declare'],
            'case' => ['situation', 'container', 'legal matter'],
            'kind' => ['type', 'gentle', 'considerate'],
            'well' => ['satisfactory', 'water source', 'in good health'],
            'back' => ['rear', 'to support', 'to return'],
            'down' => ['direction', 'sad', 'soft feathers'],
            'up' => ['direction', 'awake', 'higher'],
            'over' => ['above', 'finished', 'excessive'],
            'round' => ['circular', 'a cycle', 'approximately'],
            'line' => ['a mark', 'a row', 'a boundary'],
            'form' => ['shape', 'document', 'to create'],
            'change' => ['to alter', 'coins', 'replacement'],
            'turn' => ['to rotate', 'opportunity', 'to become'],
            'page' => ['sheet of paper', 'to call', 'web page'],
            'hand' => ['body part', 'to give', 'worker'],
            'head' => ['body part', 'leader', 'to go toward'],
            'face' => ['front of head', 'to confront', 'surface'],
            'place' => ['location', 'to put', 'position'],
            'time' => ['duration', 'occasion', 'to measure'],
            'work' => ['job', 'to function', 'effort'],
            'part' => ['portion', 'component', 'to separate'],
            'way' => ['method', 'path', 'manner'],
            'side' => ['edge', 'team', 'aspect'],
            'end' => ['finish', 'extremity', 'purpose'],
            'top' => ['highest part', 'shirt', 'superior'],
            'cut' => ['to slice', 'a wound', 'reduction'],
            'check' => ['to verify', 'payment method', 'pattern'],
            'watch' => ['timepiece', 'to observe', 'to guard'],
            'play' => ['to perform', 'drama', 'to compete'],
            'move' => ['to relocate', 'action', 'to emotionally affect'],
            'show' => ['to display', 'program', 'to prove'],
            'call' => ['to telephone', 'to name', 'to summon'],
            'ask' => ['to request', 'to question', 'to invite'],
            'try' => ['to attempt', 'to test', 'rugby score'],
            'use' => ['to utilize', 'purpose', 'benefit'],
            'find' => ['to locate', 'to discover', 'a good deal'],
            'give' => ['to provide', 'to yield', 'elasticity'],
            'tell' => ['to inform', 'to distinguish', 'to count'],
            'get' => ['to obtain', 'to become', 'to understand'],
            'make' => ['to create', 'brand', 'to force'],
            'go' => ['to travel', 'to function', 'board game'],
            'come' => ['to arrive', 'to happen', 'to originate'],
            'know' => ['to understand', 'to be acquainted', 'to recognize'],
            'take' => ['to grab', 'to accept', 'to require'],
            'see' => ['to view', 'to understand', 'episcopal seat'],
            'look' => ['to gaze', 'appearance', 'to search'],
            'want' => ['to desire', 'to need', 'to lack'],
            'put' => ['to place', 'stock option', 'golf stroke'],
            'say' => ['to speak', 'to suppose', 'influence'],
            'think' => ['to consider', 'to believe', 'to remember'],
            'help' => ['to assist', 'aid', 'domestic worker'],
            'keep' => ['to retain', 'to continue', 'castle tower'],
            'start' => ['to begin', 'to startle', 'beginning'],
            'stop' => ['to cease', 'a break', 'to prevent'],
            'hold' => ['to grasp', 'to contain', 'cargo area'],
            'turn' => ['to rotate', 'opportunity', 'to change'],
            'leave' => ['to depart', 'permission', 'foliage'],
            'bring' => ['to carry', 'to cause', 'to take along'],
            'build' => ['to construct', 'physique', 'to develop'],
            'grow' => ['to increase', 'to cultivate', 'to become'],
            'lose' => ['to misplace', 'to be defeated', 'to shed'],
            'win' => ['to be victorious', 'to gain', 'to earn'],
            'send' => ['to dispatch', 'to transmit', 'to cause to go'],
            'break' => ['to shatter', 'a pause', 'to violate'],
            'buy' => ['to purchase', 'to accept', 'a good deal'],
            'pay' => ['to compensate', 'to be profitable', 'wages'],
            'cost' => ['price', 'to require payment', 'expense'],
            'sell' => ['to market', 'to convince', 'to betray'],
            'draw' => ['to sketch', 'to attract', 'tie game'],
            'write' => ['to compose', 'to record', 'to inscribe'],
            'read' => ['to peruse', 'to interpret', 'well-educated'],
            'hear' => ['to listen', 'to learn', 'to try legally'],
            'feel' => ['to touch', 'to experience', 'texture'],
            'touch' => ['to make contact', 'to affect', 'small amount'],
            'talk' => ['to speak', 'conversation', 'to discuss'],
            'walk' => ['to stroll', 'path', 'to abandon'],
            'sit' => ['to be seated', 'to pose', 'to be situated'],
            'stand' => ['to rise', 'position', 'booth'],
            'fall' => ['to drop', 'autumn', 'to decrease'],
            'rise' => ['to ascend', 'increase', 'to rebel'],
            'fly' => ['to soar', 'insect', 'to travel by air'],
            'drive' => ['to operate vehicle', 'motivation', 'path'],
            'ride' => ['to travel on', 'journey', 'to tease'],
            'sleep' => ['to rest', 'to be inactive', 'crusty deposit'],
            'wake' => ['to rouse', 'funeral viewing', 'ship\'s trail'],
            'eat' => ['to consume', 'to erode', 'to bother'],
            'drink' => ['to consume liquid', 'beverage', 'to absorb'],
            'cook' => ['to prepare food', 'chef', 'to falsify'],
            'clean' => ['to tidy', 'pure', 'to remove'],
            'wash' => ['to cleanse', 'laundry', 'to flow against'],
            'dry' => ['not wet', 'to remove moisture', 'lacking emotion'],
            'hot' => ['high temperature', 'spicy', 'stolen'],
            'cold' => ['low temperature', 'illness', 'unfriendly'],
            'warm' => ['mild temperature', 'friendly', 'to heat'],
            'cool' => ['low temperature', 'fashionable', 'to chill'],
            'fast' => ['quick', 'to abstain from food', 'secure'],
            'slow' => ['not fast', 'to reduce speed', 'behind schedule'],
            'big' => ['large', 'important', 'generous'],
            'small' => ['little', 'minor', 'narrow'],
            'long' => ['extended', 'to yearn', 'for a long time'],
            'short' => ['brief', 'not tall', 'to sell borrowed stock'],
            'high' => ['tall', 'elevated', 'drug-induced state'],
            'low' => ['not high', 'sad', 'quiet'],
            'wide' => ['broad', 'far from target', 'fully open'],
            'narrow' => ['not wide', 'limited', 'barely'],
            'deep' => ['far down', 'profound', 'intense'],
            'shallow' => ['not deep', 'superficial', 'not profound'],
            'thick' => ['not thin', 'dense', 'stupid'],
            'thin' => ['not thick', 'slender', 'weak'],
            'heavy' => ['weighty', 'serious', 'intense'],
            'light' => ['not heavy', 'illumination', 'pale'],
            'hard' => ['solid', 'difficult', 'severe'],
            'soft' => ['not hard', 'gentle', 'quiet'],
            'smooth' => ['even', 'suave', 'to make level'],
            'rough' => ['uneven', 'approximate', 'harsh'],
            'sharp' => ['pointed', 'intelligent', 'sudden'],
            'dull' => ['not sharp', 'boring', 'not bright'],
            'bright' => ['luminous', 'intelligent', 'cheerful'],
            'dark' => ['without light', 'evil', 'secret'],
            'loud' => ['noisy', 'flashy', 'strong'],
            'quiet' => ['silent', 'peaceful', 'subdued'],
            'strong' => ['powerful', 'intense', 'durable'],
            'weak' => ['not strong', 'diluted', 'unconvincing'],
            'rich' => ['wealthy', 'full-flavored', 'abundant'],
            'poor' => ['not rich', 'of low quality', 'unfortunate'],
            'full' => ['complete', 'satisfied', 'maximum'],
            'empty' => ['vacant', 'meaningless', 'to drain'],
            'new' => ['recent', 'fresh', 'modern'],
            'old' => ['aged', 'former', 'traditional'],
            'young' => ['youthful', 'recent', 'inexperienced'],
            'fresh' => ['new', 'cool', 'impudent'],
            'clean' => ['pure', 'tidy', 'complete'],
            'dirty' => ['unclean', 'unfair', 'indecent'],
            'clear' => ['transparent', 'obvious', 'to remove'],
            'dark' => ['without light', 'secret', 'evil'],
            'free' => ['at no cost', 'liberated', 'available'],
            'busy' => ['occupied', 'crowded', 'active'],
            'ready' => ['prepared', 'willing', 'immediate'],
            'easy' => ['simple', 'comfortable', 'promiscuous'],
            'hard' => ['difficult', 'solid', 'harsh'],
            'simple' => ['easy', 'plain', 'basic'],
            'complex' => ['complicated', 'building group', 'psychological issue'],
            'real' => ['actual', 'genuine', 'very'],
            'true' => ['correct', 'loyal', 'genuine'],
            'false' => ['incorrect', 'fake', 'disloyal'],
            'right' => ['correct', 'direction', 'entitlement'],
            'wrong' => ['incorrect', 'immoral', 'to treat unjustly'],
            'good' => ['positive', 'well-behaved', 'benefit'],
            'bad' => ['negative', 'evil', 'rotten'],
            'best' => ['highest quality', 'to defeat', 'most suitable'],
            'better' => ['superior', 'improved', 'more'],
            'worse' => ['inferior', 'more severe', 'more badly'],
            'same' => ['identical', 'unchanging', 'aforementioned'],
            'different' => ['unlike', 'various', 'separate'],
            'other' => ['alternative', 'additional', 'opposite'],
            'next' => ['following', 'adjacent', 'nearest'],
            'last' => ['final', 'previous', 'to endure'],
            'first' => ['initial', 'primary', 'before others'],
            'second' => ['after first', 'time unit', 'to support'],
            'third' => ['after second', 'one of three parts', 'questioning'],
            'final' => ['last', 'ultimate', 'decisive game'],
            'early' => ['before expected time', 'in the beginning', 'premature'],
            'late' => ['after expected time', 'deceased', 'recent'],
            'soon' => ['in a short time', 'quickly', 'early'],
            'now' => ['at this time', 'given that', 'current'],
            'then' => ['at that time', 'next', 'in that case'],
            'here' => ['in this place', 'at this point', 'present'],
            'there' => ['in that place', 'at that point', 'existing'],
            'where' => ['at what place', 'in what situation', 'whereas'],
            'when' => ['at what time', 'at the time that', 'although'],
            'how' => ['in what way', 'to what degree', 'the way that'],
            'what' => ['which thing', 'that which', 'whatever'],
            'who' => ['what person', 'the person that', 'whoever'],
            'why' => ['for what reason', 'the reason for which', 'expression of impatience']
        ];
        
        // Extract words from translations and check for ambiguous terms
        $wordCounts = [];
        
        foreach ($translations as $translation) {
            // Remove HTML tags and extract words
            $cleanText = strip_tags($translation);
            $cleanText = preg_replace('/[^\w\s]/', ' ', $cleanText);
            $words = array_filter(explode(' ', strtolower($cleanText)), function($word) {
                return strlen(trim($word)) >= $this->minLength;
            });
            
            foreach ($words as $word) {
                $word = trim($word);
                if (!empty($word)) {
                    $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
                }
            }
        }
        
        // Find ambiguous terms that appear in translations
        foreach ($ambiguousPatterns as $term => $definitions) {
            if (isset($wordCounts[$term]) && $wordCounts[$term] > 0) {
                $this->ambiguousTerms[$term] = [
                    'definitions' => $definitions,
                    'frequency' => $wordCounts[$term],
                    'context' => $this->findContextForTerm($term, $translations)
                ];
            }
        }
        
        // Also add frequently used words that might need clarification
        arsort($wordCounts);
        $commonWords = array_slice($wordCounts, 0, 50, true);
        
        foreach ($commonWords as $word => $count) {
            if (!isset($this->ambiguousTerms[$word]) && $count >= 3) {
                $contexts = $this->findContextForTerm($word, $translations);
                if (count($contexts) > 1) {
                    $this->ambiguousTerms[$word] = [
                        'definitions' => ['Multiple usages detected - needs clarification'],
                        'frequency' => $count,
                        'context' => $contexts
                    ];
                }
            }
        }
    }

    protected function findContextForTerm(string $term, array $translations): array
    {
        $contexts = [];
        $limit = 3;
        $found = 0;
        
        foreach ($translations as $translation) {
            if ($found >= $limit) {
                break;
            }
            
            if (stripos($translation, $term) !== false) {
                $contexts[] = mb_strlen($translation) > 80 
                    ? mb_substr($translation, 0, 77) . '...'
                    : $translation;
                $found++;
            }
        }
        
        return $contexts;
    }

    protected function buildGlossary(): void
    {
        $this->info("🔨 Building comprehensive glossary...");
        
        // Merge existing glossary with ambiguous terms
        foreach ($this->ambiguousTerms as $term => $data) {
            if (!isset($this->glossary[$term])) {
                $definitions = is_array($data['definitions']) 
                    ? implode(' | ', $data['definitions'])
                    : $data['definitions'];
                    
                $this->glossary[$term] = $definitions;
            }
        }
        
        // Sort glossary alphabetically
        ksort($this->glossary);
        
        $this->line("  ✓ Built glossary with " . count($this->glossary) . " terms");
    }

    protected function saveGlossary(): void
    {
        $this->info("💾 Saving glossary...");
        
        $timestamp = date('Y-m-d H:i:s');
        $totalTerms = count($this->glossary);
        $ambiguousCount = count($this->ambiguousTerms);
        
        switch ($this->outputFormat) {
            case 'json':
                $this->saveAsJson($timestamp, $totalTerms, $ambiguousCount);
                break;
            case 'php':
                $this->saveAsPhp($timestamp, $totalTerms, $ambiguousCount);
                break;
            case 'md':
                $this->saveAsMarkdown($timestamp, $totalTerms, $ambiguousCount);
                break;
            default:
                $this->error("Unsupported format: {$this->outputFormat}");
                return;
        }
    }

    protected function saveAsJson(string $timestamp, int $totalTerms, int $ambiguousCount): void
    {
        $data = [
            'meta' => [
                'generated_at' => $timestamp,
                'source_locale' => $this->sourceLocale,
                'total_terms' => $totalTerms,
                'ambiguous_terms_found' => $ambiguousCount,
                'generator' => 'Laravel AI Translator - Build Glossary Command'
            ],
            'glossary' => $this->glossary,
            'ambiguous_terms' => $this->ambiguousTerms
        ];
        
        $outputFile = "{$this->outputDirectory}/glossary.json";
        File::put($outputFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("  ✓ JSON glossary saved to: {$outputFile}");
    }

    protected function saveAsPhp(string $timestamp, int $totalTerms, int $ambiguousCount): void
    {
        $content = "<?php\n\n";
        $content .= "// Generated at: {$timestamp}\n";
        $content .= "// Source locale: {$this->sourceLocale}\n";
        $content .= "// Total terms: {$totalTerms}\n";
        $content .= "// Ambiguous terms found: {$ambiguousCount}\n";
        $content .= "// Generator: Laravel AI Translator - Build Glossary Command\n\n";
        $content .= "return [\n";
        $content .= "    'meta' => [\n";
        $content .= "        'generated_at' => '{$timestamp}',\n";
        $content .= "        'source_locale' => '{$this->sourceLocale}',\n";
        $content .= "        'total_terms' => {$totalTerms},\n";
        $content .= "        'ambiguous_terms_found' => {$ambiguousCount},\n";
        $content .= "        'generator' => 'Laravel AI Translator - Build Glossary Command'\n";
        $content .= "    ],\n\n";
        $content .= "    'glossary' => [\n";
        
        foreach ($this->glossary as $term => $definition) {
            $escapedTerm = addslashes($term);
            $escapedDefinition = addslashes($definition);
            $content .= "        '{$escapedTerm}' => '{$escapedDefinition}',\n";
        }
        
        $content .= "    ],\n\n";
        $content .= "    'ambiguous_terms' => [\n";
        
        foreach ($this->ambiguousTerms as $term => $data) {
            $content .= "        '{$term}' => [\n";
            $content .= "            'definitions' => " . var_export($data['definitions'], true) . ",\n";
            $content .= "            'frequency' => {$data['frequency']},\n";
            $content .= "            'context' => " . var_export($data['context'], true) . ",\n";
            $content .= "        ],\n";
        }
        
        $content .= "    ],\n";
        $content .= "];\n";
        
        $outputFile = "{$this->outputDirectory}/glossary.php";
        File::put($outputFile, $content);
        $this->line("  ✓ PHP glossary saved to: {$outputFile}");
    }

    protected function saveAsMarkdown(string $timestamp, int $totalTerms, int $ambiguousCount): void
    {
        $content = "# Translation Glossary\n\n";
        $content .= "**Generated:** {$timestamp}  \n";
        $content .= "**Source Locale:** {$this->sourceLocale}  \n";
        $content .= "**Total Terms:** {$totalTerms}  \n";
        $content .= "**Ambiguous Terms Found:** {$ambiguousCount}  \n";
        $content .= "**Generator:** Laravel AI Translator - Build Glossary Command  \n\n";
        
        $content .= "## Glossary Terms\n\n";
        $content .= "| Term | Definition |\n";
        $content .= "|------|------------|\n";
        
        foreach ($this->glossary as $term => $definition) {
            $content .= "| `{$term}` | {$definition} |\n";
        }
        
        if (!empty($this->ambiguousTerms)) {
            $content .= "\n## Potentially Ambiguous Terms\n\n";
            $content .= "These terms have multiple meanings or appear in various contexts. Consider providing specific translation guidelines.\n\n";
            
            foreach ($this->ambiguousTerms as $term => $data) {
                $content .= "### `{$term}`\n\n";
                $content .= "**Frequency:** {$data['frequency']} occurrences\n\n";
                
                if (is_array($data['definitions'])) {
                    $content .= "**Possible meanings:**\n";
                    foreach ($data['definitions'] as $definition) {
                        $content .= "- {$definition}\n";
                    }
                } else {
                    $content .= "**Note:** {$data['definitions']}\n";
                }
                
                if (!empty($data['context'])) {
                    $content .= "\n**Context examples:**\n";
                    foreach ($data['context'] as $context) {
                        $content .= "- \"{$context}\"\n";
                    }
                }
                
                $content .= "\n---\n\n";
            }
        }
        
        $outputFile = "{$this->outputDirectory}/glossary.md";
        File::put($outputFile, $content);
        $this->line("  ✓ Markdown glossary saved to: {$outputFile}");
    }

    protected function displaySuccess(): void
    {
        $this->newLine();
        $this->line($this->colors['green'] . '✓ Glossary building completed successfully!' . $this->colors['reset']);
        $this->line($this->colors['cyan'] . "Generated glossary saved to: {$this->outputDirectory}/" . $this->colors['reset']);
        
        if (!empty($this->ambiguousTerms)) {
            $this->newLine();
            $this->line($this->colors['yellow'] . "🔍 Found " . count($this->ambiguousTerms) . " potentially ambiguous terms." . $this->colors['reset']);
            $this->line($this->colors['yellow'] . "Review these terms and provide specific translation guidelines for better AI translation quality." . $this->colors['reset']);
        }
        
        $this->newLine();
    }
}