<?php

namespace Drupal\extended_paragraph;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Class CloneReferencedEntityService.
 */
class CloneReferencedEntityService {

  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * CloneReferencedEntityService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   */
  public function __construct(EntityTypeManager $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity_to_clone
   * @param string $langcode target language
   * @param int $level Debug thing, to check which level is parsed
   *
   * @return \Drupal\Core\Entity\EntityInterface
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function cloneEntity(ContentEntityInterface $entity_to_clone, $langcode, $level = 0) {
    $paragraph_array = $entity_to_clone->toArray();
    $target_type = $entity_to_clone->getEntityTypeId();
    $entity_type = $this->entityTypeManager->getDefinition($target_type);
    $bundle_key = $entity_type->getKey('bundle');

    // Create a new entity for this language.
    $new_entity = [
      $bundle_key => $entity_to_clone->bundle(),
      'langcode' => $langcode,
      'content_translation_source' => $entity_to_clone->language()->getId(),
    ];
    // Loop through all fields in the paragraph and add to new entity.
    foreach ($entity_to_clone->getFieldDefinitions() as $field_name => $field_definition) {
      // Check that the value is a field config and not empty.
      if (substr_count($field_name, 'field_', 0) || in_array($field_name, [
          'parent_type',
          'parent_field_name',
          'status',
          'reference',
        ], FALSE)) {
        if (!empty($paragraph_array[$field_name])) {
          $new_entity[$field_name] = $paragraph_array[$field_name];
          if ($this->checkEntityTypeCloneable($field_definition->getSetting('target_type'))) {
            /** @var [EntityInterface] $entities */
            $entities = $entity_to_clone->get($field_name)->referencedEntities();
            $cloned_entites = [];
            foreach ($entities as $entity) {
              $cloned_entites[] = $this->cloneEntity($entity, $langcode, $level + 1);
            }
            $new_entity[$field_name] = $cloned_entites;
          }
        }
      }
    }
    if ($new_entity['parent_type'][0]['value'] == 'node') {
      // Be sure that the system knows the parent of this field!
      $new_entity['parent_id'] = $paragraph_array['parent_id'];
    }
    $clone = $this->entityTypeManager->getStorage($target_type)
      ->create($new_entity);
    return $clone;
  }


  /**
   * Helper function that translates a given paragraph field on the source nodel
   *
   * @param Node $node
   * @param string $fieldname field name to translate
   * @param string $targetLangCode language code for the translation
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @return array|boolean
   */
  public function translateFieldOnNode(Node $node, $fieldname, $targetLangCode) {
    $returnData = [];
    if ($node->hasField($fieldname)) {   // Should check for all paragraph fields!
      /** @var \Drupal\entity_reference_revisions\Plugin\Field\FieldType\EntityReferenceRevisionsItem $fieldData */
      foreach ($node->get($fieldname) as $delta => $fieldData) {
        /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
        $paragraph = $fieldData->entity;
        if ($paragraph) {
          $translatedParagraph = $this->translateParagraphField($paragraph, $targetLangCode);
          if ($translatedParagraph) {
            $translatedParagraph->save();
            $returnData[$fieldname][$delta] = [
              'target_id' => $translatedParagraph->id(),
              'target_revision_id' => $translatedParagraph->getRevisionId(),
            ];
          }
        }
      }
      return $returnData;
    }
    return FALSE;
  }

  /**
   * Helper function that actually translates the paragraph entity
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   * @param string $targetLangCode language code for the translation
   *
   * @return $this|bool|\Drupal\Core\Entity\EntityInterface|\Drupal\paragraphs\Entity\Paragraph
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */

  private function translateParagraphField(Paragraph $paragraph, $targetLangCode) {
    if (!$paragraph->hasTranslation($this->targetLangcode)) {
      /** @var Paragraph $translatedParagraph */
      $translatedParagraph = $this->cloneEntity($paragraph, $targetLangCode);
      $reference = $paragraph->reference->getString();
      if ($reference == '') {
        $reference = $translatedParagraph->label();
      }
      $translatedParagraph->reference = $reference . ' (' . $targetLangCode . ')';
    }
    else {
      $translatedParagraph = $paragraph->getTranslation($this->targetLangcode);
      if (!$translatedParagraph) {
        return FALSE;
      }
    }
    return $translatedParagraph;
  }

  /**
   * Checks whether we support cloning a certain entity type or not.
   *
   * @param string $entity_type_id the entity type ID to check whether it's
   *   cloneable
   *
   * @return bool
   */
  public function checkEntityTypeCloneable($entity_type_id) {
    return in_array($entity_type_id, [
      'field_collection_item',
      'paragraph',
      'segment',
    ], FALSE);
  }
}
