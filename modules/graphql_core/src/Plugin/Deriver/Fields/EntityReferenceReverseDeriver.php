<?php

namespace Drupal\graphql_core\Plugin\Deriver\Fields;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\graphql\Utility\StringHelper;
use Drupal\graphql_core\Plugin\GraphQL\Interfaces\Entity\Entity;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EntityReferenceReverseDeriver extends DeriverBase implements ContainerDeriverInterface {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The typed data manager service.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $basePluginId) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('typed_data_manager')
    );
  }

  /**
   * RawValueFieldItemDeriver constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    TypedDataManagerInterface $typedDataManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->typedDataManager = $typedDataManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($basePluginDefinition) {
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      $interfaces = class_implements($entityType->getClass());
      if (!array_key_exists(FieldableEntityInterface::class, $interfaces)) {
        continue;
      }

      foreach ($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId) as $fieldDefinition) {
        if ($fieldDefinition->getType() !== 'entity_reference' || !$targetTypeId = $fieldDefinition->getSetting('target_type')) {
          continue;
        }

        $fieldName = $fieldDefinition->getName();
        $derivative = [
          'parents' => [Entity::getId($targetTypeId)],
          'name' => StringHelper::propCase('reverse', $fieldName, $entityTypeId),
          'description' => $this->t('Reverse reference: @description', [
            '@description' => $fieldDefinition->getDescription(),
          ]),
          'field' => $fieldName,
          'entity_type' => $entityTypeId,
          'schema_cache_tags' => array_merge($fieldDefinition->getCacheTags(), ['entity_field_info']),
          'schema_cache_contexts' => $fieldDefinition->getCacheContexts(),
          'schema_cache_max_age' => $fieldDefinition->getCacheMaxAge(),
        ];

        /** @var \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $definition */
        $definition = $this->typedDataManager->createDataDefinition("entity:$targetTypeId");
        $properties = $definition->getPropertyDefinitions();
        $queryableProperties = array_filter($properties, function ($property) {
          return $property instanceof BaseFieldDefinition && $property->isQueryable();
        });

        if (!empty($queryableProperties)) {
          $derivative['arguments']['filter'] = [
            'multi' => FALSE,
            'nullable' => TRUE,
            'type' => StringHelper::camelCase($targetTypeId, 'query', 'filter', 'input'),
          ];
        }

        $this->derivatives["$entityTypeId-$fieldName"] = $derivative + $basePluginDefinition;
      }
    }

    return $this->derivatives;
  }
}
