<?php

namespace Drupal\bm_views_instantiated_view\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Tracks a MySQL VIEW created from a Drupal View + display.
 *
 * @ConfigEntityType(
 *   id = "bm_viv",
 *   label = @Translation("Instantiated View"),
 *   handlers = {
 *     "list_builder" = "Drupal\Core\Config\Entity\ConfigEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\Core\Entity\EntityForm",
 *       "edit" = "Drupal\Core\Entity\EntityForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   admin_permission = "administer instantiated views",
 *   config_prefix = "bm_viv",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "view_id",
 *     "display_id",
 *     "db_view_name",
 *     "sql",
 *     "created",
 *     "changed",
 *     "created_by",
 *     "last_refreshed"
 *   }
 * )
 */
class InstantiatedView extends ConfigEntityBase {

  /** @var string */
  public $view_id;

  /** @var string */
  public string $display_id;

  /** @var string */
  public string $db_view_name;

  /** @var string */
  public string $sql;

  /** @var int */
  public int $created;

  /** @var int */
  public int $changed;

  /** @var int */
  public int $last_refreshed;

  /** @var string */
  public string $created_by;

}
