# **WDB: Word-Database Module for Drupal**

> Documentation note: A full Japanese explanation is provided in the latter half of this file. / 後半に日本語での詳細説明があります。

## **1\. Overview**

The Word-Database (WDB) Core module is a comprehensive toolkit for Drupal designed for linguists, historians, philologists, and digital humanists. A key feature of WDB is its flexibility, allowing for the management of **multiple linguistic materials from various eras and regions on a single, unified platform.**

At its core, the system allows users to perform granular annotations on high-resolution IIIF-compliant images, linking individual characters (signs) and words to detailed linguistic information. This deep data structure enables powerful searches, allowing users to **query textual information directly from the image context**. It features a rich user interface based on OpenSeadragon and Annotorious v3, a robust data import workflow, and deep integration with Drupal's core systems.

WDB is designed to transform digital image archives into structured, searchable, and interoperable linguistic databases, ready for scholarly research and public engagement.

## **Screenshots**

![Screenshot](docs/screenshots/wdb-screenshot-01.png)

## **2\. Features**

* **IIIF-Compliant Viewer & Editor:** A powerful and intuitive interface for viewing and annotating high-resolution images, built with OpenSeadragon and Annotorious v3.
* **Granular Annotation:** Supports detailed polygon annotations for individual characters (signs). **Word polygons are automatically calculated from these character sets**, enabling precise linguistic analysis.
* **Flexible Linguistic Data Import:** A batch import system for linguistic data using TSV files, complete with a rollback feature for safe data management.
* **Template Generation:** Automatically generate TSV templates from morphological analysis results (currently supporting **Japanese formats like WebChaMaMe's "Chaki import format"**) or from existing data within the system, significantly lowering the barrier to entry.
* **Dynamic Configuration & Access Control:** Manage all module and subsystem settings through a unified administrative UI. Configure whether to **make gallery and search pages public to anonymous users for each collection** (subsystem) of documents.
* **IIIF Presentation API v3 Compliant:** Automatically generates IIIF Presentation API v3 manifests, including word-level annotations with rich, linked-data-ready descriptions, ensuring interoperability with external viewers like Mirador 3\.
* **Customizable Data Export:** Export linguistic data in **TEI/XML and RDF/XML formats**. **Templates for these formats can be edited directly within the administrative UI**, allowing for user-defined output structures.
* **Deep Views Integration:** Full integration with Drupal's Views module allows site administrators to create completely customized search result pages and data listings without writing any code.
* **Extensible Entity Structure:** Built on Drupal's standard entity system. Site administrators can easily **add new fields to core data types like 'Source' and customize their display**, just like any other content type.
* **Optional Cantaloupe Integration:** An optional submodule provides a secure API endpoint to integrate with a Cantaloupe IIIF Image Server's delegate script, enabling control over **IIIF image access based on the user's login status on the Drupal site**.

## **3\. Requirements**

* **Drupal Core:** `^10` or `^11`
* **PHP:** `^8.1`
* **Required Drupal Modules (Enabled automatically by** `wdb_core.info.yml`**):**
  * Taxonomy, Content Translation, Language
  * File, Image
  * Field, Field UI
  * jQuery UI, jQuery UI Dialog
* **Optional:**
  * **IIIF Image Server (Cantaloupe recommended)** (for serving your own images)

## **4\. Installation**

1. Navigate to your Drupal project's root directory.
2. Place the module directory in `/modules/custom`. *(Once published on Drupal.org, it can be installed via `composer require drupal/wdb_core`)*.
3. Enable the main module and any desired sub-modules via the Drupal UI (`/admin/modules`) or Drush:
```
   # Enable the core module (required)
   drush en wdb_core

   # Enable optional sub-modules as needed
   drush en wdb_core_views      # For Views integration
   drush en wdb_cantaloupe_auth # For Cantaloupe authentication
   drush en wdb_example         # For sample content
```
4. **Configure Private File System:** The linguistic data import feature requires Drupal's private file system.
   * Go to `Configuration > Media > File system` (`/admin/config/media/file-system`).
   * Set the "Private file system path" (e.g., `../private`) and save the configuration. Ensure this directory exists and is writable by the web server, but is not accessible directly from the web.
5. **Upon installation, the module will automatically:**
   * Add English and Japanese languages to the site if they do not exist.
   * Create the necessary Taxonomy vocabularies (Lexical Category, Grammatical Categories, etc.).
   * Create a default set of POS (Part-of-Speech) mapping rules.
6. Navigate to `/admin/people/permissions` and grant the "Administer WDB Core configuration" and other WDB-related permissions to the appropriate roles.

## **5\. Usage Workflow**

After installation, all WDB-related management pages are consolidated under the **WDB** menu item in the administration toolbar. Here is a typical workflow for setting up and using the system:

1. **Create a Subsystem:**
   * **Prerequisite:** Ensure the language for your data is available in Drupal. Go to `Configuration > Regional and language > Languages` (`/admin/config/regional/language`). If the language (e.g., Egyptian) does not exist, add it as a "Custom language".
   * **Create the Term:** Go to `Structure > Taxonomy > Subsystem` and create a new taxonomy term for your collection. Select the language for this subsystem.
2. **Configure the Subsystem:** Go to `WDB > Dashboard > Configuration > Module Settings`. In the tab for your new subsystem, configure the IIIF server details and access control settings.
   * **Important:** It is recommended to set the **IIIF Identifier Pattern** at this stage, before creating any `WDB Source` entities. This ensures that `image_identifier` values are generated correctly from the start. If you want to modify the `image_identifier` after creating the `WDB Source` entity (i.e., after the annotation page entity has been automatically created), enter the **IIIF Identifier Pattern** and click the **Apply pattern to existing pages in "{subsystem_name}"** button in the **Update Existing Pages** section.
   * **IIIF Server Prefix:** Do not URL-encode this value.
   * **Allow anonymous access:** Check this to make the gallery and search pages for this subsystem public. Otherwise, users will need the "View non-public WDB gallery pages" or "Access WDB search form" permissions, respectively.
   * **Hull Concavity:** Controls the tightness of the auto-generated word polygon. A smaller value creates a tighter, more concave shape. However, `0` results in a convex hull.
3. **Define a Source:** Go to `WDB > Dashboard > Content Management > Manage Sources` and create a new `WDB Source` entity. Select the subsystem you just created.
4. **Update Annotation Pages:** When a new Source is created, Annotation Page entities are automatically generated based on the "Pages" field. Navigate to `WDB > Dashboard > Content Management > Manage Annotation Pages` to edit these pages and confirm the `image_identifier` has been generated correctly. You can also manually override it here if needed.
5. **View the Gallery:** At this point, you should be able to access the gallery page (`/wdb/{subsystem_name}/gallery/{source_identifier}/{page_number}`) and see the IIIF images.
6. **Annotate Characters:** In edit mode (`./edit`), draw polygons for each character. Enter a label for each polygon in the popup editor (e.g., a format like "line-character_sequence" like "1-1", "1-2" is recommended, but any readable text is acceptable).
7. **Prepare Linguistic Data:** Create your linguistic data in a TSV file. You can download a template from the "TSV Template Generator" tool (`WDB > Dashboard > Tools & Utilities`). The `example` module provides useful sample data. For Japanese, you can also generate a template directly from the output of a morphological analyzer like WebChaMaMe (Chaki import format). In that case, UniDic's part-of-speech system will be automatically mapped to WDB's lexical categories. The POS Mappings table is used for mapping. You can also modify the mapping as needed.
8. **Import Data:** Go to the Import Form (`WDB > Dashboard > Tools & Utilities > Import New Data`), select the language, upload your TSV file, and start the batch import.
9. **Verify:** Check the gallery page again. The linguistic data should now be displayed in the annotation panel, linked to the character polygons on the viewer.

## **6\. Displaying Subsystem Titles**

To display a prominent title for each subsystem (e.g., "Genji Monogatari Database") independently of the main site name, WDB Core provides a dedicated block. This is the recommended way to show visitors which collection they are currently viewing.

1. **Set the Title:** Go to `WDB > Dashboard > Configuration > Module Settings`. In each subsystem's tab, fill in the **Display Title** field with the full title you want to show for that collection.
2. **Place the Block:** Go to `Structure > Block layout` (`/admin/structure/block`). Click **Place block** in the region where you want the title to appear (e.g., Header or Content).
3. **Select the Block:** In the modal window, find and place the **WDB Subsystem Title** block.
4. **Configure the Block:** Uncheck the "Display title" option in the block's configuration form to prevent the block label from appearing.
5. **Theme Integration (Recommended):** For the best user experience, it is recommended to hide the default site name in your theme's settings and use this block as the primary title on WDB pages. The block will only appear on pages that belong to a subsystem, so it will not interfere with other parts of your site.

## **7\. TSV File Format**

The following columns are expected in the data import file. Fields marked with \* are mandatory.

* `source`\*: The "Source Identifier" of the WdbSource entity.
* `page`\*: The page number.
* `labelname`\*: The label text entered for each polygon (e.g., "1-1").
* `sign`\*: The character symbol or code.
* `function`: The function of the character (e.g., phonogram, logogram).
* `phone`: The phonetic transcription of the character.
* `note`: A note about the character.
* `word_unit`\*: A numeric value to group characters into a word. All characters in the same word should have the same number. This value must be unique across the entire source document.
* `basic_form`\*: The lemma (dictionary form) of the word. A word is considered unique by the combination of its basic form and lexical category.
* `realized_form`: The actual form of the word as it appears in the text.
* `word_sequence`\*: The sequence number of the word in the document. This value must be unique across the entire source document.
* `lexical_category_name`\*: The part-of-speech name for the word. If a matching term does not exist in the "Lexical Category" taxonomy, a new one will be created.
* `meaning`\*: A numeric value to distinguish between multiple meanings of the same word.
* `explanation`: A description or gloss for the meaning.
* `verbal_form_name`, `gender_name`, `number_name`, `person_name`, `voice_name`, `aspect_name`, `mood_name`, `grammatical_case_name`: Names of grammatical categories. If a matching term does not exist in the corresponding taxonomy, a new one will be created.

## **8\. Advanced Topics**

### **Cantaloupe IIIF Server Integration**

The optional `wdb_cantaloupe_auth` submodule allows you to integrate WDB's access control system with your Cantaloupe IIIF Image Server. This enables you to restrict access to IIIF images based on a user's login status in Drupal.

**How it Works:**

1. When a request for an image is made to Cantaloupe, its delegate script makes a `POST` request to a secure API endpoint provided by this submodule (`/wdb/api/cantaloupe_auth`).
2. The script sends the user's browser cookies and the requested image identifier to the Drupal API.
3. The Drupal API checks the user's session based on the cookies and verifies if the user has the "View non-public WDB gallery pages" permission for the subsystem the image belongs to.
4. Drupal returns a simple JSON response (`{"authorized": true/false}`).
5. The delegate script then either serves the image or returns a "Forbidden" error based on this response.

**Setup:**

1. Enable the `wdb_cantaloupe_auth` submodule.
2. Configure your Cantaloupe server to use a delegate script.
3. Use the following Ruby code as a template for your `delegate.rb` file. Make sure to update the `DRUPAL_AUTH_ENDPOINT` constant to match your site's URL.

**Sample** `delegate.rb` **script:**

```ruby:delegate.rb
require 'net/http'
require 'uri'
require 'json'

# ... (Cantaloupe delegate script boilerplate) ...

# The full URL to your Drupal site's authorization API endpoint.
DRUPAL_AUTH_ENDPOINT = "https://your.host.name/wdb/api/cantaloupe_auth"

def pre_authorize(options = {})
  # Allow requests for info.json unconditionally.
  return true if context['request_uri'].end_with?('info.json')

  # Allow requests from the server itself (e.g., for derivative generation).
  return true if context['client_ip'].start_with?('127.0.0.1')

  # Convert cookies to the format expected by the API.
  cookies = context['cookies'].map { |k, v| "#{k}=#{v}" }

  # Create the payload for the API request.
  payload = {
    cookies: cookies,
    identifier: context['identifier']
  }.to_json

  begin
    uri = URI.parse(DRUPAL_AUTH_ENDPOINT)
    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')

    request = Net::HTTP::Post.new(uri.request_uri, 'Content-Type' => 'application/json')
    request.body = payload

    response = http.request(request)

    if response.is_a?(Net::HTTPSuccess)
      auth_result = JSON.parse(response.body)
      return auth_result['authorized']
    else
      # If the API call fails, deny access for security.
      return false
    end
  rescue => e
    # If any exception occurs, deny access for security.
    # Consider logging the error: logger.error("Error during authorization: #{e.message}")
    return false
  end
end
```
### **Export Template Variables**

The TEI/XML and RDF/XML export templates are rendered using Twig. The main data object available in the template is `page_data`. Below is a guide to its structure and the available variables.

* `page_data`
  * `.source`: The `WdbSource` entity object for the current document.
    * `{{ page_data.source.label() }}`: The display name of the source.
    * `{{ page_data.source.source_identifier.value }}`: The unique identifier of the source.
    * `{{ page_data.source.description.value }}`: The description of the source.
  * `.page`: The `WdbAnnotationPage` entity object for the current page.
    * `{{ page_data.page.label() }}`: The display label of the page (e.g., "p. 3").
    * `{{ page_data.page.page_number.value }}`: The page number.
    * `{{ page_data.page.getCanvasUri() }}`: A helper method to get the full IIIF Canvas URI for the page.
  * `.word_units`: An array of all word units on the page, sorted by their sequence. You can loop through this array:
    ```
    {% for item in page_data.word_units %}
      ...
    {% endfor %}`
    ```
    Each `item` in the loop contains:
    * `item.entity`: The `WdbWordUnit` entity object.
      * `{{ item.entity.realized_form.value }}`: The realized form.
      * `{{ item.entity.word_sequence.value }}`: The sequence number.
      * To access related data, you can traverse the entity references:
        * `{% set word_meaning = item.entity.word_meaning_ref.entity %}`
        * `{% set word = word_meaning.word_ref.entity %}`
        * `{{ word.basic_form.value }}`
        * `{{ word.lexical_category_ref.entity.label() }}`
    * `item.sign_interpretations`: An array of `WdbSignInterpretation` entities that make up this word unit, sorted by their sequence within the word.
      * `{% for si in item.sign_interpretations %}`
      * `{{ si.phone.value }}`
      * `{% set sign_function = si.sign_function_ref.entity %}`
      * `{% set sign = sign_function.sign_ref.entity %}`
      * `{{ sign.sign_code.value }}`


## **9\. For Developers**

### **Entity Relationships**

The WDB module is built upon a rich set of custom content entities. The primary relationships are as follows:

* **WdbSource** (A document) has many **WdbAnnotationPage**s.
* **WdbWordUnit** (A word in context) appears on one or more **WdbAnnotationPage**s.
* **WdbWordUnit** is linked to a single **WdbWordMeaning**.
* **WdbWordMeaning** belongs to a single **WdbWord** (the lemma).
* **WdbWord** is classified by a **Lexical Category** (Taxonomy Term).
* **WdbWordUnit** is composed of one or more **WdbSignInterpretation**s, linked via the **WdbWordMap** entity.
* **WdbSignInterpretation** is linked to a **WdbLabel** (the annotation polygon) and a **WdbSignFunction**.
* **WdbSignFunction** belongs to a single **WdbSign** (the character).

### **Design Notes**

*Optional Sign Function Name*

`WdbSignFunction.function_name` is intentionally optional. If it is left blank the generated `sign_function_code` becomes `<sign_code>_` (a trailing underscore). Two safeguards enforce logical uniqueness:

1. A composite uniqueness validator on `(langcode, sign_ref, function_name)` treats an empty string as a real value so duplicate blank entries for the same Sign + language are rejected before hitting the database.
2. An additional validator step predicts the final `sign_function_code` and blocks duplicates (relevant when `function_name` is blank or identical).

Rationale: During early data creation and TSV preparation, researchers (often in the humanities rather than computational specialists) may not yet wish to categorise every sign function. Allowing an empty value lowers friction while still guaranteeing stable identifiers and preventing silent duplication. This is analogous—but not identical—to `WdbWordMeaning`, where `meaning_identifier` is required but `explanation` is optional.

If a future project phase requires making the function name mandatory, it can be flipped to required without breaking existing codes; existing blank entries already yield consistent `sign_function_code` values.

### **Key Services**

* `wdb_core.data_service` **(**WdbDataService**):** A central service for fetching and structuring data for the annotation panel and other parts of the system.
* `wdb_core.data_importer` **(**WdbDataImporterService**):** Handles the logic for parsing TSV files and creating/updating entities in batch.
* `wdb_core.template_generator` **(**WdbTemplateGeneratorService**):** Contains the logic for generating TSV templates.

### **Extending the Module**

The module is designed to be extensible. For example, you can use standard Drupal hooks like `hook_entity_insert()` to react to the creation of new `subsystem` taxonomy terms, as demonstrated for creating default configurations.

### **API Endpoints**

The core module exposes a couple of lightweight, read-only endpoints to support client-side geometry utilities. All endpoints are open to GET requests and return JSON. Remember to URL-encode JSON passed via query parameters (examples below are already encoded).

- GET `/wdb/api/hull`
  - Purpose: Compute a concave hull for an arbitrary point set.
  - Query parameters:
    - `points` (required): JSON array of strings in the form `"X,Y"`, e.g. `["10,20","30,40","25,35"]`.
    - `concavity` (optional, integer): Hull tightness. `0` yields a convex hull. Higher values produce looser hulls. Default: `20`.
  - Response: JSON array of 2D points `[[x1, y1], [x2, y2], ...]` (numbers).
  - Errors: `400` with `{ "error": "..." }` if `points` is missing or invalid.
  - Example URL:
    - Concave (concavity=20):
      `/wdb/api/hull?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D&concavity=20`
    - Convex (concavity=0):
      `/wdb/api/hull?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D&concavity=0`

- GET `/wdb/api/bbox`
  - Purpose: Compute an axis-aligned bounding box for an arbitrary point set.
  - Query parameters:
    - `points` (required): JSON array of strings in the form `"X,Y"`.
  - Response: JSON object `{ x, y, w, h }` where `(x, y)` is the top-left, `w` is width, and `h` is height. Values are rounded to integers; `w`/`h` are at least `1`.
  - Errors: `400` with `{ "error": "..." }` if `points` is missing or invalid.
  - Example URL:
    `/wdb/api/bbox?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D`

## **1\. 概要**

Word-Database (WDB) Core モジュールは、言語学者、歴史学者、文献学者、そしてデジタル・ヒューマニティーズの研究者のために設計された、Drupalのための包括的なツールキットです。WDBの最も重要な特徴は、その柔軟性です。**様々な時代や地域の複数の言語資料を、単一の統一されたプラットフォーム上で取り扱うことができます。**

このシステムの中核となるのは、IIIFに準拠した高精細画像に対して詳細なアノテーションを付与し、個々の文字（`sign`）や単語（`word`）を、詳細な言語情報と結びつける機能です。この深いデータ構造により、**画像上の文脈から直接テキスト情報を検索する**、といった強力な検索が可能になります。OpenSeadragonとAnnotorious v3をベースにしたリッチなユーザーインターフェース、堅牢なデータ投入ワークフロー、そしてDrupalのコアシステムとの深い統合を特徴としています。

WDBは、デジタル化された画像アーカイブを、学術研究と公開活用のための、構造化され、検索可能で、相互運用性の高い言語データベースへと変換するために設計されています。

### **スクリーンショット**

![スクリーンショット](docs/screenshots/wdb-screenshot-01.png)

## **2\. 主な機能**

* **IIIF準拠のビューアとエディタ:** OpenSeadragonとAnnotorious v3で構築された、高精細画像の閲覧とアノテーション付与のための、強力で直感的なインターフェース。
* **詳細なアノテーション機能:** 個々の文字（`sign`）に対するポリゴン形式でのアノテーションをサポート。**単語のポリゴンは、これらの文字の集合から自動的に計算され**、精密な言語分析を可能にします。
* **柔軟な言語データ投入:** **TSVファイルを用いた言語データ一括投入システム**。安全なデータ管理のためのロールバック機能も完備。
* **テンプレート生成機能:** 形態素解析結果（現在は**Web茶まめ の「Chakiインポート形式」のような日本語フォーマットに対応**）や、システム内の既存データから、TSVテンプレートを自動生成。データ投入のハードルを大幅に下げます。
* **動的な設定とアクセス制御:** モジュールとサブシステム（資料群）の全ての設定を、統一された管理画面から操作可能。**資料群ごとに、匿名ユーザーに対してギャラリーページと検索フォームを公開するかどうかを設定できます。**
* **IIIF Presentation API v3準拠:** 単語レベルのアノテーション（豊富なリンク情報付き）を含む、IIIF Presentation API v3準拠のマニフェストを自動生成し、Mirador 3のような外部ビューアとの相互運用性を保証します。
* **カスタマイズ可能なデータエクスポート:** 言語データを**TEI/XMLおよびRDF/XML形式でエクスポート**できます。これらの**フォーマットのテンプレートは管理画面から直接編集でき**、ユーザーが自由に出力構造を定義できます。
* **Viewsとの深い連携:** DrupalのViewsモジュールと完全に統合されており、サイト管理者はコードを書くことなく、完全にカスタマイズされた検索結果ページやデータ一覧を作成できます。
* **拡張可能なエンティティ構造:** Drupalの標準的なエンティティシステム上に構築されています。サイト管理者は、他のコンテンツタイプと同様に、**「資料」などの中心的なデータに新しいフィールドを簡単に追加し、その表示をカスタマイズ**できます。
* **Cantaloupe連携（オプション）:** オプションのサブモジュールが、Cantaloupe IIIF画像サーバのDelegateスクリプトと連携するための、安全なAPIエンドポイントを提供。**Drupalサイトへのログイン状態に応じてIIIF画像へのアクセスを許可するかどうかを制御できます。**

## **3\. 要件**

* **Drupal Core:** `^10` または `^11`
* **PHP:** `^8.1`
* **必須Drupalモジュール（**`wdb_core.info.yml`**により自動で有効化されます）:**
  * Taxonomy, Content Translation, Language
  * File, Image
  * Field, Field UI
  * jQuery UI, jQuery UI Dialog
* **オプション:**
  * **IIIF画像サーバ（Cantaloupeを推奨）** （自身で画像を配信する場合）

## **4\. インストール**

1. Drupalプロジェクトのルートディレクトリに移動します。
2. モジュールディレクトリを `/modules/custom` に配置してください。（将来的にDrupal.orgで公開された場合は `composer require drupal/wdb_core` でインストールできます。）
3. Drupalの管理画面（`/admin/modules`）またはDrushを使って、メインモジュールと、必要に応じてサブモジュールを有効化します:
```
   # コアモジュールを有効化（必須）
   drush en wdb_core

   # 必要に応じてオプションのサブモジュールを有効化
   drush en wdb_core_views      # Views連携機能
   drush en wdb_cantaloupe_auth # Cantaloupe認証連携
   drush en wdb_example         # サンプルコンテンツ
```
4. **プライベートファイルシステムの設定:** 言語データのインポート機能は、Drupalのプライベートファイルシステムを使用します。
   * `環境設定 > メディア > ファイルシステム` (`/admin/config/media/file-system`) に移動します。
   * 「プライベートファイルシステムパス」を設定し（例: `../private`）、設定を保存してください。このディレクトリは、Webサーバーから書き込み可能で、かつWebから直接アクセスできない場所にある必要があります。
5. **インストール時の挙動:** モジュールを有効化すると、以下の初期設定が自動的に行われます。
   * サイトに英語と日本語が（もしなければ）追加されます。
   * 語彙範疇（Lexical Category）や文法カテゴリー（Grammatical Categories）のタクソノミーボキャブラリーが生成されます。
   * 品詞マッピングの初期ルールが作成されます。
5. `/admin/people/permissions` に移動し、「Administer WDB Core configuration」や、その他のWDB関連の権限を、適切な役割（ロール）に割り当ててください。

## **5\. 使い方：基本的なワークフロー**

インストール後、WDB関連の全ての管理ページは、管理ツールバーの\*\*「WDB」\*\*メニュー項目に集約されます。以下に、典型的な作業の流れを示します。

1. **サブシステムの作成:**
   * **事前準備:** 扱うデータの言語がDrupalに登録されていることを確認します。`環境設定 > 地域・言語 > 言語` (`/admin/config/regional/language`) に移動してください。もし言語（例: エジプト語）が存在しない場合は、「カスタム言語を追加」から言語コード（例: egy）を指定して作成します。
   * **タームの作成:** サイト構築 \> タクソノミー \> Subsystem に移動し、資料群に対応する新しいタクソノミータームを作成します。この時、その資料群の主要言語として、先ほど準備した言語を選択します。
2. **サブシステムの設定:** `WDB > ダッシュボード > 設定 > モジュール設定` に移動します。新しく作成したサブシステムのタブを開き、IIIF画像サーバの情報や資料の公開方法などを設定します。
   * **重要:** `image_identifier`を正しく自動生成するために、この段階で **IIIF Identifier Pattern** を設定することを推奨します。この設定は、`WDB Source`**エンティティを作成する前**に行ってください。`WDB Source`エンティティ作成後（すなわちアノテーションページエンティティが自動作成された後）に`image_identifier`を修正したい場合には、**IIIF Identifier Pattern**を入力した上で、**Update Existing Pages**セクションの**Apply pattern to existing pages in {subsystem_name} ボタン**をクリックしてください。
   * **IIIF Server Prefix:** URLエンコードは不要です。
   * **Allow anonymous access:** これにチェックを入れると、このサブシステムのギャラリーページと検索フォームが匿名ユーザーに公開されます。チェックを外した場合、それぞれ「View non-public WDB gallery pages」または「Access WDB search form」の権限が必要になります。初期状態では非公開です。
   * **Hull Concavity:** 文字の集合から単語のポリゴンを生成する際の、座標の密着度（凹みの大きさ）を制御します。値が小さいほど凹みが大きくなります（ただし、0で凸包）。
3. **資料情報の定義:** `WDB > ダッシュボード > コンテンツ管理 > 資料の管理` に移動し、新しい`WDB Source`エンティティを作成します。先ほど作成したサブシステムを選択してください。
4. **アノテーションページの更新:** 新しい資料を作成すると、そのページ数分のアノテーションページエンティティが自動生成されます。`WDB > ダッシュボード > コンテンツ管理 > アノテーションページの管理` に移動し、`image_identifier`が正しく生成されていることを確認します。必要であれば、ここで個別に値を上書きすることも可能です。
5. **ギャラリーページの確認:** ここまでの設定が完了すると、`/wdb/{サブシステム名}/gallery/{資料ID}/{ページ番号}` というURLでギャラリーページにアクセスし、IIIF画像が表示されるはずです。
6. **アノテーションの作成:** 編集モード (`./edit`) に切り替え、ツールバーのボタンを使ってポリゴンを描画します。各ポリゴンには、"1-1"（行番号-文字順）のような、ページ内でユニークなラベル名を入力します。
7. **言語データの準備:** システム外で言語データを作成します。`WDB > ダッシュボード > ツールとユーティリティ > TSVテンプレート生成` から、テンプレートとなるTSVファイルをダウンロードできます。`example`モジュールを有効化すると、サンプルデータも利用できます。日本語の場合は、形態素解析済みテキスト（Web茶まめが出力するChakiインポート用形式のフォーマット）をアップロードして、テンプレートを生成することも可能です。その場合、UniDicの品詞体系はWDBの語彙範疇に自動的にマッピングされます。マッピングにはPOS Mappingsテーブルが使われます。必要に応じてマッピングを修正することも可能です。
8. **言語データの投入:** `WDB > ダッシュボード > ツールとユーティリティ > データ新規取込` フォームで、作成したTSVファイルをアップロードします。
9. **最終確認:** 再度ギャラリーページにアクセスし、ポリゴンと言語データが正しく結びついて表示されることを確認します。

## **6\. サブシステム・タイトルの表示**

各サブシステム（資料群）のタイトル（例: 「源氏物語データベース」）を、サイト全体のサイト名とは独立して表示するために、WDB Coreは専用のブロックを提供します。これは、訪問者に現在どの資料群を閲覧しているかを伝えるための推奨される方法です。

1. **タイトルの設定:** `WDB > ダッシュボード > 設定 > モジュール設定` に移動します。各サブシステムのタブで、**Display Title** フィールドに、その資料群で表示したい正式なタイトルを入力します。
2. **ブロックの配置:** `サイト構築 > ブロックレイアウト` (`/admin/structure/block`) に移動します。タイトルを表示したい領域（例: ヘッダーやコンテンツ）の **ブロックを配置** ボタンをクリックします。
3. **ブロックの選択:** モーダルウィンドウで **WDB Subsystem Title** ブロックを探し、配置します。
4. **ブロックの設定:** ブロックの設定フォームで、「タイトルを表示」のチェックを外すと、ブロックのラベルが表示されず、\<h1\>タグで囲まれたタイトルだけが表示されるため、すっきりとします。
5. **テーマとの連携（推奨）:** 最適なユーザー体験のためには、テーマの設定でデフォルトのサイト名を非表示にし、このブロックをWDB関連ページのメインタイトルとして使用することをお勧めします。このブロックは、サブシステムに属するページでのみ表示されるため、サイトの他の部分には影響を与えません。

## **7\. TSVファイルの各カラムの書式について**

データ投入用のTSVファイルは、以下のカラムで構成されます。\* が付いている項目は必須です。

* `source`\*: 資料名。WdbSourceエンティティの「Source Identifier」と一致させる必要があります。
* `page`\*: ページ番号。
* `labelname`\*: ラベル名。各ポリゴンに入力した "1-1" 等のテキストと一致させる必要があります。
* `sign`\*: 文字記号あるいは文字コード。
* `function`: 文字の機能（例: phonogram, logogram）。
* `phone`: 文字の発音・音写。
* `note`: 文字に関する注記。
* `word_unit`\*: 単語のまとまりを表す数値。同じ単語に属する文字には、同じ数値を付与します。この値は、資料全体でユニークである必要があります。
* `basic_form`\*: 単語の基本形（見出し語）。同形異語は、`lexical_category_name`との組み合わせで区別されます（同じ形かつ同じ品詞ならば同じ語と見なされます）。
* `realized_form`: 単語の実現形。資料上で実際に出現している形。
* `word_sequence`\*: 資料内での単語の並び順。この値は、資料全体でユニークである必要があります。
* `lexical_category_name`\*: 単語の品詞名。「語彙範疇」タクソノミータームとして登録されます（存在しない場合は新規作成）。日本語翻訳名を入力することも可能です。
* `meaning`\*: 単語の語義を表す数値。同一語に複数の語義がある場合は、この数値で区別します。
* `explanation`: 語義の説明（語釈）。
* `verbal_form_name`, `gender_name`, `number_name`, `person_name`, `voice_name`, `aspect_name`, `mood_name`, `grammatical_case_name`: 各種文法カテゴリー名。「文法カテゴリー」タクソノミータームとして登録されます（存在しない場合は新規作成）。

## **8\. 高度なトピック**

### **Cantaloupe IIIF画像サーバとの連携**

オプションの`wdb_cantaloupe_auth`サブモジュールは、WDBのアクセス制御システムと、Cantaloupe IIIF画像サーバを連携させる機能を提供します。これにより、Drupalのログイン状態に基づいて、IIIF画像へのアクセスを制限することができます。

**仕組み:**

1. Cantaloupeに画像のリクエストが来ると、そのDelegateスクリプトが、このサブモジュールが提供する安全なAPIエンドポイント（`/wdb/api/cantaloupe_auth`）に`POST`リクエストを送信します。
2. スクリプトは、ユーザーのブラウザのCookieと、リクエストされた画像のIdentifierをDrupalのAPIに送ります。
3. Drupal APIは、受け取ったCookieを元にユーザーのセッションを確認し、そのユーザーが、画像が属するサブシステムの "View non-public WDB gallery pages" 権限を持っているかを検証します。
4. Drupalは、簡単なJSON形式（`{"authorized": true/false}`）で検証結果を返します。
5. Delegateスクリプトは、このレスポンスに基づいて、画像を配信するか、「アクセス拒否」エラーを返すかを決定します。

**セットアップ:**

1. `wdb_cantaloupe_auth`サブモジュールを有効化します。
2. CantaloupeサーバがDelegateスクリプトを使うように設定します。
3. 以下のRubyコードを、`delegate.rb`ファイルのテンプレートとして使用してください。定数`DRUPAL_AUTH_ENDPOINT`を、ご自身のサイトのURLに合わせて更新することを忘れないでください。

`delegate.rb` **スクリプトのサンプル:**

```
require 'net/http'
require 'uri'
require 'json'

# ... (Cantaloupe delegate script の定型句) ...

# Drupalサイトの認証APIエンドポイントの完全なURL
DRUPAL_AUTH_ENDPOINT = "https://your.host.name/wdb/api/cantaloupe_auth"

def pre_authorize(options = {})
  # info.json へのリクエストは、無条件で許可する
  return true if context['request_uri'].end_with?('info.json')

  # サーバー自身からのアクセス（派生画像の生成など）は許可する
  return true if context['client_ip'].start_with?('127.0.0.1')

  # クッキーをAPIが期待する形式の配列に変換
  cookies = context['cookies'].map { |k, v| "#{k}=#{v}" }

  # APIリクエスト用のペイロードを作成
  payload = {
    cookies: cookies,
    identifier: context['identifier']
  }.to_json

  begin
    uri = URI.parse(DRUPAL_AUTH_ENDPOINT)
    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == 'https')

    request = Net::HTTP::Post.new(uri.request_uri, 'Content-Type' => 'application/json')
    request.body = payload

    response = http.request(request)

    if response.is_a?(Net::HTTPSuccess)
      auth_result = JSON.parse(response.body)
      return auth_result['authorized']
    else
      # API呼び出しに失敗した場合は、安全のためアクセスを拒否
      return false
    end
  rescue => e
    # 何らかの例外が発生した場合も、安全のためアクセスを拒否
    # logger.error("Authorization中のエラー: #{e.message}") のようにログを残すことを推奨
    return false
  end
