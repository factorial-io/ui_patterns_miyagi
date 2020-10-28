<?php

namespace Drupal\ui_patterns_miyagi;

use Drupal\Core\Site\Settings;
use Drupal\ui_patterns\Definition\PatternDefinition;
use Drupal\ui_patterns\Element\Pattern;
use Drupal\ui_patterns\UiPatterns;
use Drupal\ui_patterns_miyagi\Plugin\Deriver\MiyagiDeriver;

/**
 * Override Ui patterns pattern-element implementation.
 *
 * The class overrides  the initial implementation and adds optional
 * schema validation of the input and revert the reserved keywords
 * in render arrays.
 *
 * @package Drupal\ui_patterns_miyagi
 */
class PatternDecorator extends Pattern {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    $class = get_class($this);
    $info['#pre_render'][] = [$class, 'resolveReservedKeywords'];
    return $info;
  }

  /**
   * Validate the input against the schema of the pattern.
   */
  private static function validateInput(PatternDefinition $definition, $fields) {
    if (!Settings::get('miyagi_validate_input', FALSE)) {
      return;
    }
    /** @var \Swaggest\JsonSchema\Schema $schema */
    $schema = $definition->getAdditional()['schema'] ?? FALSE;
    if ($schema) {
      try {
        $fields = self::replaceRenderArrays($fields);
        $objectified = json_decode(json_encode($fields));
        $schema->in($objectified);
      }
      catch (\Exception $e) {
        throw new \Exception(
          sprintf('Pattern input validation failed for pattern `%s`: %s', $definition['id'], $e->getMessage())
        );
      }
    }
  }

  /**
   * Replace render arrays with strings for validation.
   */
  private static function replaceRenderArrays($fields) {
    $result = [];
    foreach ($fields as $key => $value) {
      if (substr($key, 0, strlen(MiyagiDeriver::RESERVED_PREFIX)) == MiyagiDeriver::RESERVED_PREFIX) {
        $key = str_replace(MiyagiDeriver::RESERVED_PREFIX, '', $key);
      }
      if (is_array($value)) {
        reset($value);
        $first_key = key($value);
        /* @TODO Find a better implementation: */
        if ($first_key[0] == '#') {
          $value = "render-array";
        }
        else {
          $value = self::replaceRenderArrays($value);
        }
      }
      $result[$key] = $value;
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public static function processFields(array $element) {
    $definition = UiPatterns::getPatternDefinition($element['#id']);
    if ($definition && isset($element['#fields'])) {
      self::validateInput($definition, $element['#fields']);
    }
    return parent::processFields($element);
  }

  /**
   * Un-transform reserved keywords back to their original.
   */
  public static function resolveReservedKeywords(array $element) {
    $element['#pattern_id'] = $element["#id"];
    foreach (MiyagiDeriver::RESERVED_KEYWORDS as $key) {
      $transformed_key = sprintf("#%s%s", MiyagiDeriver::RESERVED_PREFIX, $key);
      if (isset($element[$transformed_key])) {
        $element['#' . $key] = $element[$transformed_key];
        unset($element[$transformed_key]);
      }
    }
    return $element;
  }

}
