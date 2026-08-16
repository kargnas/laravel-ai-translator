# OpenRouter smoke test

Run this from the package root. The setup script creates a disposable Laravel host project and installs the package from the current checkout.

```bash
export OPENROUTER_API_KEY='your-openrouter-api-key'
./scripts/test-setup.sh laravel-ai-translator-openrouter-smoke
cd laravel-ai-translator-openrouter-smoke

perl -0pi -e "s/'provider' => 'anthropic'/'provider' => 'openrouter'/; s/'model' => 'claude-3-5-sonnet-latest'/'model' => 'anthropic\/claude-sonnet-4.5'/; s/env\('ANTHROPIC_API_KEY'\)/env('OPENROUTER_API_KEY')/" config/ai-translator.php

php artisan ai-translator:test en ko --text='Hello from OpenRouter'
```

Do not run this against production language files.
