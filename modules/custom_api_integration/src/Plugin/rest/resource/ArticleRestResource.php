<?php

namespace Drupal\custom_api_integration\Plugin\rest\resource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\ResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides a REST API for the Article content type.
 *
 * @RestResource(
 *   id = "custom_api_integration",
 *   label = @Translation("Custom Article REST"),
 *   uri_paths = {
 *     "canonical" = "/api/articles",
 *     "create" = "/api/articles"
 *   }
 * )
 */
class ArticleRestResource extends ResourceBase {

  protected $entityTypeManager;
  protected $currentUser;

  /**
   * Constructs the ArticleResource object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,

    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition,
    $serializer_formats, $logger
  );
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->getParameter('serializer.formats'), $container->get('logger.factory')
        ->get('rest'), $container->get('entity_type.manager'), $container->get('current_user'));
  }


  /**
   * GET: Fetch articles or a single article.
   */
  public function get(Request $request) {
    $storage = $this->entityTypeManager->getStorage('node');
    $id = $request->query->get('id');
    if ($id) {
      $node = $storage->load($id);
      if (!$node || $node->bundle() !== 'article') {
        throw new NotFoundHttpException('Article not found');
      }

      $response = new ResourceResponse($this->formatNode($node));
      $response->getCacheableMetadata()
      ->setCacheContexts(['url.query_args'])->addCacheTags(['node:' . $id]);
     
    } else {
      $query = $storage->getQuery()
        ->condition('status', 1)
        ->condition('type', 'article')
        ->sort('created', 'DESC')
        ->accessCheck(TRUE);

      $nids = $query->execute();
      $nodes = $storage->loadMultiple($nids);

      $articles = array_map([$this, 'formatNode'], $nodes);
      $response = new ResourceResponse($articles);
      $response->getCacheableMetadata()
      ->setCacheContexts(['url.query_args'])
      ->addCacheTags(['node_list']);
    }

    return $response;
  }
/**
 * POST: Create an article with paragraphs.
 */
/**
 * POST: Create an article with an address as a paragraph field.
 */
public function post(Request $request) {
  $request_data = json_decode($request->getContent(), TRUE);
  $id = $request->query->get('id');
  
  if (!isset($request_data['data'])) {
    throw new BadRequestHttpException("Invalid JSON format. Data should be wrapped inside a 'data' key.");
  }

  $data = $request_data['data']; // Extract nested data

  // Create the Article node
  $storage = $this->entityTypeManager->getStorage('node');

  $node = $storage->create([
    'type' => 'article',
    'title' => $data['title'] ?? 'Untitled',
    'body' => ['value' => $data['body'] ?? '', 'format' => 'full_html'],
    'status' => 1,
    'uid' => $this->currentUser->id(),
    'field_name' => $data['name'] ?? '', // Add the new "name" field
  ]);

  // Ensure we have address data in the request
  $address_data = isset($data['address']) ? $data['address'] : null;

  if ($address_data) {
    // Create the address paragraph
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    
    $address_paragraph = $paragraph_storage->create([
      'type' => 'address', // The paragraph type for address (adjust this if needed)
      'field_colony' => $address_data['field_colony'] ?? '',
      'field_house_no' => $address_data['field_house_no'] ?? '',
    ]);

    // Save the address paragraph
    $address_paragraph->save();

    // Attach the address paragraph to the article node
    $node->set('field_address', [
      'target_id' => $address_paragraph->id(),
    ]);
  }

  // Save the Article node
  $node->save();

  return new ModifiedResourceResponse($this->formatNode($node), 201);
}


  /**
   * PUT: Replace an article.
   */
  public function put(Request $request) {
    // Decode JSON request body
    $request_data = json_decode($request->getContent(), TRUE);
    $id = $request->query->get('id');
    if (!isset($request_data['data'])) {
      throw new BadRequestHttpException("Invalid JSON format. Data should be wrapped inside a 'data' key.");
    }

    $data = $request_data['data']; // Extract nested data

    // Load the article
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($id);

    if (!$node || $node->bundle() !== 'article') {
      throw new NotFoundHttpException("Article with ID $id not found.");
    }

    // Update fields if provided
    if (!empty($data['title'])) {
      $node->title->value = $data['title'];
    }
    if (!empty($data['body']['value'])) {
      $node->body->value = $data['body']['value'];
    }
    if (!empty($data['name'])) {
      $node->set('field_name', $data['name']);
    }
    
    $node->save();

    return new ResourceResponse([
      'message' => "Article updated successfully.",
      'nid' => $node->id(),
      'title' => $node->title->value,
      'body' => $node->body->value,
      'name' => $node->get('field_name')->value,
    ]);
  }


  /**
   * PATCH: Partially update an article.
   */
  /**
 * PATCH: Partially update an existing article.
 */
public function patch(Request $request) {
  // Decode JSON request body
  $request_data = json_decode($request->getContent(), TRUE);
  $id = $request->query->get('id');
  if (!isset($request_data['data'])) {
    throw new BadRequestHttpException("Invalid JSON format. Data should be wrapped inside a 'data' key.");
  }

  $data = $request_data['data']; // Extract nested data

  // Load the article
  $storage = $this->entityTypeManager->getStorage('node');
  $node = $storage->load($id);

  if (!$node || $node->bundle() !== 'article') {
    throw new NotFoundHttpException("Article with ID $id not found.");
  }

  // Update only provided fields
  if (!empty($data['title'])) {
    $node->title->value = $data['title'];
  }
  if (!empty($data['body']['value'])) {
    $node->body->value = $data['body']['value'];
  }
  if (!empty($data['name'])) {
    $node->set('field_name', $data['name']);
  }

  $node->save();

  return new ResourceResponse([
    'message' => "Article updated successfully.",
    'nid' => $node->id(),
    'title' => $node->title->value,
    'body' => $node->body->value,
    'name' => $node->get('field_name')->value,
  ]);
}

  /**
   * DELETE: Remove an article.
   */
  public function delete(Request $request) {
    $id = $request->query->get('id');
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($id);

    if (!$node || $node->bundle() !== 'article') {
      throw new NotFoundHttpException('Article not found');
    }

    $node->delete();
    return new ResourceResponse(['message' => 'Article deleted'], 204);
  }

  /**
   * Helper: Format node for response.
   */
  protected function formatNode($node) {
    // Get the article data
    $node_data = [
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'body' => $node->get('body')->value,
      'name' => $node->get('field_name')->value, // Include name in response
      'created' => $node->getCreatedTime(),
    ];
  
    // Get the address paragraph field if it exists
    $address_paragraph = $node->get('field_address')->entity;
  
    if ($address_paragraph) {
      // Extract data from the address paragraph fields
      $node_data['address'] = [
        'field_colony' => $address_paragraph->get('field_colony')->value,
        'field_house_no' => $address_paragraph->get('field_house_no')->value,
      ];
    }
  
    return $node_data;
  }
  
}
