<?php

namespace Drupal\ui_patterns_miyagi\Plugin\Deriver;

use Swaggest\JsonSchema\Context;
use Swaggest\JsonSchema\RemoteRefProvider;
use Swaggest\JsonSchema\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * A custom remote ref provider for json schema.
 */
class MiyagiSchemaRemoteRefProvider implements RemoteRefProvider {
  /**
   * The namespaces.
   *
   * @var array
   */
  protected $namespacePaths;

  /**
   * The root path.
   *
   * @var string
   */
  protected $rootPath;

  /**
   * The context to use.
   *
   * @var \Swaggest\JsonSchema\Context
   */
  private $context;

  /**
   * Ctor.
   */
  public function __construct(Context $context, $root_path, $namespace_paths) {
    $this->context = $context;
    $this->rootPath = $root_path;
    $this->namespacePaths = $namespace_paths;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaData($url) {
    if ($url[0] !== '/') {
      return FALSE;
    }
    $elems = array_filter(explode('/', $url));
    $required_namespace = array_shift($elems);

    foreach ($this->namespacePaths as $namespace) {
      if ($required_namespace == $namespace['namespace']) {
        $schema_path =
          $this->rootPath .
          '/' . $namespace['theme_path'] .
          '/' . $namespace['namespace_path'] .
          '/' . implode('/', $elems) .
          '/schema.yaml';

        if (file_exists($schema_path)) {
          $data = Yaml::parseFile($schema_path);
          $data = json_decode(json_encode($data));
          return Schema::import($data);
        }
      }
    }

    return FALSE;
  }

}
