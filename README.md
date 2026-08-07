# Rapls PDF Image Creator

WordPress で PDF ファイルからサムネイル画像を自動生成するプラグインです。PDFアップロード時に自動的に プレビュー画像を作成し、ブログやギャラリーで表示できます。

📖 **詳しい解説記事**: [Rapls PDF Image Creator｜WordPress PDFサムネイル生成プラグイン](https://raplsworks.com/rapls-pdf-image-creator-guide/)

## Features

- **PDF 自動サムネイル生成** — メディアライブラリへ PDF アップロード時に自動で画像化
- **複数ページ対応** — 最初のページをデフォルト、複数ページ選択もサポート
- **画像フォーマット選択** — PNG / JPEG / WebP など複数フォーマットに対応
- **パフォーマンス最適化** — キャッシング機能で再生成を回避
- **Gutenberg ブロック対応** — PDF ギャラリーを簡単に配置
- **マルチサイト対応** — WordPress マルチサイト環境で使用可能
- **多言語対応** — i18n 準備完了
- **Free版と Pro版** — 基本機能は無料で利用可能

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

- **WordPress 5.9以上**
- **PHP 7.4以上**
- **GhostScript** または **ImageMagick** — サーバーに PDF 処理ライブラリがインストールされていること

### クイックスタート

1. プラグイン有効化後、管理画面の **Rapls PDF Image Creator** メニューで設定
2. 「サムネイル生成方式」を選択（GhostScript / ImageMagick）
3. 「出力フォーマット」を選択（PNG / JPEG など）
4. メディアライブラリに PDF をアップロード → 自動生成

詳しくは [ガイド](https://raplsworks.com/rapls-pdf-image-creator-guide/) を参照。

## よくある質問 / トラブルシューティング

### Q: PDF がアップロードされても画像が生成されない

**A:** サーバーに GhostScript または ImageMagick が インストールされていない可能性があります。

- ホスティング会社に問い合わせて確認
- 代替として ImageMagick での処理を試す

### Q: サムネイルが低品質

**A:** 管理画面で「解像度」設定を上げてください。

- デフォルト: 72 DPI
- 高品質: 150-200 DPI

### Q: PDF の特定ページをサムネイルにしたい

**A:** Pro版で複数ページ選択が可能です。

詳しくは [Pro版の機能一覧](https://raplsworks.com/rapls-pdf-image-creator-guide/) を参照。

### Q: 既存の PDF ファイルのサムネイルを生成したい

**A:** 管理画面で「一括再生成」ボタンを使用。

時間がかかる場合は WP-CLI で処理:

```bash
wp rapls-pdf generate-all
```

### Q: WebP に出力したい

**A:** 管理画面で「出力フォーマット」を WebP に設定。

ただし、一部のブラウザ・サーバー環境では WebP がサポートされていない場合があります。

---

## サーバー環境の確認

### GhostScript のインストール確認

```bash
gs --version
```

### ImageMagick のインストール確認

```bash
convert --version
```

どちらも出力がない場合は、サーバー提供者に問い合わせて インストールをリクエストしてください。

---

## Documentation

- [ガイド](https://raplsworks.com/rapls-pdf-image-creator-guide/)
- [WordPress.org プラグインページ](https://wordpress.org/plugins/rapls-pdf-image-creator/)

## Pro版

有料の Pro版では、以下のような高度な機能が利用できます。

- **複数ページ選択** — PDF の複数ページからサムネイルを選択
- **バッチ処理** — 大量の PDF を一括生成
- **カスタムラベル** — サムネイル画像に注釈を追加
- **S3 / クラウドストレージ対応** — Amazon S3 などへの直接出力
- **API** — 外部ツールから PDF 画像生成を自動化

ほか、複数の機能を搭載しています。

👉 [Pro版の詳細](https://raplsworks.com/rapls-pdf-image-creator-guide/)

---

## Development

### Requirements

- WordPress 5.9以上
- PHP 7.4以上
- GhostScript または ImageMagick

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
