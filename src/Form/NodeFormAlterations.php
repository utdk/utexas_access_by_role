<?php

namespace Drupal\utexas_node_access_by_role\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\content_moderation\Entity\Handler\BlockContentModerationHandler;
use Drupal\content_moderation\Entity\Handler\NodeModerationHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Alter the node form and node type forms.
 *
 * @internal
 */
class NodeFormAlterations implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The bundle information service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * A keyed array of custom moderation handlers for given entity types.
   *
   * Any entity not specified will use a common default.
   *
   * @var array
   */
  protected $moderationHandlers = [
    'node' => NodeModerationHandler::class,
    'block_content' => BlockContentModerationHandler::class,
  ];

  /**
   * EntityTypeInfo constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service. for form alters.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   Bundle information service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager.
   */
  public function __construct(
    TranslationInterface $translation,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $bundle_info,
    AccountInterface $current_user,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    $this->stringTranslation = $translation;
    $this->entityTypeManager = $entity_type_manager;
    $this->bundleInfo = $bundle_info;
    $this->currentUser = $current_user;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('string_translation'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('current_user'),
      $container->get('entity_field.manager')
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
    // Add fieldset for "Page display options" if not already present.
    if (!isset($form['page_access_options'])) {
      $form['page_access_options'] = [
        '#type' => 'details',
        '#title' => $this->t('Page access'),
        '#group' => 'advanced',
        '#weight' => 100,
      ];
    }
    $form['utexas_node_access_by_role']['#group'] = 'page_access_options';
    if (!$this->currentUser->hasPermission('manage utexas node access by role settings')) {
      $form['utexas_node_access_by_role']['#prefix'] = 'Your user account does not have permission to change access by role.';
      $form['utexas_node_access_by_role']['#disabled'] = TRUE;
    }
    // Display to the user which roles are set to bypass this setting, and
    // remove them from options.
    $bypassing_roles = $this->getBypassingRoles();
    if (!empty($bypassing_roles)) {
      foreach (array_keys($bypassing_roles) as $rid) {
        unset($form['utexas_node_access_by_role']['widget']['#options'][$rid]);
      }
      $form['utexas_node_access_by_role']['#suffix'] = 'The following role(s) are configured to bypass this restriction: ' . implode(', ', array_values($bypassing_roles)) . '.';
    }
    // Temporary logic to populate defaults on node add form.
    // @todo: figure out how this should happen automatically.
    $operation = $form_state->getFormObject()->getOperation();
    if ($operation == 'default') {
      $bundle = $form_state->getBuildInfo()['callback_object']->getEntity()->bundle();
      $node = $this->entityTypeManager->getStorage('node')->create(['type' => $bundle]);
      $node_type_current_defaults = $node->get('utexas_node_access_by_role')->getValue();
      $defaults = $node_type_current_defaults[0] ?? [];
      $form['utexas_node_access_by_role']['widget']['#default_value'] = $defaults;
    }
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
      '#title' => $this->t('Page access defaults'),
      '#group' => 'additional_settings',
    ];
    $default_roles = $this->getDefaultNodeTypeRoles($form_state);
    /** @var \Drupal\user\RoleStorage $role_storage */
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    foreach ($roles as $key => $role) {
      $access_options[$key] = $role->get('label');
    }
    $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Set the default roles which should be able to access nodes of this type.'),
      '#group' => 'utexas_node_access_by_role_wrapper',
      '#options' => $access_options,
      '#default_value' => $default_roles,
      '#description' => $this->t('These defaults will show when creating a new node of this type. Each node can modify these defaults. Subsequent changes to the defaults here will not take effect on modified nodes.'),
    ];
    $bypassing_roles = $this->getBypassingRoles();
    if (!empty($bypassing_roles)) {
      foreach (array_keys($bypassing_roles) as $rid) {
        unset($form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role']['#options'][$rid]);
      }
      $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role']['#suffix'] = 'The following role(s) are configured to bypass this restriction: ' . implode(', ', array_values($bypassing_roles)) . '.';
    }
    if (!$this->currentUser->hasPermission('manage utexas node access by role settings')) {
      $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role']['#prefix'] = 'Your user account does not have permission to change access by role.';
      $form['utexas_node_access_by_role_wrapper']['utexas_node_access_by_role']['#disabled'] = TRUE;
    }
    // Set new field definition default value for the node type on submit.
    $form['actions']['submit']['#submit'][] = [$this, 'bundleFormSubmit'];
  }

  /**
   * Provide a list of roles that are allowed to bypass the restrictions.
   *
   * @return array
   *   A key value pair of role ID and role label.
   */
  public function getBypassingRoles() {
    /** @var \Drupal\user\RoleStorage $role_storage */
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $roles = $role_storage->loadMultiple();
    $bypassing_roles = [];
    foreach ($roles as $role) {
      if ($role->hasPermission('bypass utexas node access by role')) {
        $bypassing_roles[$role->id()] = $role->label();
      }
    }
    return $bypassing_roles;
  }

  /**
   * Returns the default value of display_updated by creating a dummy node.
   *
   * Watch issue below for getting default values without an entity.
   * https://www.drupal.org/node/2318187
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The node entity.
   *
   * @return string
   *   An string that contains the active default value.
   *
   * @see updated_form_node_type_form_alter
   */
  public function getDefaultNodeTypeRoles(FormStateInterface $form_state) {
    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = $form_state->getBuildInfo()['callback_object']->getEntity();
    /** @var \Drupal\Core\Entity\EntityFormInterface $node_type_form */
    $node_type_form = $form_state->getFormObject();
    // Get a node so that we can get default values.
    $operation = $node_type_form->getOperation();
    if ($operation == 'add') {
      // Create a node with a fake bundle.
      $node = $this->entityTypeManager->getStorage('node')->create(['type' => $node_type->uuid()]);
    }
    else {
      // Create a node with existing bundle.
      $node = $this->entityTypeManager->getStorage('node')->create(['type' => $node_type->id()]);
    }
    // Since we had to create a "dummy" node, we'll get the default value from
    // the node rather than the field definition.
    $node_type_current_defaults = $node->get('utexas_node_access_by_role')->getValue();
    return $node_type_current_defaults[0] ?? [];
  }

  /**
   * Submit handler for the node type form.
   */
  public function bundleFormSubmit(&$form, FormStateInterface $form_state) {
    $field_name = 'utexas_node_access_by_role';
    $form_value = $form_state->getValue($field_name);
    $current_default_value = $this->getDefaultNodeTypeRoles($form_state);
    // If there is not a new value, we need take no further action.
    if ($current_default_value == $form_value) {
      return;
    }
    /** @var \Drupal\node\NodeTypeInterface $node_type */
    $node_type = $form_state->getBuildInfo()['callback_object']->getEntity();

    $fields = $this->entityFieldManager->getFieldDefinitions('node', $node_type->id());
    /** @var Drupal\Core\Field\Entity\BaseFieldOverride $field_definition */
    $field_definition = $fields[$field_name];
    $field_definition->getConfig($node_type->id())->setDefaultValue($form_value)->save();

    $this->entityFieldManager->clearCachedFieldDefinitions();
  }

}
