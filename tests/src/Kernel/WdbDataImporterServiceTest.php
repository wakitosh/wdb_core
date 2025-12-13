<?php

namespace Drupal\Tests\wdb_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\wdb_core\Entity\WdbAnnotationPage;

/**
 * Kernel tests for WdbDataImporterService.
 *
 * @coversDefaultClass \Drupal\wdb_core\Service\WdbDataImporterService
 * @group wdb_core
 * @category Tests
 * @package wdb_core
 * @license GPL-2.0-or-later
 * @link https://www.drupal.org/project/drupal
 */
class WdbDataImporterServiceTest extends KernelTestBase {

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  public static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'file',
    'wdb_core',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\wdb_core\Service\WdbDataImporterService
   */
  protected $importer;

  /**
   * The entity type manager.
   *
   * This property is provided by the parent KernelTestBase class after setUp.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install the database schemas for required entities.
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('wdb_source');
    $this->installEntitySchema('wdb_annotation_page');
    $this->installEntitySchema('wdb_label');
    $this->installEntitySchema('wdb_sign');
    $this->installEntitySchema('wdb_sign_function');
    $this->installEntitySchema('wdb_sign_interpretation');
    $this->installEntitySchema('wdb_word');
    $this->installEntitySchema('wdb_word_meaning');
    $this->installEntitySchema('wdb_word_unit');
    $this->installEntitySchema('wdb_word_map');

    // Create vocabularies used by the importer.
    Vocabulary::create(['vid' => 'lexical_category', 'name' => 'Lexical Category'])->save();
    Vocabulary::create(['vid' => 'verbal_form', 'name' => 'Verbal Form'])->save();
    Vocabulary::create(['vid' => 'gender', 'name' => 'Gender'])->save();
    Vocabulary::create(['vid' => 'number', 'name' => 'Number'])->save();
    Vocabulary::create(['vid' => 'person', 'name' => 'Person'])->save();
    Vocabulary::create(['vid' => 'voice', 'name' => 'Voice'])->save();
    Vocabulary::create(['vid' => 'aspect', 'name' => 'Aspect'])->save();
    Vocabulary::create(['vid' => 'mood', 'name' => 'Mood'])->save();
    Vocabulary::create(['vid' => 'grammatical_case', 'name' => 'Case'])->save();

    // Get the services from the container.
    $this->importer = $this->container->get('wdb_core.data_importer');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
  }

  /**
   * Tests the processImportRow() method.
   *
   * @covers ::processImportRow
   */
  public function testProcessImportRow() {
    // 1. Prepare prerequisite entities.
    $source = $this->createWdbSource('test_source_01');
    $page = $this->createWdbAnnotationPage($source, 1);
    $this->createWdbLabel($page, '1-1');

    // 2. Define a sample row of data from a TSV file.
    $rowData = [
      'source' => 'test_source_01',
      'page' => '1',
      'labelname' => '1-1',
      'image_identifier' => 'test/image.jpg',
      'sign' => 'い',
      'function' => 'phonogram',
      'phone' => 'i',
      'note' => 'Test note',
      'word_unit' => '1',
      'basic_form' => 'いづれ',
      'realized_form' => 'いつれ',
      'lexical_category_name' => 'demonstrative',
      'meaning' => '1',
      'explanation' => 'which',
    ];

    // 3. Define the initial batch context, ensuring all keys are set.
    $context = [
      'results' => [
        'created' => 0,
        'failed' => 0,
        'errors' => [],
        'warnings' => [],
        'created_entities' => [],
      ],
    ];

    // 4. Call the method being tested.
    $success = $this->importer->processImportRow($rowData, 'ja', 1.0, 1.0, $context);

    // 5. Assert that the operation was successful.
    $this->assertTrue($success);
    $this->assertEquals(1, $context['results']['created']);

    // Verify the page image identifier is persisted from TSV.
    $pages = $this->entityTypeManager->getStorage('wdb_annotation_page')->loadByProperties([
      'source_ref' => $source->id(),
      'page_number' => 1,
    ]);
    $this->assertNotEmpty($pages);
    $page_after = reset($pages);
    $this->assertInstanceOf(WdbAnnotationPage::class, $page_after);
    $this->assertEquals('test/image.jpg', $page_after->get('image_identifier')->value);

    // 6. Verify that the entities were created correctly in the database.
    $storage = $this->entityTypeManager->getStorage('wdb_word_unit');
    $word_units = $storage->loadByProperties(['realized_form' => 'いつれ']);
    $this->assertCount(1, $word_units, 'A new word unit was created.');

    /**
* @var \Drupal\wdb_core\Entity\WdbWordUnit $word_unit
*/
    $word_unit = reset($word_units);
    $this->assertEquals($source->id(), $word_unit->get('source_ref')->target_id);

    // Verify the word meaning and word were created and linked correctly.
    $word_meaning = $word_unit->get('word_meaning_ref')->entity;
    $this->assertNotNull($word_meaning);
    $this->assertEquals('which', $word_meaning->get('explanation')->value);

    $word = $word_meaning->get('word_ref')->entity;
    $this->assertNotNull($word);
    $this->assertEquals('いづれ', $word->get('basic_form')->value);
  }

