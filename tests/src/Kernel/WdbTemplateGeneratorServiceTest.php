<?php

namespace Drupal\Tests\wdb_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * @coversDefaultClass \Drupal\wdb_core\Service\WdbTemplateGeneratorService
 * @group wdb_core
 */
class WdbTemplateGeneratorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'system', 'user', 'field', 'text', 'taxonomy', 'file', 'wdb_core',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\wdb_core\Service\WdbTemplateGeneratorService
   */
  private $service;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $etm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
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

    // Vocabularies required by entities and template reconstruction.
    Vocabulary::create(['vid' => 'lexical_category', 'name' => 'Lexical Category'])->save();
    Vocabulary::create(['vid' => 'subsystem', 'name' => 'Subsystem'])->save();

    $this->service = $this->container->get('wdb_core.template_generator');
    $this->etm = $this->container->get('entity_type.manager');
  }

  /**
   * @covers ::generateTemplateFromSource
   */
  public function testGenerateTemplateFromSourceEmpty(): void {
    /** @var \Drupal\wdb_core\Entity\WdbSource $source */
    $source = $this->createEntity('wdb_source', [
      'source_identifier' => 'src_tmpl_empty',
      'displayname' => 'Src',
    ]);
    $tsv = $this->service->generateTemplateFromSource($source);
    $lines = array_filter(explode("\n", $tsv));
    // Header only when no SIs found.
    $this->assertCount(1, $lines);
    $this->assertMatchesRegularExpression('/^source\s+page\s+image_identifier\s+labelname/', $lines[0]);
    $header = preg_split("/\t/", $lines[0]);
    $this->assertSame(['source', 'page', 'image_identifier', 'labelname'], array_slice($header, 0, 4));
  }

  /**
   * @covers ::generateTemplateFromSource
   */
  public function testGenerateTemplateFromSourceWithData(): void {
    /** @var \Drupal\wdb_core\Entity\WdbSource $source */
    $source = $this->createEntity('wdb_source', [
      'source_identifier' => 'src_tmpl',
      'displayname' => 'Src',
    ]);
    $page = $this->createEntity('wdb_annotation_page', [
      'source_ref' => $source->id(),
      'page_number' => 1,
      'image_identifier' => 'img/0001.tif',
    ]);

    $sign = $this->createEntity('wdb_sign', ['sign_code' => 'X']);
    $sf = $this->createEntity('wdb_sign_function', ['sign_ref' => $sign->id(), 'function_name' => 'phonogram']);
    $label = $this->createEntity('wdb_label', [
      'annotation_page_ref' => $page->id(),
      'label_name' => 'L-1',
    ]);
    $si = $this->createEntity('wdb_sign_interpretation', [
      'annotation_page_ref' => $page->id(),
      'label_ref' => $label->id(),
      'sign_function_ref' => $sf->id(),
      'phone' => 'a',
      'note' => 'n',
    ]);

    // Build minimal Word/Meaning/WU so reconstruction can fill columns.
    $term = $this->etm->getStorage('taxonomy_term')->create(['vid' => 'lexical_category', 'name' => 'common noun']);
    $term->save();
    $word = $this->createEntity('wdb_word', [
      'basic_form' => 'base',
      'lexical_category_ref' => $term->id(),
    ]);
    $meaning = $this->createEntity('wdb_word_meaning', [
      'word_ref' => $word->id(),
      'meaning_identifier' => 1,
      'explanation' => 'exp',
    ]);
    $wu = $this->createEntity('wdb_word_unit', [
      'source_ref' => $source->id(),
      'annotation_page_refs' => [$page->id()],
      'word_meaning_ref' => $meaning->id(),
      'realized_form' => 'rf',
      'word_sequence' => 1.0,
    ]);
    $this->createEntity('wdb_word_map', [
      'word_unit_ref' => $wu->id(),
      'sign_interpretation_ref' => $si->id(),
      'sign_sequence' => 1.0,
    ]);

    $tsv = $this->service->generateTemplateFromSource($source);
    $lines = array_values(array_filter(explode("\n", $tsv)));
    $this->assertGreaterThanOrEqual(2, count($lines));
    $this->assertMatchesRegularExpression('/^source\s+page\s+image_identifier\s+labelname/', $lines[0]);
    $this->assertStringContainsString("src_tmpl\t1\timg/0001.tif\tL-1", $lines[1]);
  }

  /**
   * @covers ::generateTemplateFromMecab
   */
  public function testGenerateTemplateFromMecab(): void {
    // Prepare mapping target term for category name resolution.
    Vocabulary::load('lexical_category');
    $term = $this->etm->getStorage('taxonomy_term')->create(['vid' => 'lexical_category', 'name' => 'common noun']);
    $term->save();

    $tmp = tempnam(sys_get_temp_dir(), 'mecab_');
    // realized_form \t 名詞 \t 普通名詞 \t 一般 \t * ... ensure index 8.
    $line = "おとこ\t名詞\t普通名詞\t一般\t*\tX\tX\tX\t男\nEOS\n";
    file_put_contents($tmp, $line);

    $ctx = [];
    $tsv = $this->service->generateTemplateFromMecab($tmp, 'srcid', $ctx);
    @unlink($tmp);

    $lines = array_values(array_filter(explode("\n", $tsv)));
    $this->assertGreaterThanOrEqual(2, count($lines));
    $this->assertMatchesRegularExpression('/^source\s+page\s+image_identifier\s+labelname/', $lines[0]);
    // Row 1 for first character.
    $this->assertStringContainsString("srcid\t\t\t\tお", $lines[1]);
  }

  /**
   * Helper to create and save an entity.
   *
   * @param string $type
   *   Entity type ID.
   * @param array $values
   *   Values.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Saved entity.
   */
  private function createEntity(string $type, array $values) {
    $storage = $this->etm->getStorage($type);
    $e = $storage->create($values);
    $e->save();
    return $e;
  }

}
