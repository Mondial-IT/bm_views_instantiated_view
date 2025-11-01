<?php

namespace Drupal\bm_views_instantiated_view\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\pathauto\MessengerInterface;
use Psr\Log\LoggerInterface;
use Drupal\views\Views;
use Drupal\Component\Utility\Html;

/**
 * Builds SQL from a Views display and (re)creates a MySQL VIEW.
 *
 * Minimal handling per spec:
 * - Ignore exposed/contextual filters and access handlers.
 * - Strip LIMIT; ORDER BY ignored (consumers shouldn't rely on order).
 * - Expand table prefixes.
 * - SQL SECURITY INVOKER (so runtime user privileges apply).
 * - Overwrite policy: DROP and recreate on every instantiate call.
 */
class Instantiator {

  public function __construct(
    private Connection $db,
    private TranslationInterface $t,
    private LoggerInterface $logger,
    private MessengerInterface $messenger,
    private $discovery_cache // @cache.discovery used to clear views_data cache.
  ) {}

  /**
   * Build a deterministic db view name: viv__{view_id}__{display_id}.
   */
  public function dbViewName(string $view_id, string $display_id): string {
    $raw = 'viv__' . strtolower($view_id) . '__' . strtolower($display_id);
    $san = preg_replace('/[^a-z0-9_]/', '_', $raw);
    return substr($san, 0, 64);
  }

  /**
   * Instantiate (create or replace) the MySQL VIEW from a Views display.
   *
   * @return array{db_view: string, sql: string}
   */
  public function instantiate(string $view_id, string $display_id): array {

    $view = Views::getView($view_id);
    if (!$view) {
      $this->logger->error('Instantiator: view :view_id not found.',[':view_id' => $view_id]);
      $this->messenger->addMessage('Instantiator: view :view_id not found.',[':view_id' => $view_id]);
      return [];
      // throw new \RuntimeException("View '$view_id' not found.");
    }

    // Ensure our dedicated display exists and is selected.
    if (!$view->setDisplay($display_id)) {
      $this->logger->error('Instantiator: display :display_id not found on view :view_id.',[':display_id' => $display_id,':view_id'=>$view_id]);
      $this->messenger->addMessage('Instantiator: display :display_id not found on view :view_id.',[':display_id' => $display_id,':view_id'=>$view_id]);
      return [];
      //throw new \RuntimeException("Display '$display_id' not found on view '$view_id'.");
    }

    // Build the view to produce a DB query. Do not execute the result.
    $view->initDisplay();

    $view->preExecute(); // Preps handlers WITHOUT runtime args/exposed filters.

    $view->build();      // Produces query object.

    if (empty($view->query) || !method_exists($view->query, 'query')) {
      $this->logger->error('View query handler did not produce a database query.');
      $this->messenger->addMessage('View query handler did not produce a database query.');

      // throw new \RuntimeException('View query handler did not produce a database query.');
    }

    /** @var \Drupal\Core\Database\Query\SelectInterface $select */
    $select = $view->query->query();

    if (!$select instanceof SelectInterface) {
      $this->logger->error('Expected a SelectInterface for SQL generation.');
      $this->messenger->addMessage('Expected a SelectInterface for SQL generation.');
      return [];
      // throw new \RuntimeException('Expected a SelectInterface for SQL generation.');
    }

    // (1) Strip LIMIT (MySQL VIEW cannot contain LIMIT).
    $select->range(NULL, NULL);

    // (2) Cast to SQL string; collect args and inject quoted values.
    $sql = (string) $select;
    $args = $select->arguments();

    // Expand table prefixes (e.g., {node_field_data} -> prefixed table name).
    $sql = $this->db->prefixTables($sql);

    // 2a) Normalize identifier quotes: convert "identifier" → `identifier` for MySQL.
    $sql = preg_replace('/"([^"]+)"/', '`$1`', $sql);

    // 2b) Drop WHERE (...) entirely (keep everything before WHERE).
    //     This removes node access placeholders like ***CURRENT_USER*** as well.
    $sql_no_where = preg_replace('/\sWHERE\s.+?(?=(\sORDER\s+BY\s|$))/is', '', $sql);

    // 2c) Drop ORDER BY (...) — consumers shouldn’t rely on it in a VIEW.
    $sql_no_where_order = preg_replace('/\sORDER\s+BY\s.+$/is', '', $sql_no_where);

    // 2d) Ensure no trailing semicolon.
    $sql = rtrim($sql_no_where_order, " \t\n\r\0\x0B;");



    // Replace placeholders with quoted literals.
    foreach ($args as $placeholder => $value) {
      // Handle IN() arrays as comma-separated list of quoted values.
      if (is_array($value)) {
        $quoted = implode(', ', array_map([$this->db, 'quote'], $value));
        $sql = str_replace($placeholder, $quoted, $sql);
      }
      else {
        $sql = str_replace($placeholder, $this->db->quote($value), $sql);
      }
    }

    $db_view = $this->dbViewName($view_id, $display_id);

    $this->db->query("DROP VIEW IF EXISTS `$db_view`");
    $create = "CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `$db_view` AS $sql";

    $this->messenger->addMessage('Creating view as :query',[':query'=>$create]);
    $this->db->query($create);


    // Clear views data cache so our base table appears immediately.
    // The 'views_data' cache bin is used by Views discovery; use cache.discovery service to be safe.
    \Drupal::service('cache.discovery')->deleteAll();
    \Drupal::service('cache.data')->delete('views.views_data'); // Defensive.

    $this->logger->notice('Instantiated MySQL VIEW @name for @view:@display', [
      '@name' => $db_view,
      '@view' => $view_id,
      '@display' => $display_id,
    ]);

    $this->messenger->addMessage('Instantiated MySQL VIEW @name for @view:@display', [
      '@name' => $db_view,
      '@view' => $view_id,
      '@display' => $display_id,
    ]);

    return ['db_view' => $db_view, 'sql' => $sql];
  }