  /**
   * Verify idempotency: re-importing the same row does not duplicate entities.
   *
   * @covers ::processImportRow
   */
  public function testReimportIsIdempotent() : void {
    $source = $this->createWdbSource('test_source_idem');
    $page = $this->createWdbAnnotationPage($source, 3);
    $this->createWdbLabel($page, '3-1');

    $rowData = [
      'source' => 'test_source_idem',
      'page' => '3',
      'labelname' => '3-1',
      'sign' => 'あ',
      'function' => 'phonogram',
      'phone' => 'a',
      'note' => 'note',
      'word_unit' => '10',
      'basic_form' => 'ある',
      'realized_form' => 'ある',
      'lexical_category_name' => 'verb',
      'meaning' => '1',
      'explanation' => 'to exist',
    ];

    $ctx1 = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $ok1 = $this->importer->processImportRow($rowData, 'ja', 1.0, 1.0, $ctx1);
    $this->assertTrue($ok1);

    $ctx2 = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $ok2 = $this->importer->processImportRow($rowData, 'ja', 1.0, 1.0, $ctx2);
    $this->assertTrue($ok2);

    // Counts remain 1 for key entity types.
    $this->assertCount(1, $this->entityTypeManager->getStorage('wdb_word')->loadByProperties(['basic_form' => 'ある']));
    $word_meanings = $this->entityTypeManager->getStorage('wdb_word_meaning')->loadByProperties(['meaning_identifier' => 1]);
    $this->assertCount(1, $word_meanings);
    $signs = $this->entityTypeManager->getStorage('wdb_sign')->loadByProperties(['sign_code' => 'あ']);
    $this->assertCount(1, $signs);
    $sign = reset($signs);
    $functions = $this->entityTypeManager->getStorage('wdb_sign_function')->loadByProperties(
          [
            'sign_ref' => $sign->id(),
            'function_name' => 'phonogram',
          ]
      );
    $this->assertCount(1, $functions);
    $sis = $this->entityTypeManager->getStorage('wdb_sign_interpretation')->loadByProperties(['phone' => 'a']);
    $this->assertCount(1, $sis);
    $wus = $this->entityTypeManager->getStorage('wdb_word_unit')->loadByProperties(['realized_form' => 'ある']);
    $this->assertCount(1, $wus);
    $wu = reset($wus);
    $maps = $this->entityTypeManager->getStorage('wdb_word_map')->loadByProperties(['word_unit_ref' => $wu->id()]);
    $this->assertCount(1, $maps);
  }

  /**
   * Missing required data should fail fast and record an error.
   *
   * @covers ::processImportRow
   */
  public function testMissingRequiredDataFails(): void {
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $row = [
      // Missing source, sign, basic_form.
      'page' => '1',
      'word_unit' => '1',
    ];
    $ok = $this->importer->processImportRow($row, 'ja', 1.0, 1.0, $ctx);
    $this->assertFalse($ok);
    $this->assertSame(1, $ctx['results']['failed']);
    $this->assertNotEmpty($ctx['results']['errors']);
  }

  /**
   * Label not found should add a warning and still import without label link.
   *
   * @covers ::processImportRow
   */
  public function testMissingLabelAddsWarningAndImports(): void {
    $source = $this->createWdbSource('test_source_nolabel');
    // Do not create any label intentionally.
    $this->createWdbAnnotationPage($source, 5);

    $row = [
      'source' => 'test_source_nolabel',
      'page' => '5',
      // Not existing label.
      'labelname' => '5-99',
      'sign' => 'う',
      'function' => 'phonogram',
      'phone' => 'u',
      'note' => '',
      'word_unit' => '2',
      'basic_form' => 'うみ',
      'realized_form' => 'うみ',
      'lexical_category_name' => 'noun',
      'meaning' => '1',
      'explanation' => 'sea',
    ];
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $ok = $this->importer->processImportRow($row, 'ja', 1.0, 1.0, $ctx);
    $this->assertTrue($ok);
    $this->assertNotEmpty($ctx['results']['warnings']);

    // Ensure SI has no label_ref.
    $sis = $this->entityTypeManager->getStorage('wdb_sign_interpretation')->loadByProperties(['phone' => 'u']);
    $this->assertCount(1, $sis);
    /**
* @var \Drupal\wdb_core\Entity\WdbSignInterpretation $si
*/
    $si = reset($sis);
    $this->assertTrue($si->get('label_ref')->isEmpty());
  }

