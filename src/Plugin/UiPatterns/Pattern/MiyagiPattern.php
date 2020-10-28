<?php

namespace Drupal\ui_patterns_miyagi\Plugin\UiPatterns\Pattern;

use Drupal\ui_patterns\Plugin\PatternBase;

/**
 * The UI Pattern plugin.
 *
 * @UiPattern(
 *   id = "miyagi",
 *   label = @Translation("Miyagi Pattern"),
 *   description = @Translation("Pattern provided by a Miyagi instance."),
 *   deriver = "\Drupal\ui_patterns_miyagi\Plugin\Deriver\MiyagiDeriver"
 * )
 */
class MiyagiPattern extends PatternBase {

}
