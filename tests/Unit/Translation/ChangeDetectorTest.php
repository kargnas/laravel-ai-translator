<?php

namespace Kargnas\LaravelAiTranslator\Tests\Unit\Translation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Kargnas\LaravelAiTranslator\Tests\TestCase;
use Kargnas\LaravelAiTranslator\Translation\ChangeDetector;

class ChangeDetectorTest extends TestCase
{
    private ChangeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai-translator.state.disk', 'local');
        Storage::fake('local');
        $this->detector = new ChangeDetector;
    }

    public function test_first_run_has_no_changed_keys_without_saved_state(): void
    {
        $strings = [
            'auth.failed' => 'Failed login.',
            'auth.throttle' => ['text' => 'Too many attempts.', 'context' => 'Authentication'],
        ];

        $changed = $this->detector->changedAgainstState($strings, 'en', 'ko');

        $this->assertSame([], $changed);
    }

    public function test_second_run_with_same_content_reports_nothing_changed(): void
    {
        $strings = [
            'auth.failed' => 'Failed login.',
            'auth.throttle' => 'Too many attempts.',
        ];

        $this->detector->saveState(array_keys($strings), $strings, 'en', 'ko');

        $this->assertSame([], $this->detector->changedAgainstState($strings, 'en', 'ko'));
    }

    public function test_content_edit_reports_only_edited_key(): void
    {
        $original = [
            'auth.failed' => 'Failed login.',
            'auth.throttle' => 'Too many attempts.',
        ];
        $edited = [
            'auth.failed' => 'Login failed.',
            'auth.throttle' => 'Too many attempts.',
        ];

        $this->detector->saveState(array_keys($original), $original, 'en', 'ko');

        $this->assertSame(
            ['auth.failed' => 'Login failed.'],
            $this->detector->changedAgainstState($edited, 'en', 'ko')
        );
    }

    public function test_changed_checksum_is_returned_even_when_target_translation_exists(): void
    {
        $original = ['auth.failed' => 'Failed login.'];
        $edited = ['auth.failed' => 'Login failed.'];

        $this->detector->saveState(array_keys($original), $original, 'en', 'ko');

        $this->assertSame(
            $edited,
            $this->detector->changedAgainstState($edited, 'en', 'ko')
        );
    }

    public function test_key_absent_from_state_is_not_returned(): void
    {
        $strings = ['auth.failed' => 'Failed login.'];

        $this->assertSame([], $this->detector->changedAgainstState($strings, 'en', 'ko'));
    }

    public function test_whitespace_only_change_is_not_reported(): void
    {
        $original = ['auth.failed' => "Failed\nlogin."];
        $edited = ['auth.failed' => '  Failed login.  '];

        $this->detector->saveState(array_keys($original), $original, 'en', 'ko');

        $this->assertSame([], $this->detector->changedAgainstState($edited, 'en', 'ko'));
    }

    public function test_seeding_existing_translation_makes_future_source_edit_detectable(): void
    {
        $source = ['auth.failed' => 'Failed login.'];

        $this->detector->saveState(['auth.failed'], $source, 'en', 'ko');

        $this->assertSame(
            ['auth.failed' => 'Login failed.'],
            $this->detector->changedAgainstState(['auth.failed' => 'Login failed.'], 'en', 'ko')
        );
    }

    public function test_save_state_merges_without_dropping_unrelated_keys(): void
    {
        $initial = [
            'auth.failed' => 'Failed login.',
            'auth.throttle' => 'Too many attempts.',
        ];
        $updated = ['auth.failed' => 'Login failed.'];

        $this->detector->saveState(array_keys($initial), $initial, 'en', 'ko');
        $this->detector->saveState(array_keys($updated), $updated, 'en', 'ko');

        $state = json_decode(
            Storage::disk('local')->get('ai-translator/state/en/ko.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertCount(2, $state);
        $this->assertSame(
            $this->detector->checksum('auth.throttle', 'Too many attempts.'),
            $state['auth.throttle']
        );
        $this->assertSame(
            $this->detector->checksum('auth.failed', 'Login failed.'),
            $state['auth.failed']
        );
    }
}
