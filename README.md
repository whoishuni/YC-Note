# YC Note

YC Note 是由 [Saber](https://github.com/saber-notes/saber) fork 的 Flutter/Dart 手寫筆記 App，使用自有伺服器與裝置認證，不含 AI 功能。

![YC Note](assets/icon/resized/icon-128x128.png)

管理：[租屋系統 YC Note](https://yckuo.com/rent/admin/notes.php)（舊 `/yc_note/` 會轉入）

- 深度整合租屋系統的登入、導覽與管理流程，只使用租屋管理員帳號。
- 超級管理員管理授權，一般管理員須開放筆記權限；首次裝置核准、後續自動續期。
- 私有筆記儲存、歷史版本、檔案同步與衝突備份。
- 原生管理頁、共用資安與維護模式，私有資料庫與檔案儲存。

建置需 Flutter 3.47.7 / Dart 3.13.5；iOS 編譯與簽署另需 Xcode。Dart 內部 package 名稱保留 `saber`，筆記格式保留 `.sbn2/.sba`。

```sh
flutter pub get
flutter run --dart-define=YC_NOTE_SERVER=https://yckuo.com/yc_note
```

[部署與檔案清單](docs/DEPLOYMENT.md) · [功能路線與限制](docs/GOODNOTES-ROADMAP.md) · [進度](docs/STATUS.md) · [原始 README](README-upstream.md)

本專案保留 Saber 的 GPL-3.0 授權與原作者聲明。見 [LICENSE.md](LICENSE.md)。
