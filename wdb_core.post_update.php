<?php

/**
 * @file
 * Post update functions for wdb_core.
 */

use Drupal\Core\Database\Database;

/**
 * Add performance indexes for frequently queried code / sequence fields.
 */
function wdb_core_post_update_add_entity_indexes_01(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  // Table => spec arrays.
  // Each spec['fields'] keyed by column name with optional length settings.
  $definitions = [
    'wdb_annotation_page' => [
      'idx_wdb_annotation_code' => ['fields' => ['annotation_code' => []]],
      'idx_wdb_canvas_fragment' => ['fields' => ['canvas_identifier_fragment' => []]],
      'idx_wdb_page_number' => ['fields' => ['page_number' => []]],
    ],
    'wdb_word' => [
      'idx_wdb_word_code' => ['fields' => ['word_code' => []]],
    ],
    'wdb_word_meaning' => [
      'idx_wdb_word_meaning_code' => ['fields' => ['word_meaning_code' => []]],
    ],
    'wdb_sign_function' => [
      'idx_wdb_sign_function_code' => ['fields' => ['sign_function_code' => []]],
    ],
    'wdb_word_unit' => [
      'idx_wdb_word_sequence' => ['fields' => ['word_sequence' => []]],
      'idx_wdb_word_unit_original' => ['fields' => ['original_word_unit_identifier' => []]],
    ],
    'wdb_word_map' => [
      'idx_wdb_sign_sequence' => ['fields' => ['sign_sequence' => []]],
    ],
  ];

  foreach ($definitions as $table => $indexes) {
    if (!$schema->tableExists($table)) {
      continue;
    }
    foreach ($indexes as $name => $spec) {
      if (!$schema->indexExists($table, $name)) {
        try {
          // addIndex signature: ($table, $name, $fields, $spec = [])
          $schema->addIndex($table, $name, array_keys($spec['fields']), $spec);
        }
        catch (\Exception $e) {
          \Drupal::logger('wdb_core')->warning('Failed adding index @idx on @table: @msg', [
            '@idx' => $name,
            '@table' => $table,
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }
  }
}

/**
 * Add indexes on field data tables for frequently queried fields.
 *
 * The previous update (01) attempted to add indexes on base tables, but the
 * relevant columns live on the *_field_data tables for translatable entities.
 */
function wdb_core_post_update_add_entity_indexes_02(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  $definitions = [
    'wdb_annotation_page_field_data' => [
      'idx_wdb_annotation_code' => ['annotation_code'],
      'idx_wdb_canvas_fragment' => ['canvas_identifier_fragment'],
      'idx_wdb_page_number' => ['page_number'],
      // Potential composite for lookups by source + page.
      'idx_wdb_source_page' => ['source_ref', 'page_number'],
    ],
    'wdb_word_field_data' => [
      'idx_wdb_word_code' => ['word_code'],
      'idx_wdb_basic_form' => ['basic_form'],
    ],
    'wdb_word_meaning_field_data' => [
      'idx_wdb_word_meaning_code' => ['word_meaning_code'],
      'idx_wdb_word_ref' => ['word_ref'],
    ],
    'wdb_sign_function_field_data' => [
      'idx_wdb_sign_function_code' => ['sign_function_code'],
      'idx_wdb_sign_ref' => ['sign_ref'],
    ],
    'wdb_word_unit_field_data' => [
      'idx_wdb_word_sequence' => ['word_sequence'],
      'idx_wdb_word_unit_original' => ['original_word_unit_identifier'],
      'idx_wdb_word_unit_source' => ['source_ref'],
    ],
    // Non-translatable: fields are on base table.
    'wdb_word_map' => [
      'idx_wdb_sign_sequence' => ['sign_sequence'],
      'idx_wdb_word_unit_ref' => ['word_unit_ref'],
      'idx_wdb_sign_interp_ref' => ['sign_interpretation_ref'],
      'idx_wdb_word_unit_sign_seq' => ['word_unit_ref', 'sign_sequence'],
    ],
  ];

  foreach ($definitions as $table => $indexes) {
    if (!$schema->tableExists($table)) {
      continue;
    }
    foreach ($indexes as $name => $fields) {
      if (!$schema->indexExists($table, $name)) {
        try {
          $schema->addIndex($table, $name, $fields, []);
        }
        catch (\Exception $e) {
          \Drupal::logger('wdb_core')->warning('Failed adding index @idx on @table: @msg', [
            '@idx' => $name,
            '@table' => $table,
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }
  }
}

/**
 * Add indexes via direct SQL as fallback (MySQL normalization workaround).
 */
function wdb_core_post_update_add_entity_indexes_03(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  // Table => [ index_name => [ [col, length|null], ...] ].
  $map = [
    'wdb_annotation_page_field_data' => [
      'idx_wdb_annotation_code' => [['annotation_code', NULL]],
      // 255 varchar needs prefix under utf8mb4.
      'idx_wdb_canvas_fragment' => [['canvas_identifier_fragment', 191]],
      'idx_wdb_page_number' => [['page_number', NULL]],
      'idx_wdb_source_page' => [['source_ref', NULL], ['page_number', NULL]],
    ],
    'wdb_word_field_data' => [
      'idx_wdb_word_code' => [['word_code', NULL]],
      'idx_wdb_basic_form' => [['basic_form', NULL]],
    ],
    'wdb_word_meaning_field_data' => [
      // 240 length, restrict prefix.
      'idx_wdb_word_meaning_code' => [['word_meaning_code', 191]],
      'idx_wdb_word_ref' => [['word_ref', NULL]],
    ],
    'wdb_sign_function_field_data' => [
      'idx_wdb_sign_function_code' => [['sign_function_code', 191]],
      'idx_wdb_sign_ref' => [['sign_ref', NULL]],
    ],
    'wdb_word_unit_field_data' => [
      'idx_wdb_word_sequence' => [['word_sequence', NULL]],
      'idx_wdb_word_unit_original' => [['original_word_unit_identifier', 191]],
      'idx_wdb_word_unit_source' => [['source_ref', NULL]],
    ],
    'wdb_word_map' => [
      'idx_wdb_sign_sequence' => [['sign_sequence', NULL]],
      'idx_wdb_word_unit_ref' => [['word_unit_ref', NULL]],
      'idx_wdb_sign_interp_ref' => [['sign_interpretation_ref', NULL]],
      'idx_wdb_word_unit_sign_seq' => [['word_unit_ref', NULL], ['sign_sequence', NULL]],
    ],
  ];

  // Helper to test if index exists.
  $indexExists = static function (string $table, string $index) use ($connection): bool {
    $result = $connection->query(
      'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS '
      . 'WHERE TABLE_SCHEMA = DATABASE() '
      . 'AND TABLE_NAME = :t AND INDEX_NAME = :i',
      [
        ':t' => $table,
        ':i' => $index,
      ]
    )->fetchField();
    return (bool) $result;
  };

  foreach ($map as $table => $indexes) {
    if (!$schema->tableExists($table)) {
      continue;
    }
    foreach ($indexes as $index_name => $columns) {
      if ($indexExists($table, $index_name)) {
        continue;
      }
      $parts = [];
      foreach ($columns as [$col, $len]) {
        $parts[] = $len ? "$col($len)" : $col;
      }
      $sql = 'ALTER TABLE `' . $table . '` ADD INDEX `' . $index_name . '` (' . implode(', ', $parts) . ')';
      try {
        $connection->query($sql);
      }
      catch (\Exception $e) {
        \Drupal::logger('wdb_core')->warning('Failed raw index @idx on @table: @msg', [
          '@idx' => $index_name,
          '@table' => $table,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
  }
}

/**
 * Add composite index (source_ref, word_sequence) for word units ordering.
 */
function wdb_core_post_update_add_entity_indexes_04(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $table = 'wdb_word_unit_field_data';
  $index_name = 'idx_wdb_word_unit_source_seq';

  // Check table exists.
  if (!Database::getConnection()->schema()->tableExists($table)) {
    return;
  }

  // Check existing index.
  $exists = $connection->query(
    'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS '
    . 'WHERE TABLE_SCHEMA = DATABASE() '
    . 'AND TABLE_NAME = :t AND INDEX_NAME = :i',
    [
      ':t' => $table,
      ':i' => $index_name,
    ]
  )->fetchField();
  if ($exists) {
    return;
  }

  try {
    $connection->query(
      'ALTER TABLE `' . $table . '` ADD INDEX `' . $index_name . '` (source_ref, word_sequence)'
    );
  }
  catch (\Exception $e) {
    \Drupal::logger('wdb_core')->warning(
      'Failed adding composite index @idx on @table: @msg',
      [
        '@idx' => $index_name,
        '@table' => $table,
        '@msg' => $e->getMessage(),
      ]
    );
  }
}

/**
 * Add composite UNIQUE constraints including langcode.
 *
 * Applies to sign, sign_function, word, word_meaning field data tables.
 *
 * Enforces specification:
 *  - wdb_sign: (langcode, sign_code)
 *  - wdb_sign_function: (langcode, sign_ref, function_name)
 *  - wdb_word: (langcode, basic_form, lexical_category_ref)
 *  - wdb_word_meaning: (langcode, word_ref, meaning_identifier)
 * Skips creation if duplicates exist (logs warning) or index already present.
 */
function wdb_core_post_update_add_entity_unique_composites_05(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  $targets = [
    'wdb_sign_field_data' => [
      'index' => 'uniq_wdb_sign_lang_sign',
      'cols' => ['langcode', 'sign_code'],
      'dup_sql' => 'SELECT langcode, sign_code, COUNT(*) c FROM wdb_sign_field_data GROUP BY langcode, sign_code HAVING c>1 LIMIT 1',
    ],
    'wdb_sign_function_field_data' => [
      'index' => 'uniq_wdb_sign_function_lang_sign_fn',
      'cols' => ['langcode', 'sign_ref', 'function_name'],
      'dup_sql' => 'SELECT langcode, sign_ref, function_name, COUNT(*) c FROM wdb_sign_function_field_data GROUP BY langcode, sign_ref, function_name HAVING c>1 LIMIT 1',
    ],
    'wdb_word_field_data' => [
      'index' => 'uniq_wdb_word_lang_basic_lexcat',
      'cols' => ['langcode', 'basic_form', 'lexical_category_ref'],
      'dup_sql' => 'SELECT langcode, basic_form, lexical_category_ref, COUNT(*) c FROM wdb_word_field_data GROUP BY langcode, basic_form, lexical_category_ref HAVING c>1 LIMIT 1',
    ],
    'wdb_word_meaning_field_data' => [
      'index' => 'uniq_wdb_word_meaning_lang_word_mean_id',
      'cols' => ['langcode', 'word_ref', 'meaning_identifier'],
      'dup_sql' => 'SELECT langcode, word_ref, meaning_identifier, COUNT(*) c FROM wdb_word_meaning_field_data GROUP BY langcode, word_ref, meaning_identifier HAVING c>1 LIMIT 1',
    ],
  ];

  foreach ($targets as $table => $info) {
    if (!$schema->tableExists($table)) {
      continue;
    }
    // Detect duplicate.
    $duplicate = $connection->query($info['dup_sql'])->fetchAssoc();
    if ($duplicate) {
      \Drupal::logger('wdb_core')->warning('Skipped UNIQUE (@idx) on @table due to existing duplicate row (@dup).', [
        '@idx' => $info['index'],
        '@table' => $table,
        '@dup' => json_encode($duplicate),
      ]);
      continue;
    }
    // Check if index exists already via information_schema.
    $exists = $connection->query(
      'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
      [':t' => $table, ':i' => $info['index']]
    )->fetchField();
    if ($exists) {
      continue;
    }
    // Build and execute ALTER TABLE.
    $cols_sql = implode(', ', $info['cols']);
    try {
      $connection->query('ALTER TABLE `' . $table . '` ADD UNIQUE `' . $info['index'] . '` (' . $cols_sql . ')');
    }
    catch (\Exception $e) {
      \Drupal::logger('wdb_core')->warning('Failed adding UNIQUE @idx on @table: @msg', [
        '@idx' => $info['index'],
        '@table' => $table,
        '@msg' => $e->getMessage(),
      ]);
    }
  }
}

/**
 * Deduplicate sign_function & word_meaning rows then add remaining UNIQUEs.
 *
 * Steps:
 *  1. For wdb_sign_function(_field_data):
 *     - Collapse duplicates defined by (langcode, sign_ref, function_name)
 *       keeping the lowest id (base + field_data) and deleting the rest.
 *     - Normalize NULL function_name values to empty string ('') so that the
 *       forthcoming UNIQUE index can actually prevent future duplicates.
 *       (MariaDB allows multiple NULLs in a UNIQUE index.)
 *  2. For wdb_word_meaning(_field_data):
 *     - Collapse duplicates on (langcode, word_ref, meaning_identifier).
 *  3. Re-attempt adding UNIQUE constraints skipped in update 05.
 *
 * Safety: We operate only on field_data rows and mirror deletions to base
 * tables to avoid orphan base entities. No rows with differing non-key field
 * data (e.g. descriptions) were detected among duplicates earlier (all empty),
 * so choosing the smallest id is safe.
 */
function wdb_core_post_update_dedupe_sign_function_word_meaning_06(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  $schema = $connection->schema();

  // Helper to execute and log but not throw.
  $run = static function (string $sql, array $args = []) use ($connection) {
    try {
      $connection->query($sql, $args);
    }
    catch (\Exception $e) {
      \Drupal::logger('wdb_core')->warning('Post-update 06 SQL failed (@msg) for: @sql', [
        '@msg' => $e->getMessage(),
        '@sql' => $sql,
      ]);
    }
  };

  // 1. SIGN FUNCTION dedupe & normalize.
  if ($schema->tableExists('wdb_sign_function_field_data') && $schema->tableExists('wdb_sign_function')) {
    // Delete extra field_data rows (retain MIN(id)).
    $run('DELETE fd FROM wdb_sign_function_field_data fd
      JOIN (
        SELECT langcode, sign_ref, function_name, MIN(id) keep_id
        FROM wdb_sign_function_field_data
        GROUP BY langcode, sign_ref, function_name
        HAVING COUNT(*) > 1
      ) d ON d.langcode = fd.langcode
           AND ((d.function_name IS NULL AND fd.function_name IS NULL) OR d.function_name = fd.function_name)
           AND d.sign_ref = fd.sign_ref
      WHERE fd.id <> d.keep_id');

    // Delete matching base rows not kept.
    $run('DELETE b FROM wdb_sign_function b
      JOIN (
        SELECT langcode, sign_ref, function_name, MIN(id) keep_id
        FROM wdb_sign_function_field_data
        GROUP BY langcode, sign_ref, function_name
        HAVING COUNT(*) > 1
      ) dfd ON dfd.keep_id <> b.id
      JOIN wdb_sign_function_field_data fdk ON fdk.id = b.id
        AND ((dfd.function_name IS NULL AND fdk.function_name IS NULL) OR dfd.function_name = fdk.function_name)
        AND dfd.langcode = fdk.langcode
        AND dfd.sign_ref = fdk.sign_ref');

    // Normalize remaining NULL function_name to empty string.
    $run("UPDATE wdb_sign_function_field_data SET function_name='' WHERE function_name IS NULL");
    $run("UPDATE wdb_sign_function SET function_name='' WHERE function_name IS NULL");
  }

  // 2. WORD MEANING dedupe.
  if ($schema->tableExists('wdb_word_meaning_field_data') && $schema->tableExists('wdb_word_meaning')) {
    $run('DELETE fd FROM wdb_word_meaning_field_data fd
      JOIN (
        SELECT langcode, word_ref, meaning_identifier, MIN(id) keep_id
        FROM wdb_word_meaning_field_data
        GROUP BY langcode, word_ref, meaning_identifier
        HAVING COUNT(*) > 1
      ) d ON d.langcode = fd.langcode AND d.word_ref = fd.word_ref AND d.meaning_identifier = fd.meaning_identifier
      WHERE fd.id <> d.keep_id');

    $run('DELETE b FROM wdb_word_meaning b
      JOIN (
        SELECT langcode, word_ref, meaning_identifier, MIN(id) keep_id
        FROM wdb_word_meaning_field_data
        GROUP BY langcode, word_ref, meaning_identifier
        HAVING COUNT(*) > 1
      ) dfd ON dfd.keep_id <> b.id
      JOIN wdb_word_meaning_field_data fdk ON fdk.id = b.id
        AND dfd.langcode = fdk.langcode
        AND dfd.word_ref = fdk.word_ref
        AND dfd.meaning_identifier = fdk.meaning_identifier');
  }

  // 3. Attempt UNIQUE constraints (sign_function & word_meaning)
  // if still missing.
  $uniques = [
    'wdb_sign_function_field_data' => [
      'index' => 'uniq_wdb_sign_function_lang_sign_fn',
      'cols' => ['langcode', 'sign_ref', 'function_name'],
    ],
    'wdb_word_meaning_field_data' => [
      'index' => 'uniq_wdb_word_meaning_lang_word_mean_id',
      'cols' => ['langcode', 'word_ref', 'meaning_identifier'],
    ],
  ];
  foreach ($uniques as $table => $info) {
    if (!$schema->tableExists($table)) {
      continue;
    }
    $exists = $connection->query(
      'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
      . 'AND TABLE_NAME = :t AND INDEX_NAME = :i',
      [
        ':t' => $table,
        ':i' => $info['index'],
      ]
    )->fetchField();
    if ($exists) {
      continue;
    }
    // Re-check duplicates (should be none now) -- if found, skip.
    $dup_sql = 'SELECT 1 FROM ' . $table . ' GROUP BY ' . implode(', ', $info['cols']) . ' HAVING COUNT(*)>1 LIMIT 1';
    $dup = $connection->query($dup_sql)->fetchField();
    if ($dup) {
      \Drupal::logger('wdb_core')->warning('Skipped UNIQUE (@idx) on @table in 06; duplicates still remain after attempted cleanup.', [
        '@idx' => $info['index'],
        '@table' => $table,
      ]);
      continue;
    }
    $cols = implode(', ', $info['cols']);
    try {
      $connection->query('ALTER TABLE `' . $table . '` ADD UNIQUE `' . $info['index'] . '` (' . $cols . ')');
    }
    catch (\Exception $e) {
      \Drupal::logger('wdb_core')->warning('Failed adding UNIQUE @idx on @table in 06: @msg', [
        '@idx' => $info['index'],
        '@table' => $table,
        '@msg' => $e->getMessage(),
      ]);
    }
  }
}

/**
 * Reconcile wdb_sign_function langcodes with their referenced sign.
 *
 * After introducing automatic inheritance in preSave, existing rows may
 * still have mismatched language values causing potential confusion and
 * blocking uniqueness validation. This update normalizes all existing
 * sign_function entities so that langcode == referenced sign's langcode.
 */
function wdb_core_post_update_normalize_sign_function_langcode_07(&$sandbox = NULL): void {
  $connection = Database::getConnection();
  // Only run if tables exist.
  if (!Database::getConnection()->schema()->tableExists('wdb_sign_function_field_data') || !Database::getConnection()->schema()->tableExists('wdb_sign_field_data')) {
    return;
  }
  try {
    // Update field data table first (translatable storage).
    $connection->query('UPDATE wdb_sign_function_field_data sf
      JOIN wdb_sign_field_data s ON s.id = sf.sign_ref AND s.langcode <> sf.langcode
      SET sf.langcode = s.langcode');
    // Mirror for base table for consistency (non-translatable base row).
    if ($connection->schema()->tableExists('wdb_sign_function')) {
      $connection->query('UPDATE wdb_sign_function b
        JOIN wdb_sign_function_field_data sf ON sf.id = b.id
        JOIN wdb_sign_field_data s ON s.id = sf.sign_ref
        SET b.langcode = s.langcode
        WHERE b.langcode <> s.langcode');
    }
  }
  catch (\Exception $e) {
    \Drupal::logger('wdb_core')->warning('Failed normalizing sign_function langcodes: @msg', ['@msg' => $e->getMessage()]);
  }
}

/**
 * Ensure subsystem configs define the group_uuid linkage key.
 */
function wdb_core_post_update_add_group_uuid_setting_08(&$sandbox = NULL): void {
  $storage = \Drupal::service('config.storage');
  $config_factory = \Drupal::configFactory();
  $config_names = $storage->listAll('wdb_core.subsystem.');

  foreach ($config_names as $name) {
    $config = $config_factory->getEditable($name);
    $data = $config->getRawData();
    if (!array_key_exists('group_uuid', $data)) {
      $config->set('group_uuid', NULL)->save();
    }
  }
}
