# bottlers-releases

Whisky Roundup（https://whisky-roundup.com/）の「ボトラーズ最新リリース」ページ用データ。
世界のインディペンデントボトラーの公式リリースを、輸入元・販売店に偏らず掲載する。

- `REQUIREMENTS.md`：要件定義
- `UPDATE_GUIDE.md`：週次更新の手順
- `data/releases.json`：対象ボトラーとリリース一覧（毎週自動更新）
- `data/names_ja.json`：カタカナ表記の対応表（武川蒸留酒販売の表記が基準）
- `scripts/validate.py`：データの検証。エラーがあるときはpushしない
- `wordpress/wr-bottlers.php`：WordPress用ショートコード `[bottlers_list]`
