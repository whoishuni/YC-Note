# YC Note

YC Note 深度整合租屋系統的帳號、權限與私人筆記儲存。iPad 目前改用 [Saber 官方 App Store 版](https://apps.apple.com/app/id1671523739)，以相容登入與同步協定連入自有後台，無需自行每週簽署。

本 repo 保留由 [Saber](https://github.com/saber-notes/saber) fork 的原 YC Note Flutter/Dart 客戶端、格式與既有筆記；其本機唯讀、原子同步及網站 PDF 功能屬於自訂客戶端，不能視為官方版已有相同功能。自訂程式不新增 AI。

![YC Note](assets/icon/resized/icon-128x128.png)

管理：[租屋系統 YC Note](https://yckuo.com/rent/admin/notes.php)（舊 `/yc_note/` 會轉入）

- 深度整合租屋系統的登入、導覽與管理流程，只使用租屋管理員帳號。
- 超級管理員管理授權，一般管理員須開放筆記權限；首次裝置核准、後續自動續期。
- 私有筆記儲存、歷史版本、檔案同步與衝突備份。
- 原生管理頁、共用資安與維護模式，私有資料庫與檔案儲存。

官方 Saber 的「其他 Nextcloud 伺服器」填入 `https://yckuo.com/rent/yc_note/saber`，由租屋網頁核准連線；雲端可唯讀或撤銷，官方 App 的本機編輯不受遠端限制。密文與加密設定以原樣儲存，網站可下載加密備份；PDF 目前在 Saber 匯出。

以下為保留的自訂客戶端建置方式，建置需 Flutter 3.47.7 / Dart 3.13.5；iOS 編譯與簽署另需 Xcode。Dart 內部 package 名稱保留 `saber`，筆記格式保留 `.sbn2/.sba`。

```sh
flutter pub get
flutter run --dart-define=YC_NOTE_SERVER=https://yckuo.com/yc_note
```

[部署與檔案清單](docs/DEPLOYMENT.md) · [功能路線與限制](docs/GOODNOTES-ROADMAP.md) · [進度](docs/STATUS.md) · [原始 README](README-upstream.md)

本專案保留 Saber 的 GPL-3.0 授權與原作者聲明。見 [LICENSE.md](LICENSE.md)。
