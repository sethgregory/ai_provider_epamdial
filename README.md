# EPAM DIAL Provider

This module provides integration between Drupal's AI module and EPAM DIAL services using dual authentication (Bearer JWT tokens or API keys).

## Features

- **Smart Endpoint Selection**: Predefined endpoint options with custom URL support
- **Dual Authentication**: Automatic detection and support for Bearer JWT tokens and API key formats
- **Chat Operations**: Conversational AI with access to 120+ models including vision-capable models
- **Advanced Model Discovery**: Automatic detection and categorization of chat, embedding, and vision models
- **Comprehensive Connection Testing**: Multi-endpoint, multi-auth testing with fallback mechanisms
- **Vision Support**: Chat with image capabilities using vision-enabled models
- **Embeddings**: Generate text embeddings for various NLP tasks
- **Enhanced Configuration UI**: Live model counting, organized model display, and connection validation
- **Robust Error Handling**: Detailed logging, connection diagnostics, and troubleshooting support

## Installation

1. Ensure the AI and Key modules are enabled
2. Enable this module: `drush en ai_provider_epamdial`
3. Configure the module at `/admin/config/ai/providers/epamdial`

## Configuration

1. **Create an API Key**:
   - Go to `/admin/config/system/keys`
   - Create a new key for your EPAM DIAL API token or Bearer JWT
   - The key can be stored in either format (the module auto-detects)

2. **Configure the Provider**:
   - Go to `/admin/config/ai/providers/epamdial`
   - Select your API key from the dropdown
   - Choose your API endpoint:
     - **EPAM DIAL AI Proxy**: `https://ai-proxy.lab.epam.com/openai` (may require EPAM VPN)
     - **Elitea Environment**: `https://next.elitea.ai/llm/v1` (public access, no VPN required)
     - **Custom URL**: Enter a custom endpoint URL for other EPAM DIAL deployments

3. **Automatic Testing**: The configuration form automatically:
   - Tests your connection using multiple authentication methods
   - Displays live model counts in the form title
   - Shows organized model categories (Chat, Embedding, Other)
   - Validates endpoint accessibility and provides detailed error messages

## Supported Operations

- **Chat**: Text generation and conversational AI (120+ models)
- **Chat with Image Vision**: Visual analysis and image understanding using vision-capable models
- **Embeddings**: Text embedding generation for NLP tasks

### Vision-Enabled Models
The module automatically detects and filters models with vision capabilities, including:
- GPT-4o, GPT-4 Turbo (with vision)
- Claude 4.5 Sonnet, Claude 4.6 Opus
- Gemini 2.5 Pro
- And other vision-capable models in your EPAM DIAL deployment

## Authentication

This module supports **dual authentication formats**:

### JWT Bearer Tokens
- Format: `Bearer eyJhbGciOiJSUz...`
- Uses `Authorization: Bearer TOKEN` header
- Automatically detected when key starts with "Bearer "

### API Keys
- Format: `your-api-key-string`
- Uses `Api-Key: KEY` header
- Default format when no "Bearer " prefix detected

The module automatically detects which format to use based on your stored key.

## API Endpoints & Smart Fallback

The module employs intelligent endpoint detection with automatic fallback:

### Endpoint Formats Supported
- **Standard OpenAI format**: `/models`, `/chat/completions`
- **EPAM DIAL deployment format**: `/deployments/{model}/chat/completions`
- **Custom deployment patterns**: Configurable for various EPAM DIAL setups

### Smart Authentication Testing
The module automatically tests multiple authentication configurations:
1. **Bearer Authorization**: `Authorization: Bearer YOUR_TOKEN`
2. **API Key Header**: `Api-Key: YOUR_KEY`
3. **Multiple endpoints**: Tests both standard and deployment-specific patterns
4. **Graceful fallback**: Automatically selects the working configuration

### Connection Validation
During configuration, the module performs comprehensive testing:
- Tests `/models` endpoint for model discovery
- Tests `/chat/completions` for chat functionality
- Tests deployment-specific endpoints when available
- Provides detailed logging for troubleshooting failed connections

## Expected Performance

