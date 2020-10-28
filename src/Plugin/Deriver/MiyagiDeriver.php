<?php

namespace Drupal\ui_patterns_miyagi\Plugin\Deriver;

use Drupal\ui_patterns_library\Plugin\Deriver\LibraryDeriver;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Swaggest\JsonSchema\Context;
use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\SchemaContract;
use Symfony\Component\Yaml\Yaml;

/**
 * Pattern deriver class implementaton for Miyagi.
 */
class MiyagiDeriver extends LibraryDeriver {

  /**
   * List of preserved keywords from UiPatterns module.
   */
  const RESERVED_KEYWORDS = ['id', 'type', 'use'];
  const RESERVED_PREFIX = 'reserved__';

  /**
   * {@inheritdoc}
   */
  public function getFileExtensions() {
    return ['schema.yaml'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPatterns() {
    $patterns = [];
    $themes = [];

    // Get the list of currently active default theme and related base themes.
    $theme_handler = \Drupal::service('theme_handler');
    $default_theme = $theme_handler->getDefault();
    $themes[$default_theme] = $default_theme;
    $base_themes = $theme_handler->getBaseThemes($theme_handler->listInfo(), $default_theme);
    $themes = $themes + $base_themes;

    // Determine the paths to any defined component libraries.
    $namespace_paths = [];
    foreach ($themes as $theme => $item) {
      $theme_config = $theme_handler->getTheme($theme);
      if (isset($theme_config->info["component-libraries"])) {
        foreach ($theme_config->info["component-libraries"] as $namespace => $path) {
          foreach ($path['paths'] as $key => $path_item) {
            $provider = $theme . "@" . $namespace . "_" . $key;
            $namespace_paths[$provider] = [
              "theme_path" => $theme_config->getPath(),
              "namespace_path" => $path_item,
              "namespace" => $namespace,
            ];
          }
        }
      }
    }

    $definitions = [];
    $context = new Context();
    $context->setRemoteRefProvider(new MiyagiSchemaRemoteRefProvider($context, $this->root, $namespace_paths));

    foreach ($namespace_paths as $provider => $data) {
      $this->findPatternDefinitionsInNamespace($data, $provider, $definitions, $context);
    }

    foreach ($definitions as $definition) {
      $definition = $this->applyFields($definition, $definitions);
      $patterns[] = $this->getPatternDefinition($definition);
    }

    return $patterns;
  }

  /**
   * Find pattern definitions in a given namespace.
   */
  protected function findPatternDefinitionsInNamespace($data, $provider, array &$definitions, Context $context) {

    $directory = $this->root . '/' . $data['theme_path'] . '/' . $data['namespace_path'];
    foreach ($this->fileScanDirectory($directory) as $file_path => $file) {
      $base_path = dirname($file_path);
      if ($file->name !== 'schema') {
        continue;
      }

      $relative_path = str_replace($directory . '/', '', $file_path);
      $machine_name = $this->getMachineName(dirname($relative_path));

      // Name of pattern is name of enclosing folder.
      $pattern_name = basename(dirname($relative_path));
      $template_file = $base_path . '/' . $pattern_name . '.twig';
      if (!file_exists($template_file)) {
        continue;
      }

      $info = $this->getInfo($base_path);
      if (isset($info['exposeToDrupal']) && !$info['exposeToDrupal']) {
        continue;
      }
      $readme = $this->getReadme($base_path);
      $schema = $this->getSchema($base_path, $context);
      $mock = $this->getMock($base_path);

      $definition = [
        'id' => $this->getMachineName($data['namespace'] . '_' . $machine_name),
        'label' => $info['name'] ?? ucwords($relative_path),
        'base path' => dirname($file_path),
        'file name' => $file_path,
        'provider' => explode('@', $provider)[0],
        'description' => $readme->body(),
        'use' => str_replace($this->root . '/', '', $template_file),
        'schema' => $schema,
        'mock' => $mock,
      ];

      $definitions[$definition['id']] = $definition;

    }
  }

  /**
   * Get a file path from a list of candidates.
   */
  protected function getFileFromCandidates($base_path, $candidates) {

    foreach ($candidates as $candidate) {
      $path = $base_path . '/' . $candidate;
      if (file_exists($path)) {
        return $path;
      }
    }
    return FALSE;
  }

  /**
   * Get the readme.
   */
  protected function getReadme($base_path) {
    $file = $this->getFileFromCandidates($base_path, [
      '/readme.md',
      '/Readme.md',
      '/ReadMe.md',
      '/README.md',
    ]);
    return $file ? YamlFrontMatter::parseFile($file) : YamlFrontMatter::parse("");
  }

  /**
   * Get the pattern info.
   */
  protected function getInfo($base_path) {
    $file = $this->getFileFromCandidates($base_path, [
      '/info.yaml',
      '/info.yml',
    ]);
    return $file ? Yaml::parseFile($file) : [];
  }

  /**
   * Get the schema.
   */
  protected function getSchema(string $base_path, Context $context) {
    set_error_handler(
      function () {
        // Ignore errors reported by Schema and file_get_contents.
        ;
      }
    );
    $file = $this->getFileFromCandidates($base_path, ['schema.yaml']);
    if (!$file) {
      return FALSE;
    }
    $content = Yaml::parseFile($file);
    $json = json_decode(json_encode($content));
    try {
      return Schema::import($json, $context);
    }
    catch (\Exception $e) {
      watchdog_exception('ui_pattern_miyagi', $e, "Could not handle schema from " . $file);
    } finally {
      restore_error_handler();
    }
    return FALSE;
  }

  /**
   * Get list of fields from a schema.
   */
  protected function getFieldsFromSchema(SchemaContract $schema, $mock, $definitions) {
    $fields = [];

    foreach ($schema->getProperties()->toArray() as $name => $property) {
      $machine_name = $name;
      // UI Patterns does have a list of reserved keywords.
      // Prefix them if needed.
      if (in_array($name, self::RESERVED_KEYWORDS)) {
        $machine_name = self::RESERVED_PREFIX . $name;
      }
      $fields[$machine_name] = [
        'label' => $property->title ?? ucwords($name),
        'type' => $property->type,
        'description' => $property->description,
        'preview' => $this->getPreviewFor($name, $mock, $definitions),
        'escape' => FALSE,
      ];
    }

    return $fields;
  }

  /**
   * Get the mock data.
   */
  private function getMock(string $base_path) {
    $file = $this->getFileFromCandidates($base_path, ['mocks.yaml']);
    if (!$file) {
      return [];
    }
    return Yaml::parseFile($file);
  }

  /**
   * Apply the fields to a definition.
   */
  protected function applyFields($definition, $definitions) {
    $definition['fields'] = $definition['schema']
      ? $this->getFieldsFromSchema($definition['schema'], $definition['mock'], $definitions)
      : [];

    return $definition;
  }

  /**
   * Get the preview for a given field.
   */
  protected function getPreviewFor($name, $mock, $definitions) {
    if (!empty($mock['$hidden'])) {
      $mock = array_merge($mock, $mock['$variants'][0]);
      unset($mock['$name']);
      unset($mock['$hidden']);
      unset($mock['$variants']);
    }
    $preview = $mock[$name] ?? FALSE;
    if (!$preview) {
      return $preview;
    }

    if (is_array($preview)) {
      $preview = $this->resolveMockRefs($preview, $definitions);
    }

    return $preview;
  }

  /**
   * Resolve $tpl and $ref references in mock-data.
   */
  private function resolveMockRefs(array $preview, $definitions) {
    if (isset($preview['$ref']) || isset($preview['$tpl'])) {
      return $this->resolveMockRef($preview, $definitions);
    }
    $result = [];
    foreach ($preview as $ndx => $value) {
      if (is_array($value)) {
        if (isset($value['$render'])) {
          $result[$ndx] = '$render not supported';
        }
        else {
          $result[$ndx] = $this->resolveMockRefs($value, $definitions);
        }
      }
      else {
        $result[$ndx] = $value;
      }
    }
    return $result;
  }

  /**
   * Resolve $tpl and $ref references in mock-data.
   */
  private function resolveMockRef($data, $definitions) {

    $payload = [];
    $ref = FALSE;
    $tpl = FALSE;
    foreach ($data as $ndx => $value) {
      if ($ndx == '$ref') {
        $ref = $value;
      }
      elseif ($ndx == '$tpl') {
        $tpl = $value;
      }
      else {
        $payload[$ndx] = $value;
      }
    }
    if (!$ref && !$tpl) {
      return [];
    }
    if (!$ref && $tpl) {
      $ref = $tpl;
    }
    if ($ref[0] == '/') {
      $ref = substr($ref, 1);
    }

    list($ref, $variant) = array_pad(explode('#', $ref), 2, FALSE);

    $machine_name = $this->getMachineName($ref);
    $definition = $definitions[$machine_name] ?? FALSE;
    if (!$definition) {
      return [];
    }
    if ($variant) {
      $variant_data = array_reduce($definition['mock']['$variants'], function ($carry, $item) use ($variant) {
        return ($this->normalizeVariantName($item['$name']) == $variant) ? $item : $carry;
      }, []);
      $variant_data = array_merge($definition['mock'], $variant_data);
      $payload = array_merge($variant_data, $payload);
    }
    else {
      $payload = array_merge($definition['mock'], $payload);
    }
    unset($payload['$variants']);
    unset($payload['$name']);

    $payload = $this->resolveMockRefs($payload, $definitions);

    if ($tpl) {
      $payload = [
        '#type' => 'pattern',
        '#id' => $definition['id'],
        '#fields' => $this->replaceReservedKeys($payload),
      ];
    }
    return $payload;
  }

  /**
   * Normalize a variant name.
   */
  private function normalizeVariantName($name) {
    return strtolower(str_replace(' ', '-', $name));
  }

  /**
   * Get a machine name.
   */
  private function getMachineName(string $ref) {
    return str_replace(['/', '-'], '_', $ref);
  }

  /**
   * Replace reserved keys.
   */
  private function replaceReservedKeys(array $payload) {
    $result = [];
    foreach ($payload as $key => $value) {
      if (in_array($key, self::RESERVED_KEYWORDS)) {
        $key = self::RESERVED_PREFIX . $key;
      }
      $result[$key] = $value;
    }
    return $result;
  }

}