  /**
   * If page does not exist, it should be created automatically.
   *
   * @covers ::processImportRow
   */
  public function testCreatesPageIfMissing(): void {
    $source = $this->createWdbSource('test_source_newpage');
    // No page created beforehand.
    $row = [
      'source' => 'test_source_newpage',
      'page' => '7',
      'labelname' => '',
      'sign' => 'え',
      'function' => '',
      'phone' => 'e',
      'note' => '',
      'word_unit' => '3',
      'basic_form' => 'えき',
      'realized_form' => 'えき',
      'lexical_category_name' => 'noun',
      'meaning' => '1',
      'explanation' => 'station',
    ];
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $ok = $this->importer->processImportRow($row, 'ja', 1.0, 1.0, $ctx);
    $this->assertTrue($ok);

    // Verify page exists with page_number 7 for this source.
    $pages = $this->entityTypeManager->getStorage('wdb_annotation_page')->loadByProperties(
          [
            'source_ref' => $source->id(),
            'page_number' => 7,
          ]
      );
    $this->assertCount(1, $pages);
  }

  /**
   * Grammar terms should be created and WordUnit should reference them.
   *
   * @covers ::processImportRow
   */
  public function testCreatesGrammarTermsAndSetsRefs(): void {
    $source = $this->createWdbSource('test_source_grammar');
    $this->createWdbAnnotationPage($source, 9);
    $row = [
      'source' => 'test_source_grammar',
      'page' => '9',
      // Not created; fine.
      'labelname' => '9-1',
      'sign' => 'お',
      'function' => 'phonogram',
      'phone' => 'o',
      'note' => '',
      'word_unit' => '4',
      'basic_form' => 'おとこ',
      'realized_form' => 'おとこ',
      'lexical_category_name' => 'noun',
      'meaning' => '1',
      'explanation' => 'man',
      'verbal_form_name' => 'finite',
      'gender_name' => 'masculine',
      'number_name' => 'plural',
      'person_name' => 'third',
      'voice_name' => 'active',
      'aspect_name' => 'perfective',
      'mood_name' => 'indicative',
      'grammatical_case_name' => 'nominative',
    ];
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $ok = $this->importer->processImportRow($row, 'ja', 1.0, 1.0, $ctx);
    $this->assertTrue($ok);

    $wus = $this->entityTypeManager->getStorage('wdb_word_unit')->loadByProperties(['realized_form' => 'おとこ']);
    $this->assertCount(1, $wus);
    /**
* @var \Drupal\wdb_core\Entity\WdbWordUnit $wu
*/
    $wu = reset($wus);

    $this->assertSame($this->getTermId('gender', 'masculine'), (int) $wu->get('gender_ref')->target_id);
    $this->assertSame($this->getTermId('number', 'plural'), (int) $wu->get('number_ref')->target_id);
    $this->assertSame($this->getTermId('person', 'third'), (int) $wu->get('person_ref')->target_id);
    $this->assertSame($this->getTermId('voice', 'active'), (int) $wu->get('voice_ref')->target_id);
    $this->assertSame($this->getTermId('aspect', 'perfective'), (int) $wu->get('aspect_ref')->target_id);
    $this->assertSame($this->getTermId('mood', 'indicative'), (int) $wu->get('mood_ref')->target_id);
    $this->assertSame($this->getTermId('grammatical_case', 'nominative'), (int) $wu->get('grammatical_case_ref')->target_id);
    // verbal_form may or may not exist on noun; if present, assert.
    if (!$wu->get('verbal_form_ref')->isEmpty()) {
      $this->assertSame($this->getTermId('verbal_form', 'finite'), (int) $wu->get('verbal_form_ref')->target_id);
    }
  }

