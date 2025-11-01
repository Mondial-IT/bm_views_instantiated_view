<?php

namespace Drupal\bm_views_instantiated_view\Plugin\views\display;

use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a dedicated display for "Instantiated View".
 *
 * @ViewsDisplay(
 *   id = "bm_viv_display",
 *   title = @Translation("Instantiated View"),
 *   help = @Translation("Dedicated display to instantiate this View as a MySQL VIEW."),
 *   uses_route = TRUE
 * )
 */
class InstantiatedDisplay extends DisplayPluginBase {

  /**
   * Default options for this display.
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    // Minimal custom options; future: allow custom name override, etc.
    $options['note'] = ['default' => ''];
    return $options;
  }

  /**
   * Build the options form; add an "Instantiate this view" primary action.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    $form['bm_viv_actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Instantiate'),
      '#open' => TRUE,
    ];

    $form['bm_viv_actions']['instantiate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Instantiate this view'),
      '#submit' => [[get_class($this), 'instantiateSubmit']],
      '#limit_validation_errors' => [],
      '#weight' => -100,
    ];
  }

  /**
   * Submission handler: instantiate the MySQL VIEW.
   */
  public static function instantiateSubmit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\views_ui\ViewUI $view_ui */
    $view_ui = $form_state->get('view');
    $view = $view_ui->getExecutable();

    $display_id = $form_state->get('display_id');
    $instantiator = \Drupal::service('bm_viv.instantiator');

    $result = $instantiator->instantiate($view->storage->id(), $display_id);

    // Persist/update config mapping.
    $id = $result['db_view'];
    $storage = \Drupal::entityTypeManager()->getStorage('bm_viv');
    $label = $view->storage->label() . ' :: ' . $display_id;

    $entity = $storage->load($id) ?: $storage->create(['id' => $id, 'label' => $label]);
    $entity->set('view_id', $view->storage->id());
    $entity->set('display_id', $display_id);
    $entity->set('db_view_name', $result['db_view']);
    $entity->set('sql', $result['sql']);
    $entity->set('changed', \Drupal::time()->getRequestTime());
    if (!$entity->get('created')) {
      $entity->set('created', \Drupal::time()->getRequestTime());
      $entity->set('created_by', \Drupal::currentUser()->getAccountName());
    }
    $entity->set('last_refreshed', \Drupal::time()->getRequestTime());
    $entity->save();

    \Drupal::messenger()->addStatus(t('MySQL VIEW @v created/updated.', ['@v' => $result['db_view']]));
  }

}
