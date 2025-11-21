<?php

namespace Drupal\Tests\wdb_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\Group;

/**
 * Kernel tests for the DefaultSubsystemAccessPolicy service.
 *
 * @coversDefaultClass \Drupal\wdb_core\Access\DefaultSubsystemAccessPolicy
 * @group wdb_core
 */
class SubsystemAccessPolicyKernelTest extends KernelTestBase {

  /**
   * Subsystem taxonomy term ID.
   *
   * @var int
   */
  protected $subsystemTid;

  /**
   * Subsystem machine name (term name).
   *
   * @var string
   */
  protected $subsystemName;

  /**
   * Modules to enable.
   *
   * We include group and its dependencies plus wdb_core and core essentials.
   *
   * @var string[]
   */
  public static $modules = [
    'system', 'user', 'field', 'text', 'taxonomy', 'file',
    'entity', 'flexible_permissions', 'options', 'group',
    'wdb_core',
  ];

  /**
   * The access policy service under test.
   *
   * @var \Drupal\wdb_core\Access\DefaultSubsystemAccessPolicy
   */
  protected $policy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install required entity schemas for WDB + Group.
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

    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);

    // Subsystem vocabulary.
    Vocabulary::create(['vid' => 'subsystem', 'name' => 'Subsystem'])->save();

    // Create a subsystem term used across tests.
    $term = Term::create(['vid' => 'subsystem', 'name' => 'tb']);
    $term->save();
    $this->subsystemTid = $term->id();
    $this->subsystemName = $term->getName();

    // Access policy service.
    $this->policy = $this->container->get('wdb_core.subsystem_access_policy');
  }

  /**
   * Helper: set config for current subsystem.
   */
  protected function setSubsystemConfig(array $values): void {
    $cfg = $this->config('wdb_core.subsystem.' . $this->subsystemTid);
    foreach ($values as $k => $v) {
      $cfg->set($k, $v);
    }
    $cfg->save();
  }

  /**
   * @covers ::checkAccess
   * Tests anonymous allowed scenario.
   */
  public function testAnonymousAllowed() {
    $this->setSubsystemConfig(['allowAnonymous' => TRUE]);
    $anonymous = $this->container->get('current_user')->getAccount();
    $result = $this->policy->checkAccess($this->subsystemName, $anonymous, ['permission' => 'access content']);
    $this->assertTrue($result->isAllowed(), 'Anonymous users allowed when allowAnonymous is TRUE.');
  }

  /**
   * @covers ::checkAccess
   * Tests permission fallback when anonymous denied (no group restriction).
   */
  public function testPermissionFallbackWithoutGroup() {
    $this->setSubsystemConfig(['allowAnonymous' => FALSE, 'group_uuid' => NULL]);

    $anonymous = $this->container->get('current_user')->getAccount();
    $anonResult = $this->policy->checkAccess($this->subsystemName, $anonymous, ['permission' => 'access content']);
    $this->assertFalse($anonResult->isAllowed(), 'Anonymous denied when allowAnonymous FALSE.');

    // Create authenticated user.
    $member = User::create(['name' => 'perm_user']);
    $member->save();
    $result = $this->policy->checkAccess($this->subsystemName, $member, ['permission' => 'access content']);
    $this->assertTrue($result->isAllowed(), 'Authenticated user allowed via permission fallback.');
  }

  /**
   * Creates and returns a group tied to a new group type.
   */
  protected function createTestGroup(): Group {
    $group_type = GroupType::create([
      'id' => 'wdb_user_group',
      'label' => 'WDB User Group',
      'description' => 'Test group type',
    ]);
    $group_type->save();

    $group = Group::create([
      'type' => $group_type->id(),
      'label' => 'TB',
    ]);
    $group->save();
    return $group;
  }

  /**
   * @covers ::checkAccess
   * Tests group restriction: member allowed, non-member denied.
   */
  public function testGroupRestriction() {
    $group = $this->createTestGroup();

    // Configure subsystem to require membership (anonymous false + group UUID).
    $this->setSubsystemConfig([
      'allowAnonymous' => FALSE,
      'group_uuid' => $group->uuid(),
    ]);

    // Anonymous denied.
    $anonymous = $this->container->get('current_user')->getAccount();
    $anonResult = $this->policy->checkAccess($this->subsystemName, $anonymous, ['permission' => 'access content']);
    $this->assertFalse($anonResult->isAllowed(), 'Anonymous denied when group restriction active.');

    // Create member & add to group.
    $member = User::create(['name' => 'member_user']);
    $member->save();
    $group->addMember($member);
    $memberResult = $this->policy->checkAccess($this->subsystemName, $member, ['permission' => 'access content']);
    $this->assertTrue($memberResult->isAllowed(), 'Group member allowed.');

    // Non-member user.
    $nonMember = User::create(['name' => 'non_member_user']);
    $nonMember->save();
    $nonMemberResult = $this->policy->checkAccess($this->subsystemName, $nonMember, ['permission' => 'access content']);
    $this->assertFalse($nonMemberResult->isAllowed(), 'Non-member denied despite having base permission.');
  }

}
