<?php

namespace Drupal\bm_views_instantiated_view\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class RenameForm extends FormBase {

  public function getFormId(): string {
    return 'bm_viv_rename_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $db_view = NULL): array {
    $form['old'] = ['#type' => 'value', '#value' => $db_view];
    $form['new'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New MySQL VIEW name'),
      '#default_value' => $db_view,
      '#required' => TRUE,
      '#description' => $this->t('Lowercase, max 64 chars, only [a-z0-9_].'),
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rename'),
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $new = $form_state->getValue('new');
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $new)) {
      $form_state->setErrorByName('new', $this->t('Invalid name.'));
    }
  }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $old = $form_state->getValue('old');
    $new = $form_state->getValue('new');


    \Drupal::service('bm_viv.instantiator')->rename($old, $new);

    // Update config mapping id.
    $storage = \Drupal::entityTypeManager()->getStorage('bm_viv');
    if ($entity = $storage->load($old)) {
      $entity->set('db_view_name', $new);
      $entity->set('id', $new);
      $entity->save();
      // Delete the old config key if it still exists.
      if ($old !== $new && ($ghost = $storage->load($old))) {
        $ghost->delete();
      }
    }

    $this->messenger()->addStatus($this->t('Renamed @o → @n', ['@o' => $old, '@n' => $new]));
    $form_state->setRedirectUrl(Url::fromRoute('bm_viv.admin.list'));
  }
}
