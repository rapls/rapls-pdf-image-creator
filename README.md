# Rapls PDF Image Creator

WordPress で PDF ファイルからサムネイル画像を自動生成するプラグインです。PDF アップロード時に自動でプレビュー画像を作成し、アイキャッチ画像や投稿内の画像として利用できます。

📖 **詳しい解説記事**: [Rapls PDF Image Creator｜WordPress PDFサムネイル生成プラグイン](https://raplsworks.com/rapls-pdf-image-creator-guide/)

## Features

- **PDF 自動サムネイル生成** — メディアライブラリへ PDF アップロード時に自動で画像化
- **抽出ページの指定** — 既定は 1 ページ目。設定画面で任意の 1 ページを指定でき、フィルタ `rapls_pdf_image_creator_thumbnail_page` で PDF ごとに上書きできます（1 回の生成につき 1 ページ）
- **画像フォーマット選択** — PNG / JPEG / WebP など複数フォーマットに対応
- **パフォーマンス最適化** — キャッシング機能で再生成を回避
- **ショートコード / テンプレート関数** — `[rapls_pdf_thumbnail]` などで投稿やテーマから呼び出し
- **一括生成** — 既存の PDF をまとめて画像化
- **多言語対応** — i18n 対応（日本語翻訳同梱）

## Installation

### WordPress.org から（推奨）

1. WordPress管理画面 → **プラグイン** → **新規追加**
2. 「Rapls PDF Image Creator」で検索 → **インストール**
3. 有効化

### GitHub から

1. [Releases](../../releases) から最新版の ZIP をダウンロード
2. WordPress管理画面 → **プラグイン** → **新規追加** → **プラグインのアップロード**
3. 有効化

## セットアップ

### 前提条件

- **WordPress 5.0 以上**
- **PHP 7.4 以上**
- **Imagick PHP 拡張** — 画像変換に使用します
- **Ghostscript** — ImageMagick が PDF を読むために内部で使用します。本プラグインが Ghostscript を直接起動することはありません

### クイックスタート

1. プラグイン有効化後、管理画面の **Rapls PDF Image Creator** メニューを開く
2. 「Status」タブで Imagick と PDF の対応状況を確認
3. 「Settings」タブで出力フォーマット（JPEG / PNG / WebP）と解像度を設定
4. メディアライブラリに PDF をアップロード → 自動生成

詳しくは [ガイド](https://raplsworks.com/rapls-pdf-image-creator-guide/) を参照。

## よくある質問 / トラブルシューティング

### Q: PDF をアップロードしても画像が生成されない

**A:** Imagick PHP 拡張が無いか、ImageMagick が PDF を読むために必要な Ghostscript が
サーバーに無い可能性があります。まず管理画面の「Status」タブで検出結果を確認してください。

- 下の「サーバー環境の確認」で切り分け
- 不足していればホスティング会社に導入を依頼

### Q: サムネイルが低品質

**A:** 管理画面で「解像度」設定を上げてください。

- 既定: 150 DPI
- 高品質: 200〜300 DPI（生成時間とファイルサイズも増えます）

### Q: PDF の特定ページをサムネイルにしたい

**A:** 「Settings」タブの **Page Number** で指定します（`0` が 1 ページ目）。
サイト全体の既定値なので、PDF ごとに変えたい場合はフィルタを使ってください。

```php
add_filter( 'rapls_pdf_image_creator_thumbnail_page', function ( $page, $pdf_id ) {
    return 2; // 3 ページ目
}, 10, 2 );
```

1 回の生成で書き出せるのは 1 ページです。

### Q: 既存の PDF ファイルのサムネイルを生成したい

**A:** 管理画面の「Bulk Generate」タブから一括生成します。
処理は分割して順次実行されるので、完了するまでタブを開いたままにしてください。

### Q: WebP に出力したい

**A:** 管理画面で「出力フォーマット」を WebP に設定。

ただし、一部のブラウザ・サーバー環境では WebP がサポートされていない場合があります。

---

## サーバー環境の確認

### Imagick PHP 拡張があるか

```bash
php -m | grep -i imagick
```

### Imagick が PDF を扱えるか

```bash
php -r 'var_dump( Imagick::queryFormats( "PDF" ) );'
```

空配列が返る場合は、ImageMagick に PDF デリゲートが無いか、Ghostscript が未導入です。

```bash
gs --version
```

いずれも確認できない場合は、サーバー提供者に導入を依頼してください。

---

## フィルターとアクション（開発者向け）

### 生成オプション

`Generator::generate()` は変換オプションを**すべて PDF 単位のフィルター経由**で組み立てます。第 2 引数に添付 ID が渡るので、PDF ごとに違う値を返せます。

| フィルター | 既定値 | 型 |
|---|---|---|
| `rapls_pdf_image_creator_thumbnail_page` | 設定画面の値（**0 = 1 ページ目**） | int |
| `rapls_pdf_image_creator_thumbnail_max_width` | 1024 | int |
| `rapls_pdf_image_creator_thumbnail_max_height` | 1024 | int |
| `rapls_pdf_image_creator_thumbnail_resolution` | 150 | int |
| `rapls_pdf_image_creator_thumbnail_quality` | 90 | int |
| `rapls_pdf_image_creator_thumbnail_format` | `jpeg` | string |
| `rapls_pdf_image_creator_thumbnail_bgcolor` | `white` | string |

```php
// report-*.pdf だけ 3 ページ目を使う（ページ番号は 0 起点）
add_filter('rapls_pdf_image_creator_thumbnail_page', function ($page, $pdfId) {
    return 0 === strpos(basename(get_attached_file($pdfId)), 'report-') ? 2 : $page;
}, 10, 2);
```

### 描画後・リサイズ前のフック（1.4.0〜）

```php
add_filter('rapls_pdf_image_creator_before_resize', function (Imagick $image, array $options) {
    // $options['attachment_id'] — 元 PDF の添付 ID
    // $options['source_path']   — 元 PDF の絶対パス
    return $image;   // 別インスタンスを返してもよい
}, 10, 2);
```

透過を背景色に合成し、アルファチャンネルを落とした**直後**、リサイズの**直前**に走ります。この位置である理由は 2 つあります。アルファが残っていると、内容と余白を見分ける処理が意味をなしません。そして縮小後に測ると、境界がぼやけた測定になります。

戻り値が `Imagick` でなければ無視され、画像はそのまま処理を続けます。**リスナーが 1 つも無いときに挙動が変わってはいけない**ので、返り値は必ず型検査してください、ではなく、プラグイン側が型検査します。

### 生成の前後

| フック | 引数 |
|---|---|
| `rapls_pdf_image_creator_before_generate` | `$pdfId`, `$pdfPath` |
| `rapls_pdf_image_creator_after_generate` | `$thumbnailId`, `$pdfId`, `$result` |
| `rapls_pdf_image_creator_generation_failed` | `$message`, `$pdfId`, `$code`, `$result` |

`generation_failed` は 1.4.0 で変わりました。**すべての失敗経路で発火し**（それ以前は変換エラーのときだけ）、第 3 引数に機械可読なコードが付きます。既存の 2 引数のリスナーはそのまま動きます。

コードの一覧は `includes/FailureCode.php` にあります。`FailureCode::isPermanent()` は、同じ設定でやり直しても同じ結果になるものを `true` で返します。ディスクが一杯（`write_failed`）は後で解消しますが、2 億ピクセルのページ（`resource_limit`）は明日も 2 億ピクセルです。

### その他

| フィルター | 用途 |
|---|---|
| `rapls_pdf_image_creator_engines` | 変換エンジンの差し替え |
| `rapls_pdf_image_creator_icc_paths` | ICC プロファイルの探索先 |
| `rapls_pdf_image_creator_color_conversion` | 色変換の方式を固定する |
| `rapls_pdf_image_creator_policy_paths` | policy.xml の探索先 |
| `rapls_pdf_image_creator_flatten_background` | 合成する背景色 |
| `rapls_pdf_image_creator_hide_thumbnails_in_library` | 生成画像をライブラリで隠すか |
| `rapls_pdf_image_creator_thumbnail_image_attributes` | 出力する `img` の属性 |

## Documentation

- [ガイド](https://raplsworks.com/rapls-pdf-image-creator-guide/)
- [WordPress.org プラグインページ](https://wordpress.org/plugins/rapls-pdf-image-creator/)

## Development

### Requirements

- WordPress 5.0 以上
- PHP 7.4 以上
- Imagick PHP 拡張（PDF を読むには Ghostscript も必要）

### Contributing

バグ報告・機能要望は [Issues](../../issues) までお願いします。Pull Request も歓迎です。

## Changelog

詳細は [readme.txt](./readme.txt) をご覧ください。

## Author

**Rapls（ラプルス）**  
フリーランス Web 開発者 / WordPress Polyglots PTE（日本語翻訳責任者）

- 🌐 [Rapls Works](https://raplsworks.com/)
- 📋 [WordPress.org プロフィール](https://profiles.wordpress.org/rapls/)
- 🐙 [GitHub](https://github.com/raplsworks)

## License

GPL v2 or later
