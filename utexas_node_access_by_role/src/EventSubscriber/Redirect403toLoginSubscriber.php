<?php

namespace Drupal\utexas_node_access_by_role\EventSubscriber;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\utexas_node_access_by_role\Event\RedirectEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Redirect 403 to User Login event subscriber.
 */
class Redirect403toLoginSubscriber extends HttpExceptionSubscriberBase {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * An event dispatcher instance to use for map events.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * Constructs a new event Subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountInterface $current_user, PathMatcherInterface $path_matcher, EventDispatcherInterface $event_dispatcher, MessengerInterface $messenger, RedirectDestinationInterface $redirect_destination) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->pathMatcher = $path_matcher;
    $this->eventDispatcher = $event_dispatcher;
    $this->messenger = $messenger;
    $this->redirectDestination = $redirect_destination;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats() {
    return ['html'];
  }

  /**
   * Redirects on 403 Access Denied kernel exceptions.
   *
   * @param Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The Event to process.
   */
  public function on403(ExceptionEvent $event) {
    $request = $event->getRequest();
    $currentPath = $request->getPathInfo();
    if (!$this->currentUser->isAnonymous()) {
      return;
    }
    if ($node = $request->attributes->get('node')) {
      if ($node instanceof NodeInterface) {
        if (!$node->hasField('utexas_node_access_by_role_enable')) {
          return;
        }
        $enabled = (bool) $node->get('utexas_node_access_by_role_enable')->getString();
        if (!$enabled) {
          return;
        }
      }
    }
    else {
      // This is not a node, so it's not applicable to this redirect.
      return;
    }
    // Dependency injection is more complicated code than static calls
    // and therefore has a negative Developer Experience (DX) for our team.
    // We mark these PHPCS standards as ignored.
    // phpcs:ignore
    $moduleHandler = \Drupal::service('module_handler');
    $redirectPath = '/user/login';
    if ($moduleHandler->moduleExists('simplesamlphp_auth')) {
      $redirectPath = '/saml_login';
    }
    if ($moduleHandler->moduleExists('samlauth')) {
      $redirectPath = '/saml/login';
    }
    $custom_redirect = $this->configFactory->get('utexas_node_access_by_role.settings')->get('redirect_path');
    // If set in site configuration, override the default redirect behavior.
    if (isset($custom_redirect) && $custom_redirect != '') {
      $redirectPath = $custom_redirect;
    }
    if (!empty($redirectPath)) {
      // Determine if the redirect path is external.
      $externalRedirect = UrlHelper::isExternal($redirectPath);

      // Determine the url options.
      $options = [
        'absolute' => TRUE,
      ];
      // Determine the destination parameter
      // and add it as options for the url build.
      if ($externalRedirect) {
        $destination = Url::fromUserInput($currentPath, [
          'absolute' => TRUE,
        ])->toString();
        if ($queryString = $request->getQueryString()) {
          $destination .= '?' . $queryString;
        }
      }
      else {
        $destination = $this->redirectDestination->get();
      }

      $options['query']['destination'] = $destination;

      // Remove the destination parameter to allow redirection.
      $request->query->remove('destination');

      // Allow to alter the url or options before to redirect.
      $redirectEvent = new RedirectEvent($redirectPath, $options);
      // See Symfony 4.3 change in /drupal/issues/3055194.
      $this->eventDispatcher->dispatch($redirectEvent, RedirectEvent::EVENT_NAME);
      $redirectPath = $redirectEvent->getUrl();
      $options = $redirectEvent->getOptions();

      // Perform the redirection.
      $code = '301';
      if ($externalRedirect) {
        $url = Url::fromUri($redirectPath, $options)->toString();
        $response = new TrustedRedirectResponse($url, $code);
      }
      else {
        $url = Url::fromUserInput($redirectPath, $options)->toString();
        $response = new CacheableRedirectResponse($url, $code);
      }

      // Add caching dependencies so the cache of the redirection will be
      // updated when necessary.
      $cacheMetadata = new CacheableMetadata();
      $cacheMetadata->addCacheTags(['4xx-response']);
      $response->addCacheableDependency($cacheMetadata);
      $event->setResponse($response);
    }
  }

}
