#!/bin/bash

# Laravel AI Translator Test Setup Script
# This script creates a fresh Laravel project and configures it to test the AI Translator package
# Usage: ./scripts/test-setup.sh [test-directory-name]

set -e  # Exit on any error

# Configuration
TEST_DIR="${1:-laravel-ai-translator-test}"
PACKAGE_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
print_step() {
    printf "${BLUE}==>${NC} ${1}\n"
}

print_success() {
    printf "${GREEN}✓${NC} ${1}\n"
}

print_warning() {
    printf "${YELLOW}⚠${NC} ${1}\n"
}

print_error() {
    printf "${RED}✗${NC} ${1}\n"
}

check_command() {
    if ! command -v "$1" &> /dev/null; then
        print_error "$1 is required but not installed."
        exit 1
    fi
}

# Check prerequisites
print_step "Checking prerequisites..."
check_command "composer"
check_command "php"

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION_ID;")
if [ "$PHP_VERSION" -lt 80100 ]; then
    print_error "PHP 8.1 or higher is required. Current version: $(php -v | head -n1)"
    exit 1
fi
print_success "PHP version check passed"

# Step 1: Clean up existing directory and create new one
print_step "Cleaning up existing test directory: $TEST_DIR"
if [ -d "$TEST_DIR" ]; then
    print_warning "Directory $TEST_DIR already exists. Removing it..."
    rm -rf "$TEST_DIR"
    print_success "Existing directory removed"
fi

print_step "Creating test directory: $TEST_DIR"
mkdir "$TEST_DIR"
cd "$TEST_DIR"
print_success "Test directory created"

# Step 2: Create blank Laravel project
print_step "Creating blank Laravel project..."
composer create-project laravel/laravel . --no-interaction --prefer-dist
print_success "Laravel project created"

# Step 3: Configure local package repository
print_step "Configuring local package repository..."
composer config repositories.ai-translator "{\"type\": \"path\", \"url\": \"$PACKAGE_PATH\"}"
print_success "Package repository configured"

# Step 4: Install the AI Translator package
print_step "Installing laravel-ai-translator package..."
composer require kargnas/laravel-ai-translator:@dev --no-interaction
print_success "AI Translator package installed"

# Step 5: Publish configuration
print_step "Publishing AI Translator configuration..."
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider" --no-interaction
print_success "Configuration published"

# Install Debug tool
print_step "Installing debug tool..."
composer require spatie/laravel-ray --dev
php artisan ray:publish-config
print_success "Debug tool installed"

# Step 6: Create sample language files
print_step "Creating sample language files for testing..."

# Create English language file
mkdir -p lang/en
cat > lang/en/test.php << 'EOF'
<?php

return [
    'welcome' => 'Welcome to our application',
    'hello' => 'Hello :name',
    'products' => 'You have :count product|You have :count products',
    'description' => 'This is a <strong>test</strong> description with HTML',
    'complex' => 'User :user_name has :count item in their cart|User :user_name has :count items in their cart',
];
EOF

# Create sample JSON language file
cat > lang/en.json << 'EOF'
{
    "Login": "Login",
    "Register": "Register",
    "Forgot Password?": "Forgot Password?",
    "Remember me": "Remember me",
    "Email Address": "Email Address",
    "Password": "Password"
}
EOF

print_success "Sample language files created"

# Step 7: Configure AI provider in .env
print_step "Configuring AI provider in .env..."
cat >> .env << EOF

# AI Translator Configuration
ANTHROPIC_API_KEY=
EOF
print_success "AI provider configured in .env"

# Step 8: Create test scripts
print_step "Creating test scripts..."

# Test translation script
cat > test-translate.sh << 'EOF'
#!/bin/bash

# Test AI Translator Commands

set -e

echo "🧪 Testing Laravel AI Translator..."
echo ""

# Test basic translation
echo "📋 Test 1: Test translation with sample strings"
php artisan ai-translator:test
echo ""

# Test translate specific file
echo "📋 Test 2: Translate single file"
php artisan ai-translator:translate-file lang/en/test.php --locale=ko
echo ""

# Test JSON translation
echo "📋 Test 3: Translate JSON files"
php artisan ai-translator:translate-json --locale=ko
echo ""

