<?php

namespace Drupal\utexas_node_access_by_role\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Alter the node form and node type forms.
 *
 * @internal
 */
class NodeFormAlterations implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * EntityTypeInfo constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service. for form alters.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration object factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    TranslationInterface $translation,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger
  ) {
    $this->stringTranslation = $translation;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('string_translation'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('messenger'),
    );
  }

  /**
   * Alters bundle forms to enforce revision handling.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   The form id.
   *
   * @see hook_form_alter()
   */
  public function nodeFormAlter(array &$form, FormStateInterface $form_state, $form_id) {
    $form['page_access_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Page access'),
      '#group' => 'advanced',
      '#weight' => 100,
    ];
    $form['utexas_node_access_by_role']['#group'] = 'page_access_options';
    $form['utexas_node_access_by_role_enable']['#group'] = 'page_access_options';
    if (!$this->currentUser->hasPermission('set utexas node access by role')) {
      $form['utexas_node_access_by_role']['#prefix'] = 'Your user account does not have permission to change access by role.';
      $form['utexas_node_access_by_role']['#access'] = TRUE;
    }
    $form['utexas_node_access_by_role']['widget']['#options'] = \Drupal::service('utexas_node_access_by_role.helper')->getSelectableRoles();

    $form['utexas_node_access_by_role']['#states'] = [
      'disabled' => array(
        ':input[name="utexas_node_access_by_role_enable"]' => array('checked' => FALSE),
      ),
    ];
    $form['#validate'][] = [$this, 'nodeFormValidate'];
    $form['actions']['submit']['#submit'][] = [$this, 'nodeFormSubmit'];
  }

  /**
   * Alters bundle forms to enforce revision handling.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   The form id.
   *
   * @see hook_form_alter()
   */
  public function nodeTypeFormAlter(array &$form, FormStateInterface $form_state, $form_id) {
    $form['utexas_node_access_by_role_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Page access'),
      '#group' => 'additional_settings',
    ];
    $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable controlling access to nodes of this type by role'),
      '#group' => 'utexas_node_access_by_role_wrapper',
      '#default_value' => FALSE,
    ];
    if (!$this->currentUser->hasPermission('manage utexas node access by role')) {
      $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role']['#access'] = FALSE;
    }
    // Set new field definition default value for the node type on submit.
    $form['actions']['submit']['#submit'][] = [$this, 'bundleFormSubmit'];
  }

  /**
   * Submit handler for the node type form.
   */
  public function bundleFormSubmit(&$form, FormStateInterface $form_state) {
    $status = $form_state->getValue('utexas_node_access_by_role_enable');
    $config = $this->configFactory->getEditable('utexas_node_access_by_role.node_type');
    $config->set('page', $status);
    // Save enabled state.
  }

  public function nodeFormValidate(&$form, FormStateInterface $form_state) {
    // @todo require roles to be selected if 'enable' is checked.
  }

  /**
   * Submit handler for the node type form.
   */
  public function nodeFormSubmit(&$form, FormStateInterface $form_state) {
    $enabled = $form_state->getValue('utexas_node_access_by_role_enable');
    if ($enabled) {
      $roles = $form_state->getValue('utexas_node_access_by_role_roles');
      $allowed = [];
      $role_storage = $this->entityTypeManager->getStorage('user_role');
      foreach ($roles as $value) {
        $role = $role_storage->load($value['target_id']);
        $allowed[] = $role->get('label');
      }
      $this->messenger->addStatus($this->t('This node is currently restricted to the following roles: ' . explode(', ', $allowed)));
    }
  }

}