  /**
   * Fetch SHOW CREATE VIEW output.
   */
  public function showCreate(string $db_view): string {
    $row = $this->db->query("SHOW CREATE VIEW `$db_view`")->fetchAssoc();
    return $row['Create View'] ?? '';
  }

  /**
   * Simple preview (SELECT * LIMIT 100).
   */
  public function previewRows(string $db_view, int $limit = 100): array {
    // $db_view must be a known/whitelisted view/table name.
    $select = $this->db->select($db_view, 'v')
      ->fields('v')
      ->range(0, (int) $limit);

    return $select->execute()->fetchAllAssoc(NULL, \PDO::FETCH_ASSOC);

  }

  /**
   * Rename the MySQL VIEW, update callers to manage config mapping separately.
   */
  public function rename(string $old, string $new): void {
    // Use RENAME TABLE for views in MySQL.
    $this->db->query("RENAME TABLE `$old` TO `$new`");
    // Clear views data cache so the new base appears.
    \Drupal::service('cache.discovery')->deleteAll();
    \Drupal::service('cache.data')->delete('views.views_data');
  }

  /**
   * Drop the MySQL VIEW.
   */
  public function drop(string $db_view): void {
    $this->db->query("DROP VIEW IF EXISTS `$db_view`");
    \Drupal::service('cache.discovery')->deleteAll();
    \Drupal::service('cache.data')->delete('views.views_data');
  }

  /**
   * Introspect columns via INFORMATION_SCHEMA.
   */
  public function columns(string $db_view): array {
    $schema = $this->db->getConnectionOptions()['database'];
    $sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t
             ORDER BY ORDINAL_POSITION";
    return $this->db->query($sql, [':s' => $schema, ':t' => $db_view])->fetchAllAssoc(NULL, \PDO::FETCH_ASSOC);
  }
}
