<?php

namespace Drupal\ai_provider_epamdial;

use Drupal\ai\Exception\AiQuotaException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Client for interacting with EPAM DIAL API.
 *
 * Supports dual authentication formats:
 * - Bearer JWT tokens: "Bearer eyJhbGciOiJS..."
 * - API Keys: "your-api-key"
 */
class EpamDialClient {
  protected $apiKey;
  protected $apiUrl;
  protected $model_id;

  public function __construct($apiKey = '', $apiUrl = '') {
    // Ensure Bearer prefix is present for JWT tokens
    if (!empty($apiKey) && strpos($apiKey, 'Bearer ') !== 0) {
      $apiKey = 'Bearer ' . $apiKey;
    }
    $this->apiKey = $apiKey;
    $this->apiUrl = rtrim($apiUrl, '/'); // Remove trailing slash
  }

  /**
   * Load available models from the AI service.
   */
  public function loadModels() {
    $client = new Client(['http_errors' => false]);

    // Try both authentication formats
    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    $auth_configs = [
      ['Authorization' => $this->apiKey],
      ['Api-Key' => $api_key_value]
    ];

    foreach ($auth_configs as $headers) {
      try {
        $headers['Accept'] = 'application/json';

        $response = $client->get($this->apiUrl . '/models', [
          'headers' => $headers,
          'timeout' => 30,
        ]);

        if ($response->getStatusCode() === 200) {
          $data = json_decode($response->getBody()->getContents(), true);
          if (json_last_error() === JSON_ERROR_NONE) {
            return $data['data'] ?? [];
          }
        }
      } catch (RequestException $e) {
        // Try next auth format
        continue;
      }
    }

    // If both formats fail, provide default models
    \Drupal::logger('ai_provider_epamdial')->warning('Could not load models from API, using defaults');
    return $this->getDefaultModels();
  }

  /**
   * Get default models when API is not available.
   */
  private function getDefaultModels(): array {
    return [
      [
        'id' => 'gpt-4o',
        'display_name' => 'GPT-4o (Latest)',
        'capabilities' => ['chat' => true, 'vision' => true, 'chat_with_image_vision' => true]
      ],
      [
        'id' => 'gpt-4-turbo',
        'display_name' => 'GPT-4 Turbo',
        'capabilities' => ['chat' => true, 'vision' => true, 'chat_with_image_vision' => true]
      ],
      [
        'id' => 'anthropic.claude-sonnet-4-5-20250929-v1:0',
        'display_name' => 'Anthropic Claude 4.5 Sonnet',
        'capabilities' => ['chat' => true, 'vision' => true, 'chat_with_image_vision' => true]
      ],
      [
        'id' => 'gemini-2.5-pro',
        'display_name' => 'Google Gemini 2.5 Pro',
        'capabilities' => ['chat' => true, 'vision' => true, 'chat_with_image_vision' => true]
      ],
      [
        'id' => 'text-embedding-ada-002',
        'display_name' => 'OpenAI Text Embedding Ada 002',
        'capabilities' => ['embeddings' => true]
      ],
      [
        'id' => 'text-embedding-3-large-1',
        'display_name' => 'OpenAI Text Embedding 3 Large',
        'capabilities' => ['embeddings' => true]
      ]
    ];
  }

  /**
   * Set the model to use for generation.
   */
  public function model($model_id) {
    $this->model_id = $model_id;
    return $this;
  }

