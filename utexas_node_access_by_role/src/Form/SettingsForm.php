<?php

namespace Drupal\utexas_node_access_by_role\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure settings for the UTexas Access by Role module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'utexas_node_access_by_role_general_config';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $redirect_path = $this->config('utexas_node_access_by_role.settings')->get('redirect_path');
    $form['redirect_path'] = [
      '#title' => 'Redirect anonymous users to',
      '#description' => $this->t('If an anonymous user tries to access a page they cannot, redirect them to the path specified below. Include a leading slash (i.e., <code>/user/login</code>). Leave this field blank to use the default behavior: for sites not using Enterprise Authentication, redirect to <code>/user</code>; for sites using Enterprise Authentication, redirect to <code>/saml_login</code>.'),
      '#type' => 'textfield',
      '#default_value' => $redirect_path ?? '',
      '#size' => '20',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getValue('redirect_path');
    if (!UrlHelper::isExternal($user_input) && !str_starts_with($user_input, '/') && $user_input !== '') {
      $form_state->setErrorByName('redirect_path', $this->t('Internal URLs must start with a /'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('utexas_node_access_by_role.settings');
    $config->set('redirect_path', $form_state->getValue('redirect_path'))->save();
    drupal_flush_all_caches();
    parent::submitForm($form, $form_state);
  }

}
