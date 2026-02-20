<?php

namespace Drupal\ai_provider_epamdial\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageClassification\ImageClassificationInterface;
use Drupal\ai\OperationType\ImageClassification\ImageClassificationInput;
use Drupal\ai\OperationType\ImageClassification\ImageClassificationOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInterface;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInterface;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\ai\OperationType\TranslateText\TranslateTextInterface;
use Drupal\ai\OperationType\TranslateText\TranslateTextInput;
use Drupal\ai\OperationType\TranslateText\TranslateTextOutput;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\Moderation\ModerationInterface;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_epamdial\EpamDialClient;

/**
 * Defines the EPAM DIAL Provider plugin.
 *
 * This provider supports dual authentication (Bearer JWT or Api-Key) and
 * provides access to 120+ models including GPT-4, Claude 4.5, Gemini 2.5, and more.
 *
 * Supported Operation Types:
 * - Chat: Text conversations and Q&A (117+ models)
 * - Chat with Image Vision: Visual analysis and discussion (52+ models)
 * - Embeddings: Vector embeddings for semantic search (7+ models)
 * - Text to Image: Image generation via DALL-E and similar models
 * - Image Classification: Image analysis and description
 * - Speech to Text: Audio transcription (Whisper-compatible)
 * - Text to Speech: Voice synthesis and audio generation
 * - Translate Text: Multi-language translation (118+ models)
 * - Moderation: Content safety and policy compliance (118+ models)
 *
 * Features:
 * - Automatic capability detection based on model metadata
 * - Enhanced display names that distinguish between model variants
 * - Capability override option for testing or when API metadata is incomplete
 * - Dual API endpoint support (OpenAI format + EPAM DIAL specific)
 * - Comprehensive error handling and fallback mechanisms
 */
#[AiProvider(
  id: 'epamdial',
  label: new TranslatableMarkup('EPAM DIAL')
)]
class EpamDialProvider extends AiProviderClientBase implements ChatInterface, TextToImageInterface, ImageClassificationInterface, SpeechToTextInterface, TextToSpeechInterface, TranslateTextInterface, ModerationInterface {

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
        $override_capabilities = $this->getConfig()->get('override_capabilities');

