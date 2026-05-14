<?php

namespace Drupal\utexas_node_access_by_role\Hook;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeAccessRebuild;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;
use Drupal\utexas_node_access_by_role\EntityTypeInfo;
use Drupal\utexas_node_access_by_role\Form\NodeFormAlterations;

/**
 * Hook implementations.
 */
class Hooks {

  /**
   * Implements hook_node_access_records().
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node) {
    $grants = [];
    // If the node is not published, defer to other Drupal permissions.
    if (!$node->isPublished()) {
      return $grants;
    }
    // If the node does not have any restrictions, defer.
    if ($node->hasField('utexas_node_access_by_role_enable')) {
      $enabled = (bool) $node->get('utexas_node_access_by_role_enable')->getString();
      if (!$enabled) {
        return $grants;
      }
    }
    // This node has by-role restrictions. First, explicitly grant access to
    // roles that have the 'bypass node access by role' permission.
    $bypassing_roles = \Drupal::service('utexas_node_access_by_role.helper')->getBypassingRoles();
    if (!empty(array_keys($bypassing_roles))) {
      foreach (array_keys($bypassing_roles) as $bypasser) {
        $grants[] = [
          'realm' => 'utexas_node_access_by_role',
          'gid' => crc32($bypasser),
          'grant_view' => 1,
          'grant_update' => 0,
          'grant_delete' => 0,
          'langcode' => 'en',
        ];
      }
    }
    // 1. Retrieve node-specific role restrictions based on the base field.
    if ($node->hasField('utexas_node_access_by_role_roles') && !$node->get('utexas_node_access_by_role_roles')->isEmpty()) {
      // Create a node-ID-specific list of roles granted access to this account.
      foreach (array_values($node->get('utexas_node_access_by_role_roles')->getValue()) as $value) {
        if (in_array($value['target_id'], array_keys($bypassing_roles))) {
          // This array check is to prevent duplicate entries.
          continue;
        }
        // Set grants based on an integer-equivalent of the role ID.
        $grants[] = [
          'realm' => 'utexas_node_access_by_role',
          'gid' => crc32($value['target_id']),
          'grant_view' => 1,
          'grant_update' => 0,
          'grant_delete' => 0,
          'langcode' => 'en',
        ];
      }
    }
    return $grants;
  }

  /**
   * Implements hook_node_grants().
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, $op) {
    $rids = [];
    // Send a list of the current user's roles to the system so they can be
    // compared to the node's allowed roles in hook_node_records(), above.
    foreach ($account->getRoles() as $role) {
      // Create a unique, constant integer representing the role machine name.
      $rids[] = crc32($role);
    }
    if ($op == 'view') {
      // This is simply a list of the *current* user's roles.
      // Checking happens via hook_node_records.
      $grants['utexas_node_access_by_role'] = $rids;
      return $grants;
    }
  }

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type) {
    // Register a new base field for all entity types.
    return \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(EntityTypeInfo::class)
      ->entityBaseFieldInfo($entity_type);
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Modify node forms in Drupal\utexas_node_access_by_role\Form.
    \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(NodeFormAlterations::class)
      ->nodeFormAlter($form, $form_state, $form_id);
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_node_type_edit_form_alter')]
  public function formNodeTypeEditFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Modify node type *edit* forms in Drupal\utexas_node_access_by_role\Form.
    \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(NodeFormAlterations::class)
      ->nodeTypeFormAlter($form, $form_state, $form_id);
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_node_type_add_form_alter')]
  public function formNodeTypeAddFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    // Modify node type *add* forms in Drupal\utexas_node_access_by_role\Form.
    \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(NodeFormAlterations::class)
      ->nodeTypeFormAlter($form, $form_state, $form_id);
  }

  /**
   * Implements hook_link_alter().
   *
   * See Drupal\utexas\Hook\Hooks::linkAlter().
   */
  #[Hook('link_alter')]
  public function linkAlter(&$variables) {
    /** @var \Drupal\utexas_node_access_by_role\MenuLinkTreeManipulator $menu_link_manipulator */
    $menu_link_manipulator = \Drupal::service('menu.default_tree_manipulators');

    if ($menu_link_manipulator->isRoleRestrictedNode($variables['url'])) {
      if (isset($variables['options']['attributes']['class']) && !is_array($variables['options']['attributes']['class'])) {
        // Avoid casting to a class as a string,
        // such as in redirect/-/blob/8.x-1.x/redirect.module#L375.
        $variables['options']['attributes']['class'] = explode(',', $variables['options']['attributes']['class']);
      }
      if (!isset($variables['options']['attributes']['class'])) {
        $variables['options']['attributes']['class'] = [];
      }
      // Remove the 'access-protected' class.
      $variables['options']['attributes']['class'] = array_diff($variables['options']['attributes']['class'], ['access-protected']);
      // Add the lock icon.
      $variables['options']['attributes']['class'][] = 'ut-cta-link--lock';
      $variables['options']['attributes']['class'][] = 'ut-link';
    }
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity) {
    if ($entity instanceof RoleInterface) {
      DeprecationHelper::backwardsCompatibleCall(
        currentVersion: \Drupal::VERSION,
        deprecatedVersion: '11.4',
        currentCallable: fn() => \Drupal::service(NodeAccessRebuild::class)->rebuild(),
        deprecatedCallable: fn() => node_access_rebuild(),
      );
    }
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity) {
    if ($entity instanceof RoleInterface) {
      if ($entity instanceof RoleInterface) {
        DeprecationHelper::backwardsCompatibleCall(
          currentVersion: \Drupal::VERSION,
          deprecatedVersion: '11.4',
          currentCallable: fn() => \Drupal::service(NodeAccessRebuild::class)->rebuild(),
          deprecatedCallable: fn() => node_access_rebuild(),
        );
      }
    }
  }

}
