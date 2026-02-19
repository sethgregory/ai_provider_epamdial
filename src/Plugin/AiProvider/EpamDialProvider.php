<?php

namespace Drupal\ai_provider_epamdial\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_epamdial\EpamDialClient;

/**
 * Defines the EPAM DIAL Provider plugin.
 *
 * This provider supports dual authentication (Bearer JWT or Api-Key) and
 * provides access to 120+ models including GPT-4, Claude 4.5, Gemini 2.5, and more.
 */
#[AiProvider(
  id: 'epamdial',
  label: new TranslatableMarkup('EPAM DIAL')
)]
class EpamDialProvider extends AiProviderClientBase implements ChatInterface {

  use StringTranslationTrait;

  /**
   * The client object for interacting with the EPAM DIAL service.
   *
   * This client is used to make requests to the AI service.
   */
  protected EpamDialClient $client;

  /**
   * The API key for authenticating with the EPAM DIAL service.
   */
  protected string $apiKey = '';


  /**
   * Stores the system message if applicable.
   *
   * This is typically used for setting context or configuration for the AI.
   *
   * @var string|null
   */
  protected $systemMessage = NULL;

  /**
   * Retrieves the list of configured models supported by this provider.
   *
   * @param string|null $operation_type
   *   The operation type, e.g., "chat".
   * @param array $capabilities
   *   Specific capabilities to filter models by.
   *
   * @return array
   *   An array of supported model configurations.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the models cannot be fetched.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    try {
      $this->loadClient();
      $supported_models = $this->client->loadModels();
      $models = [];

      if (!empty($supported_models)) {
        foreach ($supported_models as $model) {
          // Filter models based on operation type and capabilities
          if ($operation_type === 'embeddings') {
            if (!empty($model['capabilities']['embeddings'])) {
              $models[$model['id']] = $model['display_name'] ?? $model['id'];
            }
          } elseif ($operation_type === 'chat') {
            // Assume chat capability if not explicitly defined or if chat is supported
            if (empty($model['capabilities']) || !empty($model['capabilities']['chat']) || !empty($model['capabilities']['text_generation'])) {
              $models[$model['id']] = $model['display_name'] ?? $model['id'];
            }
          } elseif ($operation_type === 'chat_with_image_vision') {
            // Filter for vision-enabled models
            if (!empty($model['capabilities']['vision']) || !empty($model['capabilities']['chat_with_image_vision'])) {
              $models[$model['id']] = $model['display_name'] ?? $model['id'];
            }
          } else {
            // For other operation types or when no filter is specified
            $models[$model['id']] = $model['display_name'] ?? $model['id'];
          }
        }
      }

      return $models;
    } catch (\Exception $e) {
      $error_message = sprintf('Failed to fetch models: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Checks if the provider is usable for a given operation type.
   *
   * @param string|null $operation_type
   *   The type of operation, e.g., "chat".
   * @param array $capabilities
   *   Additional capabilities to check against.
   *
   * @return bool
   *   TRUE if the provider can be used; FALSE otherwise.
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }

    if ($operation_type) {
      $supported_types = $this->getSupportedOperationTypes();
      if (!in_array($operation_type, $supported_types)) {
        return FALSE;
      }

      // For vision operations, check if we have models that support it
      if ($operation_type === 'chat_with_image_vision') {
        try {
          $models = $this->getConfiguredModels($operation_type, $capabilities);
          return !empty($models);
        } catch (\Exception $e) {
          // If we can't get models, assume false for safety
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Returns the operation types supported by this provider.
   *
   * @return array
   *   An array of supported operation types, e.g., ['chat'].
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'chat_with_image_vision',
      'embeddings',
    ];
  }

  /**
   * Retrieves the configuration for this plugin.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_epamdial.settings');
  }


  /**
   * Configures settings for a specific model.
   *
   * @param string $model_id
   *   The ID of the model being configured.
   * @param array $generalConfig
   *   General configuration options.
   *
   * @return array
   *   The final model settings.
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * Sets the authentication method for the provider.
   *
   * @param mixed $authentication
   *   The API key or other credentials.
   */
  public function setAuthentication(mixed $authentication): void {
    $this->apiKey = $authentication;
  }

