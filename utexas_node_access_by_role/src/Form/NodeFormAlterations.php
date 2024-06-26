<?php

namespace Drupal\utexas_node_access_by_role\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\utexas_node_access_by_role\Service\NodeAccessHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Alter the node form and node type forms.
 *
 * @internal
 */
class NodeFormAlterations implements ContainerInjectionInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * The Config Factory.
   *
   * @var \Drupal\utexas_node_access_by_role\Service\NodeAccessHelper
   */
  protected $nodeAccessHelper;


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
   * @param \Drupal\utexas_node_access_by_role\Service\NodeAccessHelper $nodeAccessHelper
   *   A helper service for enforcing node access.
   */
  public function __construct(
    TranslationInterface $translation,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    NodeAccessHelper $nodeAccessHelper,
  ) {
    $this->stringTranslation = $translation;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->nodeAccessHelper = $nodeAccessHelper;
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
      $container->get('utexas_node_access_by_role.helper')
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
    if ($form['utexas_node_access_by_role_enable']['widget']['value']['#default_value']) {
      $role_storage = $this->entityTypeManager->getStorage('user_role');
      $roles = $form['utexas_node_access_by_role_roles']['widget']['#default_value'];
      foreach ($roles as $value) {
        $role = $role_storage->load($value);
        $allowed[] = $role->get('label');
      }
      if (!empty($allowed)) {
        $form['utexas_node_access_by_role_help'] = [
          '#markup' => $this->t('<div class="messages messages--status">This page is currently only visible to the following roles:' . implode(', ', $allowed) . '.</div>'),
          '#weight' => -999,
        ];
      }
    }
    $form['page_access_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Page access'),
      '#group' => 'advanced',
      '#weight' => 100,
    ];
    $form['utexas_node_access_by_role_roles']['#group'] = 'page_access_options';
    $form['utexas_node_access_by_role_enable']['#group'] = 'page_access_options';
    if (!$this->currentUser->hasPermission('set utexas node access by role')) {
      $form['utexas_node_access_by_role']['#prefix'] = 'Your user account does not have permission to change access by role.';
      $form['utexas_node_access_by_role_roles']['#disabled'] = TRUE;
      $form['utexas_node_access_by_role_enable']['#disabled'] = TRUE;
    }
    $node_type = $form_state->getBuildInfo()['callback_object']->getEntity();
    $config = $this->configFactory->getEditable('utexas_node_access_by_role.node_type');
    $enabled = (bool) $config->get($node_type->bundle());
    if (!$enabled) {
      $form['utexas_node_access_by_role_enable']['#disabled'] = TRUE;
      $form['utexas_node_access_by_role_roles']['#disabled'] = TRUE;
      $form['utexas_node_access_by_role_enable']['#prefix'] = $this->t('This must be enabled on the node type configuration form.');
    }
    $form['utexas_node_access_by_role_roles']['widget']['#options'] = $this->nodeAccessHelper->getSelectableRoles();
    $form['utexas_node_access_by_role_roles']['#states'] = [
      'invisible' => [
        ':input[name="utexas_node_access_by_role_enable[value]"]' => ['checked' => FALSE],
      ],
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
    $node_type = $form_state->getBuildInfo()['callback_object']->getEntity();
    $config = $this->configFactory->getEditable('utexas_node_access_by_role.node_type');
    $enabled = (bool) $config->get($node_type->id());
    $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable node access protection by role.'),
      '#description' => $this->t('Users must have the <em>Set node access by role</em> permission to be able to protect node access for specific roles.'),
      '#group' => 'utexas_node_access_by_role_wrapper',
      '#default_value' => $enabled,
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
    $node_type = $form_state->getBuildInfo()['callback_object']->getEntity();
    $config->set($node_type->id(), (bool) $status);
    $config->save();
  }

  /**
   * Validation handler.
   */
  public function nodeFormValidate(&$form, FormStateInterface $form_state) {
    $enabled = $form_state->getValue('utexas_node_access_by_role_enable');
    if ($enabled['value']) {
      $roles = $form_state->getValue('utexas_node_access_by_role_roles');
      if (empty($roles)) {
        $form_state->setErrorByName('utexas_node_access_by_role_roles', $this->t('Select at least one role-based protection, or uncheck the "Protect access by role" checkbox.'));
      }
    }
  }

  /**
   * Submit handler for the node type form.
   */
  public function nodeFormSubmit(&$form, FormStateInterface $form_state) {
    $enabled = $form_state->getValue('utexas_node_access_by_role_enable');
    if ($enabled['value']) {
      $roles = $form_state->getValue('utexas_node_access_by_role_roles');
      $allowed = [];
      $role_storage = $this->entityTypeManager->getStorage('user_role');
      foreach ($roles as $value) {
        $role = $role_storage->load($value['target_id']);
        $allowed[] = $role->get('label');
      }
      if (!empty($allowed)) {
        $this->messenger->addStatus($this->t('This page is currently only visible to the following roles:' . implode(', ', $allowed) . '.'));
      }
    }
  }

}