A successful connection should discover:
- **120+ models** including:
  - GPT-4o, GPT-5 mini
  - Claude 4.5 Sonnet, Claude 4.6 Opus
  - Gemini 2.5 Pro
  - o3-mini, and many more

## Troubleshooting

### Connection Issues
The module includes comprehensive troubleshooting with enhanced diagnostics:

1. **Smart Configuration Testing**: Form automatically tests multiple endpoint and authentication combinations
2. **Real-time Model Discovery**: Live model counting and categorization during configuration
3. **Detailed Logging**: Check `/admin/reports/dblog` for step-by-step connection attempts with configuration details
4. **Visual Feedback**: Configuration UI shows connection status, model counts, and organized model listings

### Manual Testing Commands
```bash
# Test with API key format
curl -H "Api-Key: YOUR_KEY" YOUR_ENDPOINT/models

# Test with Bearer token format
curl -H "Authorization: Bearer YOUR_TOKEN" YOUR_ENDPOINT/models

# Test deployment-specific endpoint
curl -H "Api-Key: YOUR_KEY" YOUR_ENDPOINT/deployments/gpt-4o/chat/completions \
  -d '{"messages":[{"role":"user","content":"test"}],"max_tokens":1}'
```

### Enhanced Diagnostics
The module now provides:
- **Endpoint-specific errors**: Different error messages for models vs chat endpoints
- **Authentication method feedback**: Indicates which auth method succeeded/failed
- **Model categorization**: Separates chat, embedding, and vision models in diagnostics
- **Fallback notifications**: Logs when default models are used due to API unavailability
- **Structured error responses**: Better parsing of API error messages for user feedback

### Common Solutions
- **Endpoint Selection**: Try different predefined endpoints (EPAM Lab vs Elitea)
- **VPN Requirements**: EPAM Lab endpoint may require EPAM VPN connection
- **API Key Format**: Module auto-detects but verify your key format in the Key module
- **Model Availability**: Check the "Available Models" section in the configuration form
- **Firewall/Network**: Ensure outbound HTTPS access to selected endpoint

## Development

This module demonstrates advanced AI provider integration:
- **EpamDialClient**: Multi-endpoint API client with intelligent fallback and dual authentication
- **EpamDialProvider**: Full AI provider implementation supporting chat, vision, and embeddings
- **EpamDialConfigForm**: Enhanced configuration UI with live testing and model categorization
- **Smart Model Filtering**: Automatic capability detection and operation-type filtering
- **Robust Error Handling**: Multi-layer connection testing with detailed diagnostics
- **Vision Support**: Image processing and multimodal chat capabilities

### Key Technical Features
- **Automatic Auth Detection**: Seamlessly handles Bearer tokens and API keys
- **Endpoint Fallback**: Tests multiple API patterns and selects working configuration
- **Model Capability Mapping**: Intelligently categorizes models by supported operations
- **Real-time Validation**: Live connection testing during configuration
- **Structured Logging**: Comprehensive diagnostic information for troubleshooting

## Dependencies

- **drupal/ai**: Core AI module integration
- **drupal/key**: Secure API key storage

## Advanced Configuration

### Predefined Endpoint Options
The configuration form now provides easy selection between:

1. **EPAM DIAL AI Proxy**
   - URL: `https://ai-proxy.lab.epam.com/openai`
   - Requirements: May require EPAM VPN connection
   - Best for: EPAM internal users with VPN access

2. **Elitea Environment**
   - URL: `https://next.elitea.ai/llm/v1`
   - Requirements: Public access, no VPN required
   - Best for: External users or public access scenarios

3. **Custom URL**
   - Configurable endpoint for other EPAM DIAL deployments
   - Supports any OpenAI-compatible API endpoint
   - Automatically tests custom endpoints during configuration

### Model Management
- **Automatic Discovery**: Discovers all available models from your endpoint
- **Smart Categorization**: Organizes models into Chat, Embedding, and Vision categories
- **Live Counting**: Shows model counts in configuration form headers
- **Default Selection**: Automatically sets appropriate default models for operations
- **Capability Filtering**: Only shows relevant models for each operation type (chat, embeddings, vision)

## License

GPL-2.0-or-later
