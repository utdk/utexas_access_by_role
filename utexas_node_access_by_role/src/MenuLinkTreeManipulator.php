<?php

namespace Drupal\utexas_node_access_by_role;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;

/**
 * Defines the access control handler for the menu item.
 */
class MenuLinkTreeManipulator extends DefaultMenuLinkTreeManipulators {

  /**
   * Alter display of nodes that use by-role access AND are published.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $instance
   *   The menu link instance.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  protected function menuLinkCheckAccess(MenuLinkInterface $instance) {
    $access_result = parent::menuLinkCheckAccess($instance);
    if ($this->isRoleRestrictedNode($instance->getUrlObject())) {
      $access_result = AccessResult::allowed();
    }
    return $access_result->cachePerPermissions();
  }

  /**
   * Check if the URL has a current role-based access restriction.
   *
   * @param \Drupal\Core\Url $url
   *   A Drupal URL object.
   *
   * @return bool
   *   Whether or not it has a current role-based access restriction.
   */
  public function isRoleRestrictedNode($url) {
    if (!$url || !$url->isRouted()) {
      return FALSE;
    }
    if ($url->getRouteName() === 'entity.node.canonical') {
      $parameters = $url->getRouteParameters();
      // Dependency injection is more complicated code than static calls
      // and therefore has a negative Developer Experience (DX) for our team.
      // We mark these PHPCS standards as ignored.
      // phpcs:ignore
      $query = \Drupal::database()->select('node_field_data', 'n');
      $query->fields('n', ['nid']);
      $query->condition('n.nid', $parameters['node']);
      $query->condition('n.utexas_node_access_by_role_enable', 1);
      $query->condition('n.status', '1');
      $result = $query->countQuery()->execute()->fetchField();
      if ($result > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
