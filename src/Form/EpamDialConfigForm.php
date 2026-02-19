<?php

declare(strict_types=1);

namespace Drupal\ai_provider_epamdial\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_provider_epamdial\EpamDialClient;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure EPAM DIAL Provider settings.
 */
final class EpamDialConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ai_provider_epamdial.settings';

  /**
   * The AI provider manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProviderManager;


  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a new EpamDialConfigForm object.
   */
  final public function __construct(AiProviderPluginManager $ai_provider_manager, KeyRepositoryInterface $key_repository) {
    $this->aiProviderManager = $ai_provider_manager;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('key.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_provider_epamdial_config';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_provider_epamdial.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('EPAM DIAL API Key'),
      '#description' => $this->t('Select the API key for authenticating with EPAM DIAL service. Supports BOTH authentication formats automatically:<br>• <strong>JWT Bearer Token</strong>: \"Bearer eyJhbGciOiJS...\" (uses Authorization header)<br>• <strong>API Key</strong>: \"your-api-key\" (uses Api-Key header)<br>The module will automatically detect and use the correct format.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $current_url = $config->get('api_url') ?: 'https://ai-proxy.lab.epam.com/openai';

    // Determine default selection based on current URL
    $default_endpoint = 'custom';
    $predefined_endpoints = [
      'epam_lab' => 'https://ai-proxy.lab.epam.com/openai',
      'elitea' => 'https://next.elitea.ai/llm/v1',
    ];

    foreach ($predefined_endpoints as $key => $url) {
      if ($current_url === $url) {
        $default_endpoint = $key;
        break;
      }
    }

    $form['api_endpoint_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('API Endpoint'),
      '#description' => $this->t('Select your EPAM DIAL API endpoint or choose Custom to enter a different URL.'),
      '#options' => [
        'epam_lab' => $this->t('EPAM DIAL AI Proxy<br><small>https://ai-proxy.lab.epam.com/openai</small><br><em>Note: May require EPAM VPN connection</em>'),
        'elitea' => $this->t('Elitea Environment<br><small>https://next.elitea.ai/llm/v1</small><br><em>Public access, no VPN required</em>'),
        'custom' => $this->t('Custom URL<br><em>Enter a custom endpoint URL below</em>'),
      ],
      '#default_value' => $default_endpoint,
      '#required' => TRUE,
    ];

    $form['api_url_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom API Endpoint URL'),
      '#description' => $this->t('Enter the base URL for your custom EPAM DIAL API endpoint.'),
      '#default_value' => $default_endpoint === 'custom' ? $current_url : '',
      '#states' => [
        'visible' => [
          ':input[name="api_endpoint_type"]' => ['value' => 'custom'],
        ],
        'required' => [
          ':input[name="api_endpoint_type"]' => ['value' => 'custom'],
        ],
      ],
    ];

    $form['troubleshooting'] = [
      '#type' => 'details',
      '#title' => $this->t('Troubleshooting'),
      '#open' => FALSE,
    ];

    $form['troubleshooting']['help'] = [
      '#type' => 'markup',
      '#markup' => $this->t('
        <p><strong>Connection Testing:</strong> This form will automatically test your connection when saved.</p>
        <p><strong>Dual Authentication Support:</strong> The module supports both authentication formats:</p>
        <ul>
          <li><strong>JWT Bearer Token:</strong> <code>Authorization: Bearer YOUR_TOKEN</code></li>
          <li><strong>API Key:</strong> <code>Api-Key: YOUR_KEY</code></li>
        </ul>
        <p><strong>If you experience connection issues:</strong></p>
        <ul>
          <li>Check Drupal logs at <code>/admin/reports/dblog</code> for detailed error messages</li>
          <li>Verify your API key is valid and has the correct permissions</li>
          <li>Test the endpoint manually with curl:</li>
          <li style="margin-left: 20px;"><code>curl -H "Api-Key: YOUR_KEY" YOUR_ENDPOINT/models</code></li>
          <li style="margin-left: 20px;"><code>curl -H "Authorization: Bearer YOUR_TOKEN" YOUR_ENDPOINT/models</code></li>
          <li>Ensure your server can make outbound HTTPS requests</li>
          <li>Try different endpoint formats if using a custom deployment</li>
        </ul>
        <p><strong>Available Endpoints:</strong></p>
        <ul>
          <li><code>https://ai-proxy.lab.epam.com/openai</code> (EPAM DIAL AI Proxy - may require EPAM VPN connection)</li>
          <li><code>https://next.elitea.ai/llm/v1</code> (Elitea Environment - public access)</li>
          <li>Custom URL - for other EPAM DIAL deployments</li>
        </ul>
        <p><strong>Expected Results:</strong> A successful connection should discover 100+ models including GPT-4, Claude 4.5, Gemini 2.5, and more.</p>
      '),
    ];

    // Available Models section - get count for title
    $models_count = $this->getModelsCount($config);
    $models_title = $models_count > 0
      ? $this->t('Available Models (@count)', ['@count' => $models_count])
      : $this->t('Available Models');

    $form['models'] = [
      '#type' => 'details',
      '#title' => $models_title,
      '#description' => $this->t('Models available through your EPAM DIAL connection. This list is updated when you save the configuration.'),
      '#open' => FALSE,
    ];

    $form['models']['models_display'] = [
      '#type' => 'markup',
      '#markup' => $this->getModelsDisplay($config),
    ];


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $api_key_id = $form_state->getValue('api_key');
    $endpoint_type = $form_state->getValue('api_endpoint_type');

    // Determine the actual API URL based on selection
    $api_url = '';
    $predefined_endpoints = [
      'epam_lab' => 'https://ai-proxy.lab.epam.com/openai',
      'elitea' => 'https://next.elitea.ai/llm/v1',
    ];

    if ($endpoint_type === 'custom') {
      $api_url = $form_state->getValue('api_url_custom');
      if (empty($api_url)) {
        $form_state->setErrorByName('api_url_custom', $this->t('Custom API URL is required when Custom URL is selected.'));
        return;
      }
    } elseif (isset($predefined_endpoints[$endpoint_type])) {
      $api_url = $predefined_endpoints[$endpoint_type];
    } else {
      $form_state->setErrorByName('api_endpoint_type', $this->t('Invalid endpoint type selected.'));
      return;
    }

    if (empty($api_key_id)) {
      $form_state->setErrorByName('api_key', $this->t('API key is required.'));
      return;
    }

    // Test the API key
    try {
      $key = $this->keyRepository->getKey($api_key_id);
      if (!$key) {
        $form_state->setErrorByName('api_key', $this->t('Selected API key not found.'));
        return;
      }

      $api_key = $key->getKeyValue();
      if (empty($api_key)) {
        $form_state->setErrorByName('api_key', $this->t('Selected API key is empty.'));
        return;
      }

      // Test connection
      $client = new EpamDialClient($api_key, $api_url ?: 'https://ai-proxy.lab.epam.com/openai');
      if (!$client->testConnection()) {
        $form_state->setErrorByName('api_key', $this->t('Failed to connect to EPAM DIAL service. Please check the Drupal logs for detailed error information. Verify: 1) Your API key is valid and active, 2) The endpoint URL is correct and accessible, 3) Your server can make outbound HTTPS requests.'));
      } else {
        \Drupal::messenger()->addStatus($this->t('Successfully connected to EPAM DIAL service! Found @count available models.', [
          '@count' => count($client->loadModels())
        ]));
      }
    } catch (\Exception $e) {
      $form_state->setErrorByName('api_key', $this->t('Error testing connection: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $endpoint_type = $form_state->getValue('api_endpoint_type');

    // Determine the actual API URL based on selection
    $api_url = '';
    $predefined_endpoints = [
      'epam_lab' => 'https://ai-proxy.lab.epam.com/openai',
      'elitea' => 'https://next.elitea.ai/llm/v1',
    ];

    if ($endpoint_type === 'custom') {
      $api_url = $form_state->getValue('api_url_custom');
    } elseif (isset($predefined_endpoints[$endpoint_type])) {
      $api_url = $predefined_endpoints[$endpoint_type];
    }

    // Save configuration
    $this->config('ai_provider_epamdial.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('api_url', $api_url)
      ->save();

    // Set default model for chat operations if none exists
    try {
      $provider = $this->aiProviderManager->createInstance('epamdial');
      $models = $provider->getConfiguredModels('chat');
      if (!empty($models)) {
        $default_model = array_key_first($models);
        $this->aiProviderManager->defaultIfNone('chat', 'epamdial', $default_model);
      }
    } catch (\Exception $e) {
      // Log error but don't fail the save
      \Drupal::logger('ai_provider_epamdial')->warning('Could not set default model: @error', ['@error' => $e->getMessage()]);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Get the count of available models.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return int
   *   The number of available models, or 0 if none found.
   */
  private function getModelsCount($config): int {
    $api_key_id = $config->get('api_key');
    $api_url = $config->get('api_url');

    if (empty($api_key_id)) {
      return 0;
    }

    try {
      $key = $this->keyRepository->getKey($api_key_id);
      if (!$key) {
        return 0;
      }

      $api_key = $key->getKeyValue();
      if (empty($api_key)) {
        return 0;
      }

      // Get models from the client
      $client = new EpamDialClient($api_key, $api_url ?: 'https://ai-proxy.lab.epam.com/openai');
      $models = $client->loadModels();

      return count($models);

    } catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Generate the models display markup.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   *
   * @return string
   *   The HTML markup for displaying available models.
   */
  private function getModelsDisplay($config): string {
    $api_key_id = $config->get('api_key');
    $api_url = $config->get('api_url');

    if (empty($api_key_id)) {
      return '<div class="messages messages--warning">' . $this->t('Configure and save your API key to see available models.') . '</div>';
    }

    try {
      $key = $this->keyRepository->getKey($api_key_id);
      if (!$key) {
        return '<div class="messages messages--error">' . $this->t('Selected API key not found.') . '</div>';
      }

      $api_key = $key->getKeyValue();
      if (empty($api_key)) {
        return '<div class="messages messages--error">' . $this->t('Selected API key is empty.') . '</div>';
      }

      // Get models from the client
      $client = new EpamDialClient($api_key, $api_url ?: 'https://ai-proxy.lab.epam.com/openai');
      $models = $client->loadModels();

      if (empty($models)) {
        return '<div class="messages messages--warning">' . $this->t('No models found. Check your API key and connection.') . '</div>';
      }

      // Organize models by type
      $chat_models = [];
      $embedding_models = [];
      $other_models = [];

      foreach ($models as $model) {
        $model_id = $model['id'];
        $display_name = $model['display_name'] ?? $model_id;

        if (!empty($model['capabilities']['embeddings'])) {
          $embedding_models[] = ['id' => $model_id, 'name' => $display_name];
        } elseif (!empty($model['capabilities']['chat']) ||
                  empty($model['capabilities']) ||
                  preg_match('/^(gpt|anthropic\.|claude|gemini|o1|o3|o4|amazon\.|deepseek|meta\.|mistral\.|qwen)/i', $model_id)) {
          $chat_models[] = ['id' => $model_id, 'name' => $display_name];
        } else {
          $other_models[] = ['id' => $model_id, 'name' => $display_name];
        }
      }

      // Sort models alphabetically
      usort($chat_models, fn($a, $b) => strcasecmp($a['name'], $b['name']));
      usort($embedding_models, fn($a, $b) => strcasecmp($a['name'], $b['name']));
      usort($other_models, fn($a, $b) => strcasecmp($a['name'], $b['name']));

      $output = '<div class="models-display">';
      $output .= '<div class="messages messages--status">' . $this->t('Successfully connected! Found @count total models.', ['@count' => count($models)]) . '</div>';

      // Chat Models section
      if (!empty($chat_models)) {
        $output .= '<h4>' . $this->t('Chat Models (@count)', ['@count' => count($chat_models)]) . '</h4>';
        $output .= '<div class="models-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; margin-bottom: 20px;">';
        foreach ($chat_models as $model) {
          $output .= '<div class="model-item" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 0.9em;">';
          $output .= '<strong>' . htmlspecialchars($model['name']) . '</strong><br>';
          $output .= '<small style="color: #666;">' . htmlspecialchars($model['id']) . '</small>';
          $output .= '</div>';
        }
        $output .= '</div>';
      }

      // Embedding Models section
      if (!empty($embedding_models)) {
        $output .= '<h4>' . $this->t('Embedding Models (@count)', ['@count' => count($embedding_models)]) . '</h4>';
        $output .= '<div class="models-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; margin-bottom: 20px;">';
        foreach ($embedding_models as $model) {
          $output .= '<div class="model-item" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 0.9em;">';
          $output .= '<strong>' . htmlspecialchars($model['name']) . '</strong><br>';
          $output .= '<small style="color: #666;">' . htmlspecialchars($model['id']) . '</small>';
          $output .= '</div>';
        }
        $output .= '</div>';
      }

      // Other Models section
      if (!empty($other_models)) {
        $output .= '<h4>' . $this->t('Other Models (@count)', ['@count' => count($other_models)]) . '</h4>';
        $output .= '<div class="models-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; margin-bottom: 20px;">';
        foreach ($other_models as $model) {
          $output .= '<div class="model-item" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 0.9em;">';
          $output .= '<strong>' . htmlspecialchars($model['name']) . '</strong><br>';
          $output .= '<small style="color: #666;">' . htmlspecialchars($model['id']) . '</small>';
          $output .= '</div>';
        }
        $output .= '</div>';
      }

      $output .= '</div>';
      return $output;

    } catch (\Exception $e) {
      return '<div class="messages messages--error">' .
             $this->t('Error loading models: @error', ['@error' => $e->getMessage()]) .
             '</div>';
    }
  }

}