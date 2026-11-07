<?php

namespace Drupal\Tests\wdb_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\wdb_core\Entity\WdbAnnotationPage;
use Drupal\wdb_core\Entity\WdbLabel;
use Drupal\wdb_core\Entity\WdbSource;
use Drupal\wdb_core\Entity\WdbWord;
use Drupal\wdb_core\Entity\WdbWordMap;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that sample data is created once and never overwritten or removed.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class WdbExampleInstallTest extends KernelTestBase {

  private const SAMPLE_COUNTS = [
    'wdb_source' => 1,
    'wdb_annotation_page' => 3,
    'wdb_label' => 8,
    'wdb_sign_interpretation' => 8,
    'wdb_word_unit' => 6,
    'wdb_word_map' => 8,
    'wdb_word_meaning' => 6,
    'wdb_word' => 6,
    'wdb_sign_function' => 8,
    'wdb_sign' => 8,
  ];

  protected static $modules = ['system', 'user', 'field', 'text', 'taxonomy', 'file', 'options', 'entity', 'flexible_permissions', 'group', 'wdb_core', 'wdb_example'];

  protected function setUp(): void {
    parent::setUp();
    foreach (array_merge(['user', 'taxonomy_term', 'group', 'group_relationship', 'group_config_wrapper'], array_keys(self::SAMPLE_COUNTS)) as $type) {
      $this->installEntitySchema($type);
    }
    Vocabulary::create(['vid' => 'subsystem', 'name' => 'Subsystem'])->save();
    Vocabulary::create(['vid' => 'lexical_category', 'name' => 'Lexical category'])->save();
    foreach (['demonstrative', 'adnominal case marker', 'prefix', 'common noun', 'case marker', 'binding particle'] as $name) {
      Term::create(['vid' => 'lexical_category', 'name' => $name])->save();
    }
    $this->container->get('router.builder')->rebuild();
    $this->container->get('module_handler')->loadInclude('wdb_example', 'install');
  }

  /**
   * Counts every entity type the sample uses.
   */
  private function countEntities(): array {
    $counts = [];
    foreach (array_keys(self::SAMPLE_COUNTS) as $type) {
      $counts[$type] = (int) $this->container->get('entity_type.manager')->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
    }
    return $counts;
  }

  /**
   * Returns the config name of the sample subsystem.
   */
  private function subsystemConfigName(): string {
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['vid' => 'subsystem', 'name' => 'genji']);
    return 'wdb_core.subsystem.' . reset($terms)->id();
  }

  /**
   * Loads a page of the sample source.
   */
  private function loadPage(int $number): WdbAnnotationPage {
    $pages = $this->container->get('entity_type.manager')->getStorage('wdb_annotation_page')->loadByProperties(['page_number' => $number]);
    return reset($pages);
  }

  public function testFirstInstallCreatesTheSample(): void {
    wdb_example_install();

    $this->assertSame(self::SAMPLE_COUNTS, $this->countEntities());
    $this->assertSame('サンプル：保坂本源氏物語', $this->config($this->subsystemConfigName())->get('display_title'));
    $this->assertSame('100412001000-003.tif', $this->loadPage(3)->get('image_identifier')->value);
    foreach (WdbLabel::loadMultiple() as $label) {
      $this->assertNotEmpty($label->get('annotation_uri')->value);
    }
  }

  public function testReinstallKeepsEditsAndDeletionsAndLaterData(): void {
    wdb_example_install();

    $config_name = $this->subsystemConfigName();
    $this->config($config_name)->set('display_title', 'Edited title')->set('allowAnonymous', FALSE)->save();
    $page = $this->loadPage(3);
    $page->set('page_name', 'Edited page')->save();
    $sources = WdbSource::loadMultiple();
    reset($sources)->set('displayname', 'Edited source')->save();
    $maps = WdbWordMap::loadMultiple();
    reset($maps)->delete();
    WdbLabel::create(['label_name' => 'later label', 'annotation_page_ref' => $page->id(), 'polygon_points' => ['1,1', '20,1', '20,20']])->save();
    $expected = $this->countEntities();

    wdb_example_install();

    $this->assertSame($expected, $this->countEntities());
    $config = $this->config($config_name);
    $this->assertSame('Edited title', $config->get('display_title'));
    $this->assertFalse($config->get('allowAnonymous'));
    $this->assertSame('Edited page', $this->loadPage(3)->get('page_name')->value);
    $sources = WdbSource::loadMultiple();
    $this->assertSame('Edited source', reset($sources)->get('displayname')->value);
  }

  public function testExistingSubsystemBlocksTheInstall(): void {
    $term = Term::create(['vid' => 'subsystem', 'name' => 'genji']);
    $term->save();
    $config_name = 'wdb_core.subsystem.' . $term->id();
    $this->config($config_name)->set('display_title', 'My own title')->save();

    wdb_example_install();

    $this->assertSame(array_fill_keys(array_keys(self::SAMPLE_COUNTS), 0), $this->countEntities());
    $this->assertSame('My own title', $this->config($config_name)->get('display_title'));
  }

  public function testExistingSourceBlocksTheInstall(): void {
    $term = Term::create(['vid' => 'subsystem', 'name' => 'other']);
    $term->save();
    WdbSource::create(['source_identifier' => 'nich-100412001', 'displayname' => 'Own source', 'subsystem_tags' => [$term->id()]])->save();

    wdb_example_install();

    $this->assertEmpty($this->container->get('entity_type.manager')->getStorage('taxonomy_term')->loadByProperties(['vid' => 'subsystem', 'name' => 'genji']));
    $sources = WdbSource::loadMultiple();
    $this->assertCount(1, $sources);
    $this->assertSame('Own source', reset($sources)->get('displayname')->value);
  }

  public function testSharedDictionaryEntriesAreReusedAsTheyAre(): void {
    $category = Term::create(['vid' => 'lexical_category', 'name' => 'own category']);
    $category->save();
    WdbWord::create(['basic_form' => 'の', 'lexical_category_ref' => $category->id()])->save();

    wdb_example_install();

    $words = $this->container->get('entity_type.manager')->getStorage('wdb_word')->loadByProperties(['basic_form' => 'の']);
    $this->assertCount(1, $words);
    $this->assertSame((int) $category->id(), (int) reset($words)->get('lexical_category_ref')->target_id);
  }

  public function testUpdateRemovesTrackingButKeepsTheData(): void {
    wdb_example_install();
    $schema = $this->container->get('database')->schema();
    $schema->createTable('wdb_example_map', [
      'fields' => ['id' => ['type' => 'serial', 'not null' => TRUE]],
      'primary key' => ['id'],
    ]);
    // The setting no longer has a schema, so write it the way 1.5.17 left it.
    $storage = $this->container->get('config.storage');
    $storage->write('wdb_example.settings', ['purge_on_uninstall' => TRUE]);

    wdb_example_update_10001();

    $this->assertFalse($schema->tableExists('wdb_example_map'));
    $this->assertFalse($storage->exists('wdb_example.settings'));
    $this->assertSame(self::SAMPLE_COUNTS, $this->countEntities());

    // A second run, or a site that never had the table, must not fail.
    wdb_example_update_10001();
    $this->assertSame(self::SAMPLE_COUNTS, $this->countEntities());
  }

}
