<?php

namespace Drupal\utexas_node_access_by_role\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Helper methods.
 *
 * @internal
 */
class NodeAccessHelper implements ContainerInjectionInterface {

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
   * EntityTypeInfo constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration object factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
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
    );
  }

  /**
   * Provide a list of roles that are allowed to be selected.
   *
   * @return array
   *   A key value pair of role ID and role label.
   */
  public function getSelectableRoles() {
    $access_options = [];
    /** @var \Drupal\user\RoleStorage $role_storage */
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    $bypassing_roles = $this->getBypassingRoles();
    // Don't make the 'anonymous' role available: it's not a valid use case.
    unset($roles['anonymous']);
    // Don't make the 'authenticated' role available: it's not a valid use case.
    unset($roles['authenticated']);
    foreach ($roles as $key => $role) {
      if (!in_array($key, array_keys($bypassing_roles))) {
        $access_options[$key] = $role->get('label');
      }
    }
    return $access_options;
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

}
