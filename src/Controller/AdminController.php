<?php

namespace Drupal\bm_views_instantiated_view\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;

class AdminController extends ControllerBase {

  public function list() {
    $storage = $this->entityTypeManager()->getStorage('bm_viv');
    $items = $storage->loadMultiple();

    $rows = [];
    foreach ($items as $item) {
      $db = $item->db_view_name;
      $rows[] = [
        'data' => [
          ['data' => $item->label()],
          ['data' => $db],
          ['data' => $item->view_id . ':' . $item->display_id],
          ['data' => date('Y-m-d H:i', $item->last_refreshed ?? $item->created ?? time())],
          [
            'data' => [
              'data' => [
                '#type' => 'operations',
                '#links' => [
                  'sql' => [
                    'title' => $this->t('Show SQL'),
                    'url' => Url::fromRoute('bm_viv.admin.show_sql', ['db_view' => $db]),
                  ],
                  'preview' => [
                    'title' => $this->t('Preview'),
                    'url' => Url::fromRoute('bm_viv.admin.preview', ['db_view' => $db]),
                  ],
                  'edit' => [
                    'title' => $this->t('Edit source'),
                    'url' => Url::fromRoute(
                      'entity.view.edit_form',
                      ['view' => $item->view_id],
                      ['query' => ['display_id' => $item->display_id]]
                    ),
                  ],
                  'rename' => [
                    'title' => $this->t('Rename'),
                    'url' => Url::fromRoute('bm_viv.admin.rename', ['db_view' => $db]),
                  ],
                  'delete' => [
                    'title' => $this->t('Delete'),
                    'url' => Url::fromRoute('bm_viv.admin.delete', ['db_view' => $db]),
                  ],
                ],
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Label'), $this->t('DB view'), $this->t('Source'), $this->t('Updated'), $this->t('Operations')],
      '#rows' => $rows,
      '#empty' => $this->t('No instantiated views yet.'),
    ];
  }

  public function titleSql($db_view) {
    return $this->t('SHOW CREATE VIEW: @v', ['@v' => $db_view]);
  }

  public function showSql($db_view) {
    $sql = \Drupal::service('bm_viv.instantiator')->showCreate($db_view);
    return [
      '#type' => 'details',
      '#title' => $this->t('Create statement'),
      '#open' => TRUE,
      'pre' => ['#type' => 'textarea', '#default_value' => $sql, '#rows' => 20],
    ];
  }

  public function preview($db_view) {
    $rows = \Drupal::service('bm_viv.instantiator')->previewRows($db_view, 100);
    $build = [
      '#type' => 'table',
      '#header' => !empty($rows) ? array_keys(reset($rows)) : [],
      '#rows' => [],
      '#empty' => $this->t('No rows.'),
    ];
    foreach ($rows as $r) {
      $build['#rows'][] = array_values($r);
    }
    return $build;
  }

  public function delete($db_view) {
    $inst = \Drupal::service('bm_viv.instantiator');
    $inst->drop($db_view);

    // Remove mapping entity if present.
    $storage = $this->entityTypeManager()->getStorage('bm_viv');
    if ($entity = $storage->load($db_view)) {
      $entity->delete();
    }

    $this->messenger()->addStatus($this->t('Dropped MySQL VIEW @v.', ['@v' => $db_view]));
    return new RedirectResponse(Url::fromRoute('bm_viv.admin.list')->toString());
  }

}