# Test strings translation
echo "📋 Test 4: Translate PHP array files"
php artisan ai-translator:translate-strings --locale=ko
echo ""

# Test parallel translation (multiple locales)
echo "📋 Test 5: Translate to multiple locales in parallel"
php artisan ai-translator:translate-parallel
echo ""

echo "✅ All translation tests completed!"
echo ""
echo "Check the following directories for results:"
echo "  - lang/ko/ (Korean translations)"
echo "  - lang/ja/ (Japanese translations if configured)"
echo "  - lang/fr/ (French translations if configured)"
echo "  - lang/es/ (Spanish translations if configured)"
EOF

chmod +x test-translate.sh
print_success "Test scripts created"

# Step 9: Create cleanup script
cat > cleanup.sh << 'EOF'
#!/bin/bash

# Cleanup script for test translations

echo "🧹 Cleaning up test translations..."

# Remove translated directories
rm -rf lang/ko lang/ja lang/fr lang/es

echo "✅ Cleanup completed!"
EOF

chmod +x cleanup.sh
print_success "Cleanup script created"

# Step 10: Create README for the test project
cat > README.md << 'EOF'
# Laravel AI Translator Test Project

This is a test project for the Laravel AI Translator package.

## Configuration

1. Edit `.env` file and add your AI provider API key:
   ```
   ANTHROPIC_API_KEY=your-actual-api-key-here
   ```

2. Choose your AI provider (openai, anthropic, or gemini):
   ```
   AI_TRANSLATOR_PROVIDER=openai
   ```

## Available Commands

### Test Translation
```bash
php artisan ai-translator:test
```

### Translate Single File
```bash
php artisan ai-translator:translate-file lang/en/test.php --locale=ko
```

### Translate JSON Files
```bash
php artisan ai-translator:translate-json --locale=ko
```

### Translate PHP Array Files
```bash
php artisan ai-translator:translate-strings --locale=ko
```

### Translate to Multiple Locales
```bash
php artisan ai-translator:translate-parallel
```

## Test Scripts

- `./test-translate.sh` - Run all translation tests
- `./cleanup.sh` - Remove all translated files

## Sample Files

- `lang/en/test.php` - Sample PHP array language file
- `lang/en.json` - Sample JSON language file

## Package Development

The package is loaded from the local directory via Composer path repository.
Any changes made to the package will be reflected immediately.
EOF

print_success "README created"

# Final instructions
print_step "Setup completed successfully!"
echo ""
printf "${GREEN}📁 Test project created in: ${PWD}${NC}\n"
echo ""
printf "${YELLOW}🎯 Next Steps:${NC}\n"
echo ""
printf "${GREEN}1. Configure your AI provider:${NC}\n"
printf "   Edit ${BLUE}.env${NC} and add your API key:\n"
printf "   ${BLUE}ANTHROPIC_API_KEY=your-actual-api-key-here${NC}\n"
echo ""
printf "${GREEN}2. Test the translator:${NC}\n"
printf "   ${BLUE}php artisan ai-translator:test${NC}\n"
echo ""
printf "${GREEN}3. Run all tests:${NC}\n"
printf "   ${BLUE}./test-translate.sh${NC}\n"
echo ""
printf "${YELLOW}📋 Available Commands:${NC}\n"
printf "   • ${BLUE}php artisan ai-translator:test${NC} - Test with sample strings\n"
printf "   • ${BLUE}php artisan ai-translator:translate-file${NC} - Translate single file\n"
printf "   • ${BLUE}php artisan ai-translator:translate-json${NC} - Translate JSON files\n"
printf "   • ${BLUE}php artisan ai-translator:translate-strings${NC} - Translate PHP files\n"
printf "   • ${BLUE}php artisan ai-translator:translate-parallel${NC} - Translate to multiple locales\n"
echo ""
printf "${YELLOW}🔧 Troubleshooting:${NC}\n"
printf "   • Check Laravel logs: ${BLUE}tail -f storage/logs/laravel.log${NC}\n"
printf "   • Clean translations: ${BLUE}./cleanup.sh${NC}\n"
printf "   • Verify config: ${BLUE}php artisan config:show ai-translator${NC}\n"