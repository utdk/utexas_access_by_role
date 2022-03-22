<?php

namespace Drupal\utexas_node_access_by_role;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\menu_link_content\Entity\MenuLinkContent;

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
    $url = $instance->getUrlObject();
    if ($url && $url->isRouted()) {
      if ($url->getRouteName() === 'entity.node.canonical') {
        $parameters = $url->getRouteParameters();
        $query = \Drupal::database()->select('node__utexas_node_access_by_role', 'n');
        $query->fields('n', ['entity_id']);
        $query->condition('n.entity_id', $parameters['node']);
        $result = $query->countQuery()->execute()->fetchField();
        if ($result > 0) {
          // The node referenced in this menu link has per role restrictions.
          // Check if it is published.
          $query = \Drupal::database()->select('node_field_data', 'n');
          $query->fields('n', ['nid', 'status']);
          $query->condition('n.nid', $parameters['node']);
          $query->condition('n.status', '1');
          $result = $query->countQuery()->execute()->fetchField();
          if ($result > 0) {
            $access_result = AccessResult::allowed();
          }
        }
      }
    }
    return $access_result->cachePerPermissions();
  }

}