  /**
   * Executes a chat operation with the AI model.
   *
   * @param array|string|ChatInput $input
   *   The input messages or configuration for the chat.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The response from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if unsupported roles are found in the input.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->loadClient();

    // Normalize input to messages format
    $messages = [];

    if ($input instanceof ChatInput) {
      // Add system message if configured
      if ($this->systemMessage) {
        $messages[] = [
          'role' => 'system',
          'content' => $this->systemMessage,
        ];
      }

      // Convert ChatInput to messages array
      foreach ($input->getMessages() as $message) {
        $role = $message->getRole();
        // Map system role to system, others remain the same
        if ($role === 'system') {
          $role = 'system';
        } elseif ($role === 'model') {
          $role = 'assistant';
        } elseif (!in_array($role, ['user', 'assistant'])) {
          $error_message = sprintf('The role %s is not supported.', $role);
          throw new AiResponseErrorException($error_message);
        }

        // Handle text and images together
        $content = [];

        // Add text content if present
        if (!empty($message->getText())) {
          $content[] = [
            'type' => 'text',
            'text' => $message->getText(),
          ];
        }

        // Add image content if present
        $images = $message->getImages();
        if (!empty($images)) {
          foreach ($images as $image) {
            $content[] = [
              'type' => 'image_url',
              'image_url' => [
                'url' => 'data:' . $image->getMimeType() . ';base64,' . base64_encode($image->getBinary()),
              ],
            ];
          }
        }

        // Use structured content if we have images, otherwise just text
        if (count($content) > 1 || !empty($images)) {
          $messages[] = [
            'role' => $role,
            'content' => $content,
          ];
        } else {
          $messages[] = [
            'role' => $role,
            'content' => $message->getText(),
          ];
        }
      }
    } elseif (is_array($input)) {
      $messages = $input;
    } else {
      // String input
      $messages = [
        [
          'role' => 'user',
          'content' => (string) $input,
        ],
      ];
    }

    try {
      $response_text = $this->client->model($model_id)->generate($messages);

      if (empty($response_text)) {
        throw new AiResponseErrorException('Empty response from AI service');
      }

      $message = new ChatMessage('assistant', $response_text);
      $raw_response = ['content' => $response_text, 'model' => $model_id];

      return new ChatOutput($message, $raw_response, $tags);

    } catch (\Exception $e) {
      $error_message = sprintf('Chat operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }


  /**
   * Retrieves the raw client instance.
   *
   * @param string $api_key
   *   An optional API key to override the current one.
   *
   * @return \Drupal\ai_provider_epamdial\EpamDialClient
   *   The client instance.
   */
  public function getClient(string $api_key = '') {
    if ($api_key) {
      $this->setAuthentication($api_key);
    }

    $this->loadClient();
    return $this->client;
  }

  /**
   * Loads the client for EPAM DIAL interactions.
   *
   * If the client has not been initialized, this method initializes it.
   */
  protected function loadClient(): void {
    if (isset($this->client)) {
      return;
    }

    try {
      $apiKey = $this->loadApiKey();
      if (empty($apiKey)) {
        throw new AiResponseErrorException('API key is not configured');
      }

      $apiUrl = $this->getConfig()->get('api_url') ?? 'https://ai-proxy.lab.epam.com/openai';
      $this->client = new EpamDialClient($apiKey, $apiUrl);
    } catch (\Exception $e) {
      $error_message = sprintf('Failed to load EPAM DIAL client: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Retrieves the API key from the key module.
   *
   * @return string
   *   The API key value.
   *
   * @throws \Exception
   *   If the API key cannot be loaded.
   */
  protected function loadApiKey(): string {
    $key_id = $this->getConfig()->get('api_key');
    if (empty($key_id)) {
      throw new \Exception('No API key configured');
    }

    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      throw new \Exception(sprintf('API key "%s" not found', $key_id));
    }

    $api_key = $key->getKeyValue();
    if (empty($api_key)) {
      throw new \Exception(sprintf('API key "%s" is empty', $key_id));
    }

    return $api_key;
  }

  /**
   * Sets the configuration for this provider.
   *
   * @param array $configuration
   *   An array of configuration values.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    $this->systemMessage = $configuration['system_message'] ?? NULL;
  }


}