        foreach ($supported_models as $model) {
          // If capability override is enabled, include all models for any operation type
          if ($override_capabilities) {
            $models[$model['id']] = $this->generateDisplayName($model);
          } else {
            // Filter models based on operation type and capabilities
            if ($operation_type === 'embeddings') {
              if (!empty($model['capabilities']['embeddings'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'chat') {
              // Assume chat capability if not explicitly defined or if chat is supported
              if (empty($model['capabilities']) || !empty($model['capabilities']['chat']) || !empty($model['capabilities']['text_generation']) || !empty($model['capabilities']['chat_completion'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'chat_with_image_vision') {
              // Filter for vision-enabled models - include known vision models even without explicit capabilities
              if (!empty($model['capabilities']['vision']) ||
                  !empty($model['capabilities']['chat_with_image_vision']) ||
                  preg_match('/^(gpt-4(?!-turbo-preview)|gpt-4o|gpt-4-vision|claude|gemini|anthropic\.)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'text_to_image') {
              // Filter for image generation models - look for DALL-E and other image generation models
              if (!empty($model['capabilities']['image_generation']) ||
                  preg_match('/^(dall-e|stable-diffusion|midjourney|imagen|firefly)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'image_classification') {
              // Filter for image classification models - use vision-capable models
              if (!empty($model['capabilities']['vision']) ||
                  !empty($model['capabilities']['image_classification']) ||
                  preg_match('/^(gpt-4(?!-turbo-preview)|gpt-4o|gpt-4-vision|claude|gemini|anthropic\.)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'speech_to_text') {
              // Filter for speech-to-text models - look for Whisper and other audio models
              if (!empty($model['capabilities']['audio_transcription']) ||
                  !empty($model['capabilities']['speech_to_text']) ||
                  preg_match('/^(whisper|speech|audio)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'text_to_speech') {
              // Filter for text-to-speech models - look for TTS models
              if (!empty($model['capabilities']['text_to_speech']) ||
                  !empty($model['capabilities']['audio_generation']) ||
                  preg_match('/^(tts|voice|speech)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'translate_text') {
              // Filter for translation models - most LLMs can translate
              if (!empty($model['capabilities']['translation']) ||
                  !empty($model['capabilities']['chat']) ||
                  !empty($model['capabilities']['text_generation']) ||
                  !empty($model['capabilities']['chat_completion']) ||
                  preg_match('/^(gpt|claude|gemini|anthropic\.|translate)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } elseif ($operation_type === 'moderation') {
              // Filter for content moderation models - most LLMs can do moderation, some have specific moderation models
              if (!empty($model['capabilities']['moderation']) ||
                  !empty($model['capabilities']['chat']) ||
                  !empty($model['capabilities']['text_generation']) ||
                  !empty($model['capabilities']['chat_completion']) ||
                  preg_match('/^(gpt|claude|gemini|anthropic\.|moderation|text-moderation)/i', $model['id'])) {
                $models[$model['id']] = $this->generateDisplayName($model);
              }
            } else {
              // For other operation types or when no filter is specified
              $models[$model['id']] = $this->generateDisplayName($model);
            }
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
      'text_to_image',
      'image_classification',
      'speech_to_text',
      'text_to_speech',
      'translate_text',
      'moderation',
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
   * Executes a text-to-image operation with the AI model.
   *
   * @param string|\Drupal\ai\OperationType\TextToImage\TextToImageInput $input
   *   The input prompt or TextToImageInput object for image generation.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\TextToImage\TextToImageOutput
   *   The generated image(s) from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the image generation fails.
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $this->loadClient();

    // Convert input to standardized format
    if ($input instanceof TextToImageInput) {
      $prompt = $input->getPrompt();
      $width = $input->getWidth();
      $height = $input->getHeight();
      $n = $input->getNumber() ?? 1;
    } else {
      $prompt = (string) $input;
      $width = 1024; // Default width
      $height = 1024; // Default height
      $n = 1; // Default number of images
    }

    try {
      // Prepare the image generation payload
      $payload = [
        'model' => $model_id,
        'prompt' => $prompt,
        'n' => $n,
        'size' => $width . 'x' . $height,
        'response_format' => 'url', // Get URLs instead of base64 for easier handling
      ];

      // Use a custom method in the client for image generation
      $response = $this->client->generateImage($payload);

      if (empty($response) || !isset($response['data'])) {
        throw new AiResponseErrorException('Empty or invalid response from image generation service');
      }

      // Convert response to ImageFile objects
      $images = [];
      foreach ($response['data'] as $image_data) {
        if (isset($image_data['url'])) {
          // Download the image content from the URL
          $image_content = file_get_contents($image_data['url']);
          if ($image_content !== false) {
            $images[] = new ImageFile($image_content, 'image/png', 'generated_image.png');
          }
        } elseif (isset($image_data['b64_json'])) {
          // Handle base64 encoded images
          $image_content = base64_decode($image_data['b64_json']);
          if ($image_content !== false) {
            $images[] = new ImageFile($image_content, 'image/png', 'generated_image.png');
          }
        }
      }

      if (empty($images)) {
        throw new AiResponseErrorException('No valid images generated');
      }

      return new TextToImageOutput($images, $response, $tags);

    } catch (\Exception $e) {
      $error_message = sprintf('Text to image operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Executes an image classification operation with the AI model.
   *
   * @param string|array|\Drupal\ai\OperationType\ImageClassification\ImageClassificationInput $input
   *   The input image(s) or ImageClassificationInput object for classification.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\ImageClassification\ImageClassificationOutput
   *   The classification results from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the image classification fails.
   */
  public function imageClassification(string|array|ImageClassificationInput $input, string $model_id, array $tags = []): ImageClassificationOutput {
    $this->loadClient();

    // Convert input to chat format since most models handle image classification via chat
    $messages = [];

    if ($input instanceof ImageClassificationInput) {
      $prompt = $input->getPrompt() ?: 'Analyze and classify this image. Provide a detailed description of what you see.';
      $images = $input->getImages();
    } elseif (is_array($input)) {
      $prompt = 'Analyze and classify this image. Provide a detailed description of what you see.';
      $images = $input; // Assume array of images
    } else {
      $prompt = (string) $input;
      $images = []; // No images provided in string input
    }

    // Build message with text and images
    $content = [
      [
        'type' => 'text',
        'text' => $prompt,
      ]
    ];

    // Add images to content
    foreach ($images as $image) {
      $content[] = [
        'type' => 'image_url',
        'image_url' => [
          'url' => 'data:' . $image->getMimeType() . ';base64,' . base64_encode($image->getBinary()),
        ],
      ];
    }

    $messages[] = [
      'role' => 'user',
      'content' => $content,
    ];

    try {
      // Use the existing chat generation method for image analysis
      $response_text = $this->client->model($model_id)->generate($messages);

      if (empty($response_text)) {
        throw new AiResponseErrorException('Empty response from image classification service');
      }

      // For image classification, we'll return the analysis text as the classification result
      // The AI module's ImageClassificationOutput expects an array of classifications
      $classifications = [
        [
          'label' => 'AI Analysis',
          'confidence' => 1.0,
          'description' => $response_text,
        ]
      ];

      $raw_response = ['analysis' => $response_text, 'model' => $model_id];

      return new ImageClassificationOutput($classifications, $raw_response, $tags);

    } catch (\Exception $e) {
      $error_message = sprintf('Image classification operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Executes a speech-to-text operation with the AI model.
   *
   * @param string|\Drupal\ai\OperationType\SpeechToText\SpeechToTextInput $input
   *   The input audio or SpeechToTextInput object for transcription.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput
   *   The transcription results from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the speech-to-text operation fails.
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $this->loadClient();

    try {
      if ($input instanceof SpeechToTextInput) {
        $audio = $input->getAudio();
        $language = $input->getLanguage();
      } else {
        // If string input, assume it's a file path or base64 data
        $audio = $input;
        $language = null;
      }

      // Use the audio transcription API
      $response = $this->client->transcribeAudio($model_id, $audio, $language);

      if (empty($response) || !isset($response['text'])) {
        throw new AiResponseErrorException('Empty or invalid response from speech-to-text service');
      }

      return new SpeechToTextOutput($response['text'], $response, $tags);

    } catch (\Exception $e) {
      $error_message = sprintf('Speech-to-text operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Executes a text-to-speech operation with the AI model.
   *
   * @param string|\Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput $input
   *   The input text or TextToSpeechInput object for speech generation.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput
   *   The generated audio from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the text-to-speech operation fails.
   */
  public function textToSpeech(string|TextToSpeechInput $input, string $model_id, array $tags = []): TextToSpeechOutput {
    $this->loadClient();

    try {
      if ($input instanceof TextToSpeechInput) {
        $text = $input->getText();
        $voice = $input->getVoice();
        $format = $input->getFormat();
      } else {
        $text = (string) $input;
        $voice = 'alloy'; // Default voice
        $format = 'mp3'; // Default format
      }

      // Use the text-to-speech API
      $response = $this->client->generateSpeech($model_id, $text, $voice, $format);

      if (empty($response)) {
        throw new AiResponseErrorException('Empty response from text-to-speech service');
      }

      // Create AudioFile object
      $audio_file = new AudioFile($response, 'audio/' . $format, 'generated_speech.' . $format);

      return new TextToSpeechOutput($audio_file, ['model' => $model_id], $tags);

    } catch (\Exception $e) {
      $error_message = sprintf('Text-to-speech operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Executes a text translation operation with the AI model.
   *
   * @param \Drupal\ai\OperationType\TranslateText\TranslateTextInput $input
   *   The TranslateTextInput object containing text and language preferences.
   * @param string $model_id
   *   The ID of the model to use.
   * @param array $options
   *   Optional configuration options.
   *
   * @return \Drupal\ai\OperationType\TranslateText\TranslateTextOutput
   *   The translation results from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the translation operation fails.
   */
  public function translateText(TranslateTextInput $input, string $model_id, array $options = []): TranslateTextOutput {
    $this->loadClient();

    try {
      $text = $input->getText();
      $source_language = $input->getSourceLanguage();
      $target_language = $input->getTargetLanguage();

      // Build translation prompt
      $prompt = "Translate the following text";
      if ($source_language) {
        $prompt .= " from {$source_language}";
      }
      $prompt .= " to {$target_language}";
      $prompt .= ". Only return the translation, no explanations:\n\n{$text}";

      // Use chat interface for translation
      $messages = [
        [
          'role' => 'user',
          'content' => $prompt,
        ]
      ];

      $translated_text = $this->client->model($model_id)->generate($messages);

      if (empty($translated_text)) {
        throw new AiResponseErrorException('Empty response from translation service');
      }

      // Clean up the response (remove any extra explanatory text)
      $translated_text = trim($translated_text);

      $raw_response = [
        'translated_text' => $translated_text,
        'source_language' => $source_language,
        'target_language' => $target_language,
        'model' => $model_id,
      ];

      return new TranslateTextOutput($translated_text, $raw_response, []);

    } catch (\Exception $e) {
      $error_message = sprintf('Translation operation failed: %s', $e->getMessage());
      \Drupal::logger('ai_provider_epamdial')->error($error_message);
      throw new AiResponseErrorException($error_message);
    }
  }

  /**
   * Executes a content moderation operation with the AI model.
   *
   * @param string|\Drupal\ai\OperationType\Moderation\ModerationInput $input
   *   The input text or ModerationInput object for moderation.
   * @param string|null $model_id
   *   The ID of the model to use (optional for moderation).
   * @param array $tags
   *   Optional tags for additional metadata.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationOutput
   *   The moderation results from the AI model.
   *
   * @throws \Drupal\ai\Exception\AiResponseErrorException
   *   Thrown if the moderation operation fails.
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    $this->loadClient();

    // Use a default model if none provided
    if (!$model_id) {
      // Try to find a good model for moderation - prefer GPT models as they're well-suited for this
      $available_models = $this->getConfiguredModels('moderation');
      if (empty($available_models)) {
        throw new AiResponseErrorException('No models available for content moderation');
      }

      // Prefer specific moderation models, then GPT models
      $preferred_models = ['text-moderation-latest', 'gpt-4', 'gpt-4o', 'gpt-3.5-turbo'];
      foreach ($preferred_models as $preferred) {
        if (isset($available_models[$preferred])) {
          $model_id = $preferred;
          break;
        }
      }

      // If no preferred model found, use the first available
      if (!$model_id) {
        $model_id = array_key_first($available_models);
      }
    }

    try {
      if ($input instanceof ModerationInput) {
        $text = $input->getText();
      } else {
        $text = (string) $input;
      }

      // Try OpenAI's dedicated moderation endpoint first
      try {
        $response = $this->client->moderateContent($text, $model_id);

        if (isset($response['results']) && !empty($response['results'])) {
          $result = $response['results'][0];

          // Convert OpenAI moderation format to our format
          $violations = [];
          $flagged = $result['flagged'] ?? false;

          if (isset($result['categories'])) {
            foreach ($result['categories'] as $category => $violated) {
              if ($violated) {
                $confidence = $result['category_scores'][$category] ?? 1.0;
                $violations[] = [
                  'category' => $category,
                  'confidence' => $confidence,
                  'severity' => $confidence > 0.8 ? 'high' : ($confidence > 0.5 ? 'medium' : 'low'),
                ];
              }
            }
          }

          return new ModerationOutput($flagged, $violations, $response, $tags);
        }
      } catch (\Exception $e) {
        // Fall back to chat-based moderation if dedicated endpoint fails
      }

      // Fallback: Use chat interface for moderation
      $prompt = "Analyze the following content for violations of content policy. " .
                "Check for: hate speech, harassment, violence, self-harm, sexual content, " .
                "illegal activities, spam, misinformation. " .
                "Respond with JSON format: {\"flagged\": true/false, \"violations\": [\"category1\", \"category2\"], \"explanation\": \"brief explanation\"}\n\n" .
                "Content to analyze: " . $text;

      $messages = [
        [
          'role' => 'user',
          'content' => $prompt,
        ]
      ];

      $response_text = $this->client->model($model_id)->generate($messages);

      if (empty($response_text)) {
        throw new AiResponseErrorException('Empty response from moderation service');
      }

      // Try to parse JSON response
      $moderation_result = json_decode($response_text, true);

      if (json_last_error() === JSON_ERROR_NONE && isset($moderation_result['flagged'])) {
        $flagged = (bool) $moderation_result['flagged'];
        $violations = [];

        if (isset($moderation_result['violations']) && is_array($moderation_result['violations'])) {
          foreach ($moderation_result['violations'] as $violation) {
            $violations[] = [
              'category' => $violation,
              'confidence' => 0.8, // Default confidence since we don't have specific scores
              'severity' => 'medium',
            ];
          }
        }

        $raw_response = [
          'analysis' => $response_text,
          'parsed_result' => $moderation_result,
          'model' => $model_id,
        ];

        return new ModerationOutput($flagged, $violations, $raw_response, $tags);
      } else {
        // If JSON parsing fails, create a basic response based on text analysis
        $flagged = stripos($response_text, 'flagged') !== false ||
                   stripos($response_text, 'violation') !== false ||
                   stripos($response_text, 'inappropriate') !== false;

        $raw_response = [
          'analysis' => $response_text,
          'model' => $model_id,
        ];

        return new ModerationOutput($flagged, [], $raw_response, $tags);
      }

    } catch (\Exception $e) {
      $error_message = sprintf('Moderation operation failed: %s', $e->getMessage());
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

  /**
   * Generates an enhanced display name that distinguishes between model variants.
   *
   * @param array $model
   *   The model data from the API.
   *
   * @return string
   *   An enhanced display name that includes variant information.
   */
  private function generateDisplayName(array $model): string {
    $display_name = $model['display_name'] ?? $model['id'];
    $model_id = $model['id'];

    // If the display name doesn't contain enough distinguishing information,
    // enhance it with details from the model ID
    $enhancements = [];

    // Add reasoning capability
    if (strpos($model_id, '-reasoning') !== false) {
      $enhancements[] = 'Reasoning';
    }

    // Add thinking capability
    if (strpos($model_id, '-with-thinking') !== false) {
      $enhancements[] = 'Thinking';
    }

    // Add specific dates for version clarity
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $model_id, $matches)) {
      $date = $matches[1];
      // Only add date if display name doesn't already contain it
      if (strpos($display_name, $date) === false) {
        $enhancements[] = $date;
      }
    }

    // Add model type variants
    if (strpos($model_id, '-codex') !== false && strpos($display_name, 'Codex') === false) {
      $enhancements[] = 'Codex';
    }

    if (strpos($model_id, '-chat') !== false && strpos($display_name, 'Chat') === false) {
      $enhancements[] = 'Chat';
    }

    // For VertexAI models, add the version info
    if (preg_match('/@(\w+)$/', $model_id, $matches)) {
      $version = $matches[1];
      if ($version !== 'latest' && strpos($display_name, $version) === false) {
        $enhancements[] = '@' . $version;
      }
    }

    // Add enhancements to display name
    if (!empty($enhancements)) {
      $display_name .= ' (' . implode(', ', $enhancements) . ')';
    }

    return $display_name;
  }

}