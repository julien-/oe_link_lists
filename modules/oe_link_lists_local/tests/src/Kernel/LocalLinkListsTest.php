<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_link_lists_local\Kernel;

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\oe_link_lists\Entity\LinkList;
use Drupal\oe_link_lists\Entity\LinkListInterface;
use Drupal\views\Views;

/**
 * Kernel tests for the local link lists.
 */
class LocalLinkListsTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'oe_link_lists',
    'oe_link_lists_local',
    'oe_link_lists_local_test',
    'entity_reference_revisions',
    'user',
    'system',
    'node',
    'oe_link_lists_internal_source',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('link_list');
    $this->installConfig(['field', 'node']);
    $this->installConfig([
      'oe_link_lists',
      'system',
      'views',
    ]);
  }

  /**
   * Tests that base field overrides get the third party settings.
   */
  public function testBaseFieldOverride(): void {
    // Create a test node bundle.
    $type = $this->entityTypeManager->getStorage('node_type')->create([
      'name' => 'Test content type',
      'type' => 'test_ct',
    ]);
    $type->save();

    $base_field_definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('node');
    $expected = [
      // The field types should allow the saving of the third party setting.
      'entity_reference_correct' => TRUE,
      'entity_reference_revisions_correct' => TRUE,
      // The field types should not allow the saving of the third party setting.
      'entity_reference_incorrect' => FALSE,
      'entity_reference_revisions_incorrect' => FALSE,
    ];
    foreach ($expected as $field_name => $value) {
      $base_field_definition = $base_field_definitions[$field_name];
      $override = BaseFieldOverride::createFromBaseFieldDefinition($base_field_definition, 'test_ct');
      $override->save();

      $override = BaseFieldOverride::loadByName('node', 'test_ct', $field_name);
      $expected_setting = $value ? ['local' => $value] : [];

      $this->assertEquals($expected_setting, $override->getThirdPartySettings('oe_link_lists_local'), sprintf('The local value of the %s field is as expected', $field_name));
    }
  }

  /**
   * Tests that local link lists are not queryable.
   */
  public function testLocalLinkListQueryAlter(): void {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $link_list_storage */
    $link_list_storage = $this->container->get('entity_type.manager')->getStorage('link_list');
    $values = [
      'bundle' => 'dynamic',
      'title' => 'My link list',
      'administrative_title' => 'Link list 1',
      'local' => NULL,
    ];
    /** @var \Drupal\oe_link_lists\Entity\LinkListInterface $link_list */
    $link_list = $link_list_storage->create($values);
    $link_list->save();

    // Since the "local" field is NULL (legacy link list entities), the query
    // should still find it.
    $query = \Drupal::entityQuery('link_list')
      ->condition('title', 'My link list')
      ->accessCheck(FALSE);
    $this->assertCount(1, $query->execute());
    // The load multiple emulates a database query as oppossed to an entity
    // query.
    $this->assertCount(1, $link_list_storage->loadMultiple());

    $link_list->set('local', 0);
    $link_list->save();
    // Again, we should find it because it's not a local one, marked
    // specifically.
    $query = \Drupal::entityQuery('link_list')
      ->condition('title', 'My link list')
      ->accessCheck(FALSE);
    $this->assertCount(1, $query->execute());
    $this->assertCount(1, $link_list_storage->loadMultiple());
    $this->assertCount(1, $link_list_storage->loadMultiple([$link_list->id()]));
    $this->assertCount(1, $link_list_storage->loadMultipleRevisions([$link_list->getRevisionId()]));
    $this->assertInstanceOf(LinkListInterface::class, $link_list_storage->loadRevision($link_list->getRevisionId()));

    $link_list->set('local', 1);
    $link_list->save();
    // Now we should no longer find it in the query, unless we use a specific
    // tag. And unless we load it by ID or revision ID.
    $query = \Drupal::entityQuery('link_list')
      ->condition('title', 'My link list')
      ->accessCheck(FALSE);
    $this->assertCount(0, $query->execute());
    $this->assertCount(0, $link_list_storage->loadMultiple());
    $this->assertInstanceOf(LinkListInterface::class, $link_list_storage->load($link_list->id()));
    $this->assertInstanceOf(LinkListInterface::class, $link_list_storage->loadRevision($link_list->getRevisionId()));

    $query = \Drupal::entityQuery('link_list')
      ->condition('title', 'My link list')
      ->addTag('allow_local_link_lists')
      ->accessCheck(FALSE);
    $this->assertCount(1, $query->execute());
    // If we try to load it by ID, we should be able to.
    $this->assertCount(1, $link_list_storage->loadMultiple([$link_list->id()]));
    $this->assertInstanceOf(LinkListInterface::class, $link_list_storage->load($link_list->id()));
    $this->assertInstanceOf(LinkListInterface::class, $link_list_storage->loadRevision($link_list->getRevisionId()));
  }

  /**
   * Tests that views don't show the local link lists either.
   */
  public function testViewsQueryAlter(): void {
    // Create 3 link lists: two normal ones (one with 0 value in the local
    // field, and the other with NULL) and a local one.
    $configuration = [
      'source' => [
        'plugin' => 'internal',
        'plugin_configuration' => [
          'entity_type' => 'node',
          'bundle' => 'page',
        ],
      ],
      'display' => [
        'plugin' => 'title',
      ],
    ];

    $link_list = LinkList::create([
      'bundle' => 'dynamic',
      'title' => 'The first visible link list',
      'administrative_title' => 'The first visible link list',
    ]);
    $link_list->setConfiguration($configuration);
    $link_list->save();

    $link_list = LinkList::create([
      'bundle' => 'dynamic',
      'title' => 'The second visible link list',
      'administrative_title' => 'The second visible link list',
    ]);
    $link_list->setConfiguration($configuration);
    $link_list->set('local', NULL);
    $link_list->save();

    $link_list = LinkList::create([
      'bundle' => 'dynamic',
      'title' => 'The local link list',
      'administrative_title' => 'The local link list',
    ]);
    $link_list->setConfiguration($configuration);
    $link_list->set('local', TRUE);
    $link_list->save();

    $view = Views::getView('link_lists');
    $view->setDisplay();
    $view->preExecute();
    $view->execute();
    $results = $view->result;
    $this->assertCount(2, $results);
    $titles = [];
    foreach ($results as $row) {
      $entity = $row->_entity;
      $titles[] = $entity->label();
    }

    $this->assertEquals([
      'The first visible link list',
      'The second visible link list',
    ], $titles);
  }

}
