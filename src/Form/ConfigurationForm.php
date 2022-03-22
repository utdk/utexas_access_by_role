<?php

namespace Drupal\utexas_node_access_by_role\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManager;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure settings for the Node Access by Role module.
 */
class ConfigurationForm extends ConfigFormBase {

  /**
   * The Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration object factory.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManager $entity_type_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'utexas_node_access_by_role_config';
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
    $config = $this->configFactory->get('utexas_node_access_by_role.config');
    $form['intro']['#markup'] = $this->t('<h3>Introduction</h3><p><em>Node access by role</em> allows content editors to set which site roles can access individual pages. Limit which roles that can be selected by choosing specific roles below.</p><p></p>');

    /** @var \Drupal\user\RoleStorage $role_storage */
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $roles = $role_storage->loadMultiple();
    $available_roles = [];
    $bypassing_roles = [];
    foreach ($roles as $role) {
      if (!$role->hasPermission('bypass utexas node access by role')) {
        $available_roles[$role->id()] = $role->label();
      }
      else {
        $bypassing_roles[$role->id()] = $role->label();
      }
    }
    // Don't make the 'anonymous' role available -- it's not a valid use case.
    unset($available_roles['anonymous']);

    $form['selectable_roles'] = [
      '#title' => $this->t('Selectable roles'),
      '#type' => 'checkboxes',
      '#options' => $available_roles,
      '#default_value' => $config->get('selectable_roles') ?? [],
      '#description' => $this->t('Roles left unselected will not be displayed as options on individual nodes, and will therefore not be eligible for access control. Leave all roles blank, below, if you want all roles to be available for access control.'),
    ];
    if (!empty($bypassing_roles)) {
      $form['selectable_roles']['#suffix'] = 'The following role(s) are configured to bypass per node access restrictions, and are omitted from the list above: ' . implode(', ', array_values($bypassing_roles)) . '.';
    }
    $form['#submit'][] = [$this, 'submitConfig'];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Extended submit callback.
   */
  public function submitConfig(&$form, FormStateInterface $form_state) {
    $form_settings = [
      'selectable_roles',
    ];
    $config = $this->configFactory->getEditable('utexas_node_access_by_role.config');
    foreach ($form_settings as $setting) {
      $value = $form_state->getValue($setting);
      $config->set($setting, $value);
    }
    $config->save();
  }

}