  /**
   * Generate a response from the AI service.
   */
  public function generate($input) {
    if (!$this->model_id) {
      throw new \InvalidArgumentException('No model specified. Call model() first.');
    }

    $client = new Client(['http_errors' => false]);

    // Prepare messages payload
    $messages = [];
    if (is_array($input)) {
      // Handle array input which might contain structured content (including images)
      $messages = $input;
    } else {
      // Simple string input - convert to text content format
      $messages = [
        [
          'role' => 'user',
          'content' => (string) $input
        ]
      ];
    }

    $payload = [
      'model' => $this->model_id,
      'messages' => $messages
    ];

    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    // Try different endpoint formats
    $endpoint_configs = [
      // Standard OpenAI format
      [
        'endpoint' => '/chat/completions',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
      // Standard with Api-Key header
      [
        'endpoint' => '/chat/completions',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
      // EPAM DialAI deployment format
      [
        'endpoint' => '/deployments/' . $this->model_id . '/chat/completions',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => ['messages' => $messages] // No model field for deployment endpoints
      ]
    ];

    foreach ($endpoint_configs as $i => $config) {
      try {
        $response = $client->post($this->apiUrl . $config['endpoint'], [
          'headers' => $config['headers'],
          'json' => $config['payload'],
          'timeout' => 60,
        ]);

        $status_code = $response->getStatusCode();

        if ($status_code === 200) {
          $data = json_decode($response->getBody()->getContents(), true);

          if (json_last_error() === JSON_ERROR_NONE) {
            return $data['choices'][0]['message']['content'] ?? '';
          }
        }

        // Log the attempt
        if ($status_code !== 200) {
          $error_body = $response->getBody()->getContents();
          \Drupal::logger('ai_provider_epamdial')->info('Config @num failed: HTTP @status - @error', [
            '@num' => $i + 1,
            '@status' => $status_code,
            '@error' => substr($error_body, 0, 300),
          ]);
        }

      } catch (RequestException $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Config @num exception: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    // If all configurations fail
    $error_message = 'All API endpoint configurations failed for model: ' . $this->model_id;
    \Drupal::logger('ai_provider_epamdial')->error($error_message);
    throw new AiQuotaException($error_message);
  }

  /**
   * Test the connection to the API service.
   *
   * @return bool
   *   TRUE if connection is successful, FALSE otherwise.
   */
  public function testConnection(): bool {
    $client = new Client(['http_errors' => false]);

    // Remove Bearer prefix for Api-Key header format
    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    \Drupal::logger('ai_provider_epamdial')->info('Testing connection to: @url', [
      '@url' => $this->apiUrl,
    ]);

    // Test different endpoint and header combinations
    $test_configurations = [
      // Standard OpenAI format
      [
        'endpoint' => '/models',
        'method' => 'GET',
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => $this->apiKey
        ]
      ],
      // EPAM DialAI format with Api-Key header
      [
        'endpoint' => '/models',
        'method' => 'GET',
        'headers' => [
          'Accept' => 'application/json',
          'Api-Key' => $api_key_value
        ]
      ],
      // Test chat endpoint with Authorization
      [
        'endpoint' => '/chat/completions',
        'method' => 'POST',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => 'gpt-3.5-turbo',
          'messages' => [['role' => 'user', 'content' => 'test']],
          'max_tokens' => 1
        ]
      ],
      // Test EPAM DialAI deployment format
      [
        'endpoint' => '/deployments/gpt-4o/chat/completions',
        'method' => 'POST',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'messages' => [['role' => 'user', 'content' => 'test']],
          'max_tokens' => 1
        ]
      ]
    ];

    foreach ($test_configurations as $i => $config) {
      try {
        \Drupal::logger('ai_provider_epamdial')->info('Testing config @num: @method @endpoint', [
          '@num' => $i + 1,
          '@method' => $config['method'],
          '@endpoint' => $config['endpoint']
        ]);

        $options = [
          'headers' => $config['headers'],
          'timeout' => 30,
        ];

        if (isset($config['json'])) {
          $options['json'] = $config['json'];
        }

        if ($config['method'] === 'GET') {
          $response = $client->get($this->apiUrl . $config['endpoint'], $options);
        } else {
          $response = $client->post($this->apiUrl . $config['endpoint'], $options);
        }

        $status_code = $response->getStatusCode();
        $response_body = $response->getBody()->getContents();

        \Drupal::logger('ai_provider_epamdial')->info('Config @num response: HTTP @status - @body', [
          '@num' => $i + 1,
          '@status' => $status_code,
          '@body' => substr($response_body, 0, 300)
        ]);

        // Success conditions
        if (in_array($status_code, [200, 201])) {
          \Drupal::logger('ai_provider_epamdial')->info('Connection successful with config @num', ['@num' => $i + 1]);
          return true;
        }

        // For chat endpoints, 400 might indicate authentication works but request format is wrong
        if ($config['method'] === 'POST' && in_array($status_code, [400, 422])) {
          $error_data = json_decode($response_body, true);
          // If we get a structured error response, connection is likely working
          if (is_array($error_data) && isset($error_data['error'])) {
            \Drupal::logger('ai_provider_epamdial')->info('Connection likely working (got structured error) with config @num', ['@num' => $i + 1]);
            return true;
          }
        }

      } catch (\Exception $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Config @num failed: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage()
        ]);
        continue;
      }
    }

    \Drupal::logger('ai_provider_epamdial')->error('All connection test configurations failed');
    return false;
  }
}