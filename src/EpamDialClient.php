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

  /**
   * Generate images using the AI service.
   *
   * @param array $payload
   *   The image generation payload containing prompt, size, etc.
   *
   * @return array
   *   The API response containing generated image data.
   *
   * @throws \Exception
   *   If the image generation fails.
   */
  public function generateImage(array $payload): array {
    $client = new Client(['http_errors' => false]);

    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    // Try different endpoint formats for image generation
    $endpoint_configs = [
      // Standard OpenAI format
      [
        'endpoint' => '/images/generations',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
      // Standard with Api-Key header
      [
        'endpoint' => '/images/generations',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
    ];

    foreach ($endpoint_configs as $i => $config) {
      try {
        $response = $client->post($this->apiUrl . $config['endpoint'], [
          'headers' => $config['headers'],
          'json' => $config['payload'],
          'timeout' => 120, // Longer timeout for image generation
        ]);

        $status_code = $response->getStatusCode();

        if ($status_code === 200) {
          $data = json_decode($response->getBody()->getContents(), true);

          if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
          }
        }

        // Log the attempt
        if ($status_code !== 200) {
          $error_body = $response->getBody()->getContents();
          \Drupal::logger('ai_provider_epamdial')->info('Image generation config @num failed: HTTP @status - @error', [
            '@num' => $i + 1,
            '@status' => $status_code,
            '@error' => substr($error_body, 0, 300),
          ]);
        }

      } catch (RequestException $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Image generation config @num exception: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    // If all configurations fail
    $error_message = 'All API endpoint configurations failed for image generation';
    \Drupal::logger('ai_provider_epamdial')->error($error_message);
    throw new \Exception($error_message);
  }

  /**
   * Transcribe audio using the AI service.
   *
   * @param string $model_id
   *   The model to use for transcription.
   * @param mixed $audio
   *   The audio data (file content, AudioFile object, or file path).
   * @param string|null $language
   *   Optional language hint for transcription.
   *
   * @return array
   *   The API response containing transcription data.
   *
   * @throws \Exception
   *   If the transcription fails.
   */
  public function transcribeAudio(string $model_id, $audio, ?string $language = null): array {
    $client = new Client(['http_errors' => false]);

    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    // Prepare multipart form data for audio upload
    $multipart = [
      [
        'name' => 'model',
        'contents' => $model_id,
      ],
    ];

    // Handle different audio input types
    if ($audio instanceof \Drupal\ai\OperationType\GenericType\AudioFile) {
      $multipart[] = [
        'name' => 'file',
        'contents' => $audio->getBinary(),
        'filename' => $audio->getFileName() ?: 'audio.wav',
      ];
    } elseif (is_string($audio) && file_exists($audio)) {
      // File path
      $multipart[] = [
        'name' => 'file',
        'contents' => fopen($audio, 'r'),
        'filename' => basename($audio),
      ];
    } else {
      // Raw audio data
      $multipart[] = [
        'name' => 'file',
        'contents' => $audio,
        'filename' => 'audio.wav',
      ];
    }

    if ($language) {
      $multipart[] = [
        'name' => 'language',
        'contents' => $language,
      ];
    }

    // Try different endpoint formats
    $endpoint_configs = [
      [
        'endpoint' => '/audio/transcriptions',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/json',
        ],
      ],
      [
        'endpoint' => '/audio/transcriptions',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
        ],
      ],
    ];

    foreach ($endpoint_configs as $i => $config) {
      try {
        $response = $client->post($this->apiUrl . $config['endpoint'], [
          'headers' => $config['headers'],
          'multipart' => $multipart,
          'timeout' => 120,
        ]);

        if ($response->getStatusCode() === 200) {
          $data = json_decode($response->getBody()->getContents(), true);
          if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
          }
        }

      } catch (RequestException $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Audio transcription config @num exception: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    throw new \Exception('All API endpoint configurations failed for audio transcription');
  }

  /**
   * Generate speech using the AI service.
   *
   * @param string $model_id
   *   The model to use for speech generation.
   * @param string $text
   *   The text to convert to speech.
   * @param string $voice
   *   The voice to use (e.g., 'alloy', 'echo', 'fable').
   * @param string $format
   *   The audio format (e.g., 'mp3', 'opus', 'aac').
   *
   * @return string
   *   The audio data as binary string.
   *
   * @throws \Exception
   *   If the speech generation fails.
   */
  public function generateSpeech(string $model_id, string $text, string $voice = 'alloy', string $format = 'mp3'): string {
    $client = new Client(['http_errors' => false]);

    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    $payload = [
      'model' => $model_id,
      'input' => $text,
      'voice' => $voice,
      'response_format' => $format,
    ];

    // Try different endpoint formats
    $endpoint_configs = [
      [
        'endpoint' => '/audio/speech',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/octet-stream',
          'Content-Type' => 'application/json',
        ],
      ],
      [
        'endpoint' => '/audio/speech',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/octet-stream',
          'Content-Type' => 'application/json',
        ],
      ],
    ];

    foreach ($endpoint_configs as $i => $config) {
      try {
        $response = $client->post($this->apiUrl . $config['endpoint'], [
          'headers' => $config['headers'],
          'json' => $payload,
          'timeout' => 120,
        ]);

        if ($response->getStatusCode() === 200) {
          return $response->getBody()->getContents();
        }

      } catch (RequestException $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Speech generation config @num exception: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    throw new \Exception('All API endpoint configurations failed for speech generation');
  }

  /**
   * Moderate content using the AI service.
   *
   * @param string $text
   *   The text content to moderate.
   * @param string $model_id
   *   The model to use for moderation.
   *
   * @return array
   *   The API response containing moderation results.
   *
   * @throws \Exception
   *   If the moderation fails.
   */
  public function moderateContent(string $text, string $model_id): array {
    $client = new Client(['http_errors' => false]);

    $api_key_value = str_replace('Bearer ', '', $this->apiKey);

    $payload = [
      'input' => $text,
    ];

    // For specific moderation models, include model in payload
    if (strpos($model_id, 'moderation') !== false) {
      $payload['model'] = $model_id;
    }

    // Try different endpoint formats for content moderation
    $endpoint_configs = [
      // OpenAI moderation endpoint
      [
        'endpoint' => '/moderations',
        'headers' => [
          'Authorization' => $this->apiKey,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
      // OpenAI moderation with Api-Key
      [
        'endpoint' => '/moderations',
        'headers' => [
          'Api-Key' => $api_key_value,
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'payload' => $payload
      ],
    ];

    foreach ($endpoint_configs as $i => $config) {
      try {
        $response = $client->post($this->apiUrl . $config['endpoint'], [
          'headers' => $config['headers'],
          'json' => $config['payload'],
          'timeout' => 30,
        ]);

        $status_code = $response->getStatusCode();

        if ($status_code === 200) {
          $data = json_decode($response->getBody()->getContents(), true);

          if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
          }
        }

        // Log the attempt
        if ($status_code !== 200) {
          $error_body = $response->getBody()->getContents();
          \Drupal::logger('ai_provider_epamdial')->info('Moderation config @num failed: HTTP @status - @error', [
            '@num' => $i + 1,
            '@status' => $status_code,
            '@error' => substr($error_body, 0, 300),
          ]);
        }

      } catch (RequestException $e) {
        \Drupal::logger('ai_provider_epamdial')->info('Moderation config @num exception: @error', [
          '@num' => $i + 1,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    // If dedicated moderation endpoints fail, throw exception to trigger fallback
    throw new \Exception('Dedicated moderation endpoint not available, using chat fallback');
  }
}