  /**
   * Importing the same WordUnit on another page should append page refs.
   *
   * @covers ::processImportRow
   */
  public function testAppendsPageRefsOnExistingWordUnit(): void {
    $source = $this->createWdbSource('test_source_pages');
    // First import on page 1.
    $this->createWdbAnnotationPage($source, 1);
    $row1 = [
      'source' => 'test_source_pages',
      'page' => '1',
      'labelname' => '1-1',
      'sign' => 'か',
      'function' => 'phonogram',
      'phone' => 'ka',
      'note' => '',
      'word_unit' => '42',
      'basic_form' => 'かみ',
      'realized_form' => 'かみ',
      'lexical_category_name' => 'noun',
      'meaning' => '1',
      'explanation' => 'paper',
    ];
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $this->importer->processImportRow($row1, 'ja', 1.0, 1.0, $ctx);

    // Second import on page 2 with same word_unit id.
    $row2 = $row1;
    $row2['page'] = '2';
    $row2['labelname'] = '2-1';
    $this->importer->processImportRow($row2, 'ja', 1.0, 2.0, $ctx);

    $wus = $this->entityTypeManager->getStorage('wdb_word_unit')->loadByProperties(['realized_form' => 'かみ']);
    $this->assertCount(1, $wus);
    /**
* @var \Drupal\wdb_core\Entity\WdbWordUnit $wu
*/
    $wu = reset($wus);
    $page_refs = array_column($wu->get('annotation_page_refs')->getValue(), 'target_id');
    sort($page_refs);
    $this->assertCount(2, $page_refs);
  }

  /**
   * Blank sign function name should reuse the same WdbSignFunction entity.
   *
   * @covers ::processImportRow
   */
  public function testBlankSignFunctionReused(): void {
    $source = $this->createWdbSource('test_source_blankfn');
    $this->createWdbAnnotationPage($source, 11);
    $row = [
      'source' => 'test_source_blankfn',
      'page' => '11',
      'labelname' => '',
      'sign' => 'き',
      'function' => '',
      'phone' => 'ki',
      'note' => '',
      'word_unit' => '7',
      'basic_form' => 'き',
      'realized_form' => 'き',
      'lexical_category_name' => 'noun',
      'meaning' => '1',
      'explanation' => 'ki',
    ];
    $ctx = ['results' => ['created' => 0, 'failed' => 0, 'errors' => [], 'warnings' => [], 'created_entities' => []]];
    $this->importer->processImportRow($row, 'ja', 1.0, 1.0, $ctx);
    $this->importer->processImportRow($row, 'ja', 1.0, 2.0, $ctx);

    $signs = $this->entityTypeManager->getStorage('wdb_sign')->loadByProperties(['sign_code' => 'き']);
    $this->assertCount(1, $signs);
    $sign = reset($signs);
    $functions = $this->entityTypeManager->getStorage('wdb_sign_function')->loadByProperties(['sign_ref' => $sign->id()]);
    $this->assertCount(1, $functions, 'Blank function_name should be reused, not duplicated.');
  }

  /**
   * Helper methods to create entities for the test.
   */

  /**
   * Create a WdbSource entity for testing.
   *
   * @param string $id
   *   The source identifier to set.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created source entity.
   */
  protected function createWdbSource(string $id = 'test_source_01') {
    $storage = $this->entityTypeManager->getStorage('wdb_source');
    $source = $storage->create(
          [
            'source_identifier' => $id,
            'displayname' => 'Test Source',
          ]
      );
    $source->save();
    return $source;
  }

  /**
   * Create a WdbAnnotationPage referencing the given source.
   *
   * @param \Drupal\Core\Entity\EntityInterface $source
   *   The source entity to reference.
   * @param int $pageNumber
   *   The page number.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created page entity.
   */
  protected function createWdbAnnotationPage($source, int $pageNumber = 1) {
    $storage = $this->entityTypeManager->getStorage('wdb_annotation_page');
    $page = $storage->create(
          [
            'source_ref' => $source->id(),
            'page_number' => $pageNumber,
          ]
      );
    $page->save();
    return $page;
  }

  /**
   * Create a WdbLabel on the given page.
   *
   * @param \Drupal\Core\Entity\EntityInterface $page
   *   The page entity.
   * @param string $labelName
   *   The label name.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created label entity.
   */
  protected function createWdbLabel($page, string $labelName = '1-1') {
    $storage = $this->entityTypeManager->getStorage('wdb_label');
    $label = $storage->create(
          [
            'annotation_page_ref' => $page->id(),
            'label_name' => $labelName,
          ]
      );
    $label->save();
    return $label;
  }

  /**
   * Helper to fetch term id for vid/name, asserting existence.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param string $name
   *   The term name.
   *
   * @return int
   *   The term entity ID.
   */
  protected function getTermId(string $vid, string $name): int {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(
          [
            'vid' => $vid,
            'name' => $name,
          ]
      );
    $this->assertCount(1, $terms, "Term for $vid:$name should be created");
    $term = reset($terms);
    return (int) $term->id();
  }

}
