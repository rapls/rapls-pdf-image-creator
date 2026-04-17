# Rapls PDF Image Creator

WordPressで PDF をアップロードすると、自動的に1ページ目のサムネイル画像を生成するプラグインです。CMYK→sRGB変換、PDF/X形式にも対応しています。

📖 **詳しい解説記事**: [WordPressでPDFの表紙サムネイルを自動生成｜設定・活用・真っ黒問題の解決策](https://raplsworks.com/rapls-pdf-image-creator-guide/)

## Features

- PDFアップロード時に自動でサムネイル生成
- CMYK→sRGB自動変換で「サムネイルが真っ黒になる」問題を解決
- PDF/X形式対応
- PHP の Imagick 拡張(ImageMagick)を直接利用
- WordPress メディアライブラリに標準統合

## Installation

### WordPress.org から(推奨)

WordPress管理画面 → プラグイン → 新規追加 → 「Rapls PDF Image Creator」で検索

### GitHub から

Releases から最新版の ZIP をダウンロード → プラグイン → 新規追加 → プラグインのアップロード

## Documentation

- [設定・使い方ガイド](https://raplsworks.com/rapls-pdf-image-creator-guide/)
- [WordPress.org プラグインページ](https://wordpress.org/plugins/rapls-pdf-image-creator/)

## Requirements

- WordPress 6.0以上
- PHP 7.4以上
- PHP の Imagick 拡張(サーバー側にインストールされていること)

## Troubleshooting

サムネイルが真っ黒になる場合の対処法(CMYK画像の sRGB変換)は、[こちらの記事](https://raplsworks.com/rapls-pdf-image-creator-guide/) で詳しく解説しています。

## Contributing

バグ報告・機能要望は [Issues](https://github.com/rapls/rapls-pdf-image-creator/issues) までお願いします。Pull Request も歓迎です。

## Author

**Rapls(ラプルス)**  
フリーランスWeb開発者 / WordPress Polyglots PTE

- ブログ: [Rapls Works](https://raplsworks.com/)
- WordPress.org: [プロフィール](https://profiles.wordpress.org/rapls/)

## License

GPL v2 or later
