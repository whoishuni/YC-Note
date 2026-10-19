# YC Note 部署

正式網站已部署到 https://yckuo.com/yc_note/，資料表已建立，不需要再次手動匯入。

## 主機上的檔案

| 位置 | 內容 |
| --- | --- |
| `/home/u805049489/domains/yckuo.com/public_html/yc_note/` | 對外網站入口與靜態資產 |
| `/home/u805049489/yc-note/releases/<版本>/src/` | PHP 服務、共用資安介接 |
| `/home/u805049489/yc-note/config.local.php` | 正式憑證，權限 0600 |
| `/home/u805049489/yc-note/releases/<版本>/config.local.php` | 該版本的私有設定副本，權限 0600 |
| `/home/u805049489/yc-note/blobs/` | 每位使用者的筆記內容與歷史版本，位於網站根目錄外 |
| `/home/u805049489/yc-note/active-release` | 目前使用的版本路徑 |

對外目錄只有以下 7 個檔案：

```text
index.php
api.php
boot.php
style.css
icon.svg
.htaccess
.local-root.php
```

`.local-root.php` 只指向私有程式目錄，直接 HTTP 存取應被拒絕。真正的資料庫憑證、筆記檔、原始服務及備份不能放在 public_html。

## 手動重部署時需要哪些檔案

執行 `python3 scripts/yc_note/build_server_bundle.py` 會產生 `dist/YC-Note-server-20261019.zip`。其中：

- `public_html/yc_note/`：上傳到對外 `/yc_note/`。
- `private/yc-note-server/`：上傳到 public_html 外的私有目錄。
- `private/yc-note-server/config.example.php`：另存為私有 `config.local.php`，填入主機上的設定。部署包不包含正式密碼。
- 將 `.local-root.example.php` 另存為 `.local-root.php`，指向該私有目錄的絕對路徑。
- 在主機 CLI 執行 `php <私有目錄>/bin/migrate.php`。此命令只對 YC Note 資料庫建表。

目前正式環境已完成上述設定。更新現有站台時，保留既有私有設定、blobs 與 `.local-root.php`，不能用範例設定覆蓋。

## 資料庫與帳號來源

YC Note 的 9 張表：`owners`、`devices`、`tokens`、`files`、`revisions`、`audit`、`rate_limits`、`note_accounts`、`access_rules`。

租屋資料庫只新增 `yc_note_identity` 檢視，篩選 `super_admin/admin/manager`。原租屋 `users` 未改動。共用 IP 黑名單透過唯讀查詢沿用。

正式身分連線沿用現有租屋 SQL 憑證，但每條查詢連線設定 `SET SESSION TRANSACTION READ ONLY`。SQL 帳號本身仍保有原租屋權限；若日後要限制到資料庫帳號層級，可改用只具檢視與 IP 黑名單 SELECT 權限的專用帳號。筆記連線已使用獨立的 `u805049489_ycnote`。

## 更新流程

`deploy_hostinger.py` 透過 stdin 取得 Hostinger 原生工具回傳的短期上傳憑證；不從程式碼或指令參數讀密碼。每次建立私有版本、執行冪等建表、更新對外檔案，最後移除臨時安裝入口。正式密碼的本機私有副本位於 `~/.config/yc-note/deploy.json`，不在 repo 或部署包內。

備份必須同時涵蓋 YC Note SQL、私有 `blobs` 與設定。歷史版本會計入每位使用者的儲存配額；目前沒有自動清除歷史版本的工作。

## iPad 安裝

1. 安裝完整 Xcode 與 iOS platform support。
2. 在 Xcode 由使用者登入 Apple 帳號，選取自己的 Personal Team。
3. 以傳輸線連接 iPad，解鎖並信任此 Mac；若系統要求，開啟開發者模式。
4. 開啟 `ios/Runner.xcworkspace`，確認 bundle ID 為 `com.yckuo.ycnote`，選取 iPad 後編譯安裝。
5. 使用 YC Note 帳號登入，在正式網站核准 App 顯示的裝置代碼。
6. 以實際手寫、PDF 匯入、重啟與上下載驗證。

Flutter/Dart 不取代 Apple 的 iOS 編譯與簽署流程。免費 Personal Team 的 provisioning 依 Apple 規則有 7 天限制；不需要上架 App Store。

原生依賴目前使用 CocoaPods；專案已關閉 Swift Package Manager。首次建置先執行 `flutter pub get`，再於 `ios/` 執行 `pod install`。Rust 原生擴充另需 Rust 1.97.1 與 `aarch64-apple-ios` target。只有編譯檢查可使用 `flutter build ios --release --no-codesign`，此產物尚不能直接安裝。

Personal Team 的顯示名稱由 Apple 帳號的開發者資料提供，無法在專案中自行改字。若 Apple 帳號姓名與團隊名稱不一致，先透過 Apple Developer Support 確認並更正；不要修改本機快取來假裝已完成更名。

參考：[Apple 開發者帳號](https://developer.apple.com/help/account/basics/about-your-developer-account)、[Flutter iOS 部署](https://docs.flutter.dev/deployment/ios)。