end
```

### **エクスポート用テンプレートの変数**

TEI/XMLおよびRDF/XMLのエクスポート用テンプレートは、Twigを使ってレンダリングされます。テンプレート内で利用可能な主要なデータオブジェクトは page_data です。以下にその構造と利用可能な変数についてのガイドを示します。

* `page_data`
  * `.source`: 現在の資料の `WdbSource` エンティティオブジェクト。
    * `{{ page_data.source.label() }}`: 資料の表示名。
    * `{{ page_data.source.source_identifier.value }}`: 資料のユニークなID。
    * `{{ page_data.source.description.value }}`: 資料の説明。
  * `.page`: 現在のページの `WdbAnnotationPage` エンティティオブジェクト。
    * `{{ page_data.page.label() }}`: ページの表示ラベル（例: "p. 3"）。
    * `{{ page_data.page.page_number.value }}`: ページ番号。
    * `{{ page_data.page.getCanvasUri() }}`: このページの完全なIIIF Canvas URIを取得するヘルパーメソッド。
  * `.word_units`: ページ上の全ての単語ユニットを、出現順にソートした配列。この配列をループ処理できます:
    ```
    {% for item in page_data.word_units %}
      ...
    {% endfor %}
    ```
    ループ内の各 item は、以下の情報を含みます:
    * `item.entity`: `WdbWordUnit` エンティティオブジェクト。
      * `{{ item.entity.realized_form.value }}`: 実現形。
      * `{{ item.entity.word_sequence.value }}`: 出現順。
      * 関連データを辿るには、エンティティ参照を利用します:
        * `{% set word_meaning = item.entity.word_meaning_ref.entity %}`
        * `{% set word = word_meaning.word_ref.entity %}`
        * `{{ word.basic_form.value }}`
        * `{{ word.lexical_category_ref.entity.label() }}`
    * `item.sign_interpretations`: この単語ユニットを構成する `WdbSignInterpretation` エンティティの配列。単語内の出現順でソートされています。
      * `{% for si in item.sign_interpretations %}`
      * `{{ si.phone.value }}`
      * `{% set sign_function = si.sign_function_ref.entity %}`
      * `{% set sign = sign_function.sign_ref.entity %}`
      * `{{ sign.sign_code.value }}`


## **9\. 開発者向け情報**

### **エンティティの関係性**

WDBモジュールは、豊富なカスタムコンテントエンティティ上に構築されています。主要な関係性は以下の通りです。

* **WdbSource** (資料) は、多数の **WdbAnnotationPage** を持ちます。
* **WdbWordUnit** (文脈中の単語) は、一つ以上の **WdbAnnotationPage** に出現します。
* **WdbWordUnit** は、単一の **WdbWordMeaning** に結びつきます。
* **WdbWordMeaning** は、単一の **WdbWord** (見出し語) に属します。
* **WdbWord** は、**Lexical Category** (タクソノミーターム) によって分類されます。
* **WdbWordUnit** は、一つ以上の **WdbSignInterpretation** から構成され、**WdbWordMap** エンティティを介して結びつきます。
* **WdbSignInterpretation** は、**WdbLabel** (アノテーションのポリゴン) と **WdbSignFunction** に結びつきます。
* **WdbSignFunction** は、単一の **WdbSign** (文字) に属します。

### 設計ノート: Sign Function の function_name が任意である理由

`WdbSignFunction.function_name` フィールドは、TSV 作成や初期投入時の負担軽減を優先し「必須ではない」設計としています。未入力（空文字）の場合、生成される `sign_function_code` は `<sign_code>_` （末尾アンダースコア）となります。これは **一時的に機能ラベルを未確定のまま識別子だけ確保したい** という人文学系ワークフローを想定しています。

重複防止は二段構えで保証されます:

1. `(langcode, sign_ref, function_name)` の複合一意性バリデーション（空文字も実際の値として扱い、同一 Sign + 言語で複数の空 function_name を禁止）。
2. 生成前に予測される `sign_function_code`（`<sign_code>_` など）での追加重複チェック。

これにより、function_name を後から追記しても識別子の一貫性が損なわれず、同時に空のままの重複行が静かに増殖することを防ぎます。将来的に要件が変わり必須化したい場合は、フィールドを required に変更するだけで、既存データの識別子（`sign_function_code`）はそのまま利用できます。

参考: `WdbWordMeaning` では `meaning_identifier` を必須とし `explanation` を任意とする対称ではない設計ですが、Sign Function 側は「まず最小限の符号機能エンティティを大量投入 → 後で機能名を意味的に整理」という運用を想定したためこの差異があります。

### **主要なサービス**

* `wdb_core.data_service` **(**WdbDataService**):** アノテーションパネルやシステムの他の部分のために、データを取得し、構造化するための中核的なサービス。
* `wdb_core.data_importer` **(**WdbDataImporterService**):** TSVファイルを解析し、バッチ処理でエンティティを作成・更新するロジックを担います。
* `wdb_core.template_generator` **(**WdbTemplateGeneratorService**):** TSVテンプレートを生成するロジックを担います。

### **モジュールの拡張**

このモジュールは、拡張性を考慮して設計されています。例えば、`hook_entity_insert()`のような標準的なDrupalのフックを使い、新しい`subsystem`タクソノミータームが作成された際の処理（デフォルト設定の作成など）を実装しています。

### **APIエンドポイント**

クライアント側の幾何ユーティリティを支援するため、読み取り専用の軽量APIを提供します。いずれも GET でアクセスし、JSON を返します。クエリ文字列で JSON を渡す場合は URL エンコードが必要です（以下の例はエンコード済み）。

- GET `/wdb/api/hull`
  - 目的: 任意の点集合に対する凹包（concave hull）を計算します。
  - クエリパラメータ:
    - `points`（必須）: `"X,Y"` 形式の文字列からなる JSON 配列。例: `["10,20","30,40","25,35"]`。
    - `concavity`（任意・整数）: 凹みの強さ（小さいほどきつい/0で凸包）。値が大きいほど外形がゆるくなります。デフォルト: `20`。
  - レスポンス: 2次元点の配列 `[[x1, y1], [x2, y2], ...]`（数値）。
  - エラー: `points` が不足・不正な場合は `400` と `{ "error": "..." }` を返します。
  - URL例:
    - 凹包（concavity=20）:
      `/wdb/api/hull?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D&concavity=20`
    - 凸包（concavity=0）:
      `/wdb/api/hull?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D&concavity=0`

- GET `/wdb/api/bbox`
  - 目的: 任意の点集合の軸平行バウンディングボックスを計算します。
  - クエリパラメータ:
    - `points`（必須）: `"X,Y"` 形式の文字列からなる JSON 配列。
  - レスポンス: `{ x, y, w, h }`（左上 `(x,y)`、幅 `w`、高さ `h`）。値は整数に丸められ、`w`/`h` は最低でも `1` となります。
  - エラー: `points` が不足・不正な場合は `400` と `{ "error": "..." }` を返します。
  - URL例:
    `/wdb/api/bbox?points=%5B%2210%2C20%22%2C%2230%2C40%22%2C%2225%2C35%22%2C%2215%2C22%22%5D